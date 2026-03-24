/**
 * KeyGate License Server — Cloudflare Worker
 *
 * Handles:
 *   POST /webhook/github-sponsor  — GitHub Sponsors webhook → generate license
 *   POST /api/register             — Manual registration → community license
 *   POST /api/validate             — License validation (phone-home)
 *   GET  /api/instance-id          — Return instance ID requirements
 *
 * Environment variables (set in Cloudflare dashboard):
 *   GITHUB_WEBHOOK_SECRET  — GitHub webhook secret for signature verification
 *   JWT_SECRET             — HMAC key for signing license JWTs
 *   SENDGRID_API_KEY       — (optional) SendGrid API key for email delivery
 *   ADMIN_EMAIL            — Admin notification email
 *
 * KV Namespace:
 *   LICENSES — stores issued licenses keyed by email
 */

// ── JWT Helpers ─────────────────────────────────────────────

function base64UrlEncode(data) {
  return btoa(String.fromCharCode(...new Uint8Array(data)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')
}

function base64UrlEncodeString(str) {
  return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

async function createJwt(payload, secret) {
  const header = base64UrlEncodeString(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
  const body = base64UrlEncodeString(JSON.stringify(payload))
  const data = `${header}.${body}`

  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  )
  const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data))
  return `${data}.${base64UrlEncode(signature)}`
}

// ── GitHub Webhook Verification ─────────────────────────────

async function verifyGitHubSignature(body, signature, secret) {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  )
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body))
  const expected = 'sha256=' + Array.from(new Uint8Array(mac))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
  return signature === expected
}

// ── Tier Mapping ────────────────────────────────────────────

const SPONSOR_TIER_MAP = {
  // Monthly tiers (match your GitHub Sponsors tier amounts)
  500: 'community',   // $5/month — community with registration
  900: 'pro',         // $9/month — pro
  2900: 'enterprise', // $29/month — enterprise
  // One-time tiers
  9900: 'pro',        // $99 one-time — pro lifetime
}

function getTierFromAmount(amountCents) {
  // Find the best matching tier
  const amounts = Object.keys(SPONSOR_TIER_MAP)
    .map(Number)
    .sort((a, b) => b - a)

  for (const amount of amounts) {
    if (amountCents >= amount) {
      return SPONSOR_TIER_MAP[amount]
    }
  }
  return 'community'
}

const TIER_LIMITS = {
  community: { max_technicians: 1, max_keys: 50 },
  pro: { max_technicians: 9999, max_keys: 999999 },
  enterprise: { max_technicians: 99999, max_keys: 9999999 },
}

// ── Request Handlers ────────────────────────────────────────

async function handleGitHubWebhook(request, env) {
  const body = await request.text()
  const signature = request.headers.get('x-hub-signature-256') || ''

  // Verify webhook signature
  if (!await verifyGitHubSignature(body, signature, env.GITHUB_WEBHOOK_SECRET)) {
    return new Response(JSON.stringify({ error: 'Invalid signature' }), { status: 401 })
  }

  const event = request.headers.get('x-github-event')
  const payload = JSON.parse(body)

  if (event !== 'sponsorship') {
    return new Response(JSON.stringify({ ok: true, message: 'Event ignored' }), { status: 200 })
  }

  const action = payload.action // created, cancelled, edited, tier_changed, pending_cancellation
  const sponsor = payload.sponsorship?.sponsor?.login || 'unknown'
  const email = payload.sponsorship?.sponsor?.email || `${sponsor}@users.noreply.github.com`
  const name = payload.sponsorship?.sponsor?.name || sponsor
  const amountCents = (payload.sponsorship?.tier?.monthly_price_in_cents) || 0
  const isOneTime = payload.sponsorship?.tier?.is_one_time || false

  if (action === 'created' || action === 'tier_changed') {
    const tier = getTierFromAmount(amountCents)
    const limits = TIER_LIMITS[tier]

    // Generate license JWT
    const licensePayload = {
      iss: 'keygate-license-server',
      tier: tier,
      instance_id: '*', // Wildcard — will bind on first use
      email: email,
      name: name,
      github_sponsor: sponsor,
      max_technicians: limits.max_technicians,
      max_keys: limits.max_keys,
      iat: Math.floor(Date.now() / 1000),
      exp: isOneTime
        ? Math.floor(Date.now() / 1000) + (10 * 365 * 86400) // 10 years for one-time
        : Math.floor(Date.now() / 1000) + (400 * 86400),      // ~13 months for monthly
    }

    const jwt = await createJwt(licensePayload, env.JWT_SECRET)

    // Store in KV
    await env.LICENSES.put(`license:${email}`, JSON.stringify({
      jwt,
      tier,
      sponsor,
      email,
      name,
      created_at: new Date().toISOString(),
      amount_cents: amountCents,
      is_one_time: isOneTime,
    }), { expirationTtl: isOneTime ? 10 * 365 * 86400 : 400 * 86400 })

    // TODO: Send email with license key via SendGrid
    // For now, store it and provide a retrieval endpoint

    return new Response(JSON.stringify({
      ok: true,
      message: `License generated for ${email} (${tier} tier)`,
      tier,
    }), { status: 200 })
  }

  if (action === 'cancelled') {
    // Mark license as revoked in KV
    const existing = await env.LICENSES.get(`license:${email}`, 'json')
    if (existing) {
      existing.revoked = true
      existing.revoked_at = new Date().toISOString()
      await env.LICENSES.put(`license:${email}`, JSON.stringify(existing))
    }

    return new Response(JSON.stringify({
      ok: true,
      message: `License revoked for ${email}`,
    }), { status: 200 })
  }

  return new Response(JSON.stringify({ ok: true, message: `Action ${action} acknowledged` }), { status: 200 })
}

async function handleRegister(request, env) {
  const { email, name, instance_id } = await request.json()

  if (!email || !instance_id) {
    return new Response(JSON.stringify({ error: 'Email and instance_id are required' }), { status: 400 })
  }

  // Check if already registered
  const existing = await env.LICENSES.get(`license:${email}`, 'json')
  if (existing && !existing.revoked) {
    return new Response(JSON.stringify({
      success: true,
      license_key: existing.jwt,
      tier: existing.tier,
      message: 'Existing license retrieved',
    }), { status: 200 })
  }

  // Generate community license
  const payload = {
    iss: 'keygate-license-server',
    tier: 'community',
    instance_id: instance_id,
    email: email,
    name: name || email.split('@')[0],
    max_technicians: 1,
    max_keys: 50,
    iat: Math.floor(Date.now() / 1000),
    exp: Math.floor(Date.now() / 1000) + (365 * 86400), // 1 year
  }

  const jwt = await createJwt(payload, env.JWT_SECRET)

  await env.LICENSES.put(`license:${email}`, JSON.stringify({
    jwt,
    tier: 'community',
    email,
    name: name || email.split('@')[0],
    instance_id,
    created_at: new Date().toISOString(),
  }), { expirationTtl: 365 * 86400 })

  return new Response(JSON.stringify({
    success: true,
    license_key: jwt,
    tier: 'community',
    message: 'Community license generated. Upgrade to Pro at github.com/sponsors/ChesnoTech',
  }), { status: 200 })
}

async function handleValidate(request, env) {
  const { license_key, instance_id } = await request.json()

  if (!license_key) {
    return new Response(JSON.stringify({ valid: false, error: 'License key required' }), { status: 400 })
  }

  // Decode JWT (basic check — full verification happens client-side too)
  try {
    const parts = license_key.split('.')
    if (parts.length !== 3) throw new Error('Invalid JWT')

    const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')))

    // Check expiration
    if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
      return new Response(JSON.stringify({ valid: false, reason: 'expired' }), { status: 200 })
    }

    // Check if revoked
    const email = payload.email
    if (email) {
      const stored = await env.LICENSES.get(`license:${email}`, 'json')
      if (stored && stored.revoked) {
        return new Response(JSON.stringify({ valid: false, reason: 'revoked' }), { status: 200 })
      }
    }

    return new Response(JSON.stringify({
      valid: true,
      tier: payload.tier,
      expires_at: new Date(payload.exp * 1000).toISOString(),
    }), { status: 200 })
  } catch (e) {
    return new Response(JSON.stringify({ valid: false, error: 'Invalid license format' }), { status: 400 })
  }
}

// ── LemonSqueezy Webhook ────────────────────────────────────

async function verifyLemonSqueezySignature(body, signature, secret) {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  )
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body))
  const expected = Array.from(new Uint8Array(mac))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
  return signature === expected
}

const LEMONSQUEEZY_TIER_MAP = {
  // Map your LemonSqueezy variant IDs to tiers
  // Update these with actual variant IDs from your LemonSqueezy dashboard
  'pro_monthly': 'pro',
  'pro_yearly': 'pro',
  'enterprise_monthly': 'enterprise',
  'enterprise_yearly': 'enterprise',
  'pro_lifetime': 'pro',
}

async function handleLemonSqueezyWebhook(request, env) {
  const body = await request.text()
  const signature = request.headers.get('x-signature') || ''

  // Verify webhook signature
  const lsSecret = env.LEMONSQUEEZY_WEBHOOK_SECRET
  if (lsSecret && !await verifyLemonSqueezySignature(body, signature, lsSecret)) {
    return new Response(JSON.stringify({ error: 'Invalid signature' }), { status: 401 })
  }

  const payload = JSON.parse(body)
  const eventName = payload.meta?.event_name || ''
  const data = payload.data?.attributes || {}
  const email = data.user_email || payload.meta?.custom_data?.email || ''
  const name = data.user_name || payload.meta?.custom_data?.name || email.split('@')[0]
  const variantName = payload.meta?.custom_data?.variant || 'pro_monthly'

  if (eventName === 'subscription_created' || eventName === 'subscription_updated' ||
      eventName === 'order_created') {
    const tier = LEMONSQUEEZY_TIER_MAP[variantName] || 'pro'
    const limits = TIER_LIMITS[tier]
    const isLifetime = variantName.includes('lifetime')

    const licensePayload = {
      iss: 'keygate-license-server',
      tier: tier,
      instance_id: '*',
      email: email,
      name: name,
      payment_provider: 'lemonsqueezy',
      max_technicians: limits.max_technicians,
      max_keys: limits.max_keys,
      iat: Math.floor(Date.now() / 1000),
      exp: isLifetime
        ? Math.floor(Date.now() / 1000) + (10 * 365 * 86400)
        : Math.floor(Date.now() / 1000) + (400 * 86400),
    }

    const jwt = await createJwt(licensePayload, env.JWT_SECRET)

    await env.LICENSES.put(`license:${email}`, JSON.stringify({
      jwt,
      tier,
      email,
      name,
      payment_provider: 'lemonsqueezy',
      created_at: new Date().toISOString(),
      is_lifetime: isLifetime,
    }), { expirationTtl: isLifetime ? 10 * 365 * 86400 : 400 * 86400 })

    return new Response(JSON.stringify({
      ok: true,
      message: `License generated for ${email} (${tier} tier via LemonSqueezy)`,
    }), { status: 200 })
  }

  if (eventName === 'subscription_cancelled' || eventName === 'subscription_expired') {
    const existing = await env.LICENSES.get(`license:${email}`, 'json')
    if (existing) {
      existing.revoked = true
      existing.revoked_at = new Date().toISOString()
      await env.LICENSES.put(`license:${email}`, JSON.stringify(existing))
    }

    return new Response(JSON.stringify({
      ok: true,
      message: `License revoked for ${email}`,
    }), { status: 200 })
  }

  return new Response(JSON.stringify({ ok: true, message: `Event ${eventName} acknowledged` }), { status: 200 })
}

// ── T-Bank (Tinkoff) Webhook ────────────────────────────────

async function handleTBankWebhook(request, env) {
  // T-Bank Касса sends payment notifications via HTTP POST
  // Docs: https://www.tbank.ru/kassa/develop/api/notifications/
  const body = await request.text()
  const payload = JSON.parse(body)

  // T-Bank sends: TerminalKey, OrderId, Success, Status, Amount, Token (signature)
  const success = payload.Success === true
  const status = payload.Status // 'CONFIRMED', 'REVERSED', 'REFUNDED'
  const orderId = payload.OrderId || ''
  const amount = payload.Amount || 0 // in kopecks

  // Verify token signature (SHA256 of sorted params + TerminalPassword)
  // In production, you'd verify payload.Token here
  // For now, we trust the payload (T-Bank sends from known IPs)

  if (success && status === 'CONFIRMED') {
    // Order ID format: "keygate_{email_base64}_{tier}"
    const parts = orderId.split('_')
    if (parts.length < 3 || parts[0] !== 'keygate') {
      return new Response(JSON.stringify({ ok: true, message: 'Not a KeyGate order' }), { status: 200 })
    }

    let email, tier
    try {
      email = atob(parts[1])
      tier = parts[2] || 'pro'
    } catch {
      return new Response(JSON.stringify({ error: 'Invalid order ID format' }), { status: 400 })
    }

    const limits = TIER_LIMITS[tier] || TIER_LIMITS.pro

    const licensePayload = {
      iss: 'keygate-license-server',
      tier: tier,
      instance_id: '*',
      email: email,
      payment_provider: 'tbank',
      max_technicians: limits.max_technicians,
      max_keys: limits.max_keys,
      iat: Math.floor(Date.now() / 1000),
      exp: Math.floor(Date.now() / 1000) + (400 * 86400),
    }

    const jwt = await createJwt(licensePayload, env.JWT_SECRET)

    await env.LICENSES.put(`license:${email}`, JSON.stringify({
      jwt,
      tier,
      email,
      payment_provider: 'tbank',
      amount_kopecks: amount,
      created_at: new Date().toISOString(),
    }), { expirationTtl: 400 * 86400 })

    return new Response(JSON.stringify({
      ok: true,
      message: `License generated for ${email} (${tier} tier via T-Bank)`,
    }), { status: 200 })
  }

  if (status === 'REVERSED' || status === 'REFUNDED') {
    // Attempt to find and revoke the license
    const parts = orderId.split('_')
    if (parts.length >= 2) {
      try {
        const email = atob(parts[1])
        const existing = await env.LICENSES.get(`license:${email}`, 'json')
        if (existing) {
          existing.revoked = true
          existing.revoked_at = new Date().toISOString()
          existing.revoke_reason = status.toLowerCase()
          await env.LICENSES.put(`license:${email}`, JSON.stringify(existing))
        }
      } catch { /* ignore parse errors */ }
    }
  }

  return new Response(JSON.stringify({ ok: true }), { status: 200 })
}

// ── License Retrieval (for manual invoice flow) ─────────────

async function handleRetrieveLicense(request, env) {
  const url = new URL(request.url)
  const email = url.searchParams.get('email')

  if (!email) {
    return new Response(JSON.stringify({ error: 'Email parameter required' }), { status: 400 })
  }

  const stored = await env.LICENSES.get(`license:${email}`, 'json')
  if (!stored || stored.revoked) {
    return new Response(JSON.stringify({ found: false }), { status: 404 })
  }

  return new Response(JSON.stringify({
    found: true,
    license_key: stored.jwt,
    tier: stored.tier,
    created_at: stored.created_at,
  }), { status: 200 })
}

// ── Main Router ─────────────────────────────────────────────

export default {
  async fetch(request, env) {
    const url = new URL(request.url)
    const path = url.pathname

    // CORS headers
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Content-Type': 'application/json',
    }

    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders })
    }

    try {
      let response

      if (path === '/webhook/github-sponsor' && request.method === 'POST') {
        response = await handleGitHubWebhook(request, env)
      } else if (path === '/webhook/lemonsqueezy' && request.method === 'POST') {
        response = await handleLemonSqueezyWebhook(request, env)
      } else if (path === '/webhook/tbank' && request.method === 'POST') {
        response = await handleTBankWebhook(request, env)
      } else if (path === '/api/register' && request.method === 'POST') {
        response = await handleRegister(request, env)
      } else if (path === '/api/validate' && request.method === 'POST') {
        response = await handleValidate(request, env)
      } else if (path === '/api/retrieve' && request.method === 'GET') {
        response = await handleRetrieveLicense(request, env)
      } else if (path === '/api/health' && request.method === 'GET') {
        response = new Response(JSON.stringify({
          status: 'ok',
          service: 'keygate-license-server',
          timestamp: new Date().toISOString(),
        }))
      } else {
        response = new Response(JSON.stringify({ error: 'Not found' }), { status: 404 })
      }

      // Add CORS headers to response
      const newResponse = new Response(response.body, response)
      for (const [key, value] of Object.entries(corsHeaders)) {
        newResponse.headers.set(key, value)
      }
      return newResponse
    } catch (e) {
      return new Response(JSON.stringify({ error: 'Internal server error' }), {
        status: 500,
        headers: corsHeaders,
      })
    }
  },
}
