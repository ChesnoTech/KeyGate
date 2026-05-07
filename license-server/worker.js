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

// ── PEM PKCS#8 → CryptoKey (cached for the lifetime of an isolate) ──
let cachedSigningKey = null
async function importLicensePrivateKey(pemPkcs8) {
  if (cachedSigningKey) return cachedSigningKey
  // Strip header/footer + whitespace; decode base64.
  const b64 = pemPkcs8
    .replace(/-----BEGIN PRIVATE KEY-----/, '')
    .replace(/-----END PRIVATE KEY-----/, '')
    .replace(/\s+/g, '')
  const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0))
  cachedSigningKey = await crypto.subtle.importKey(
    'pkcs8',
    bin.buffer,
    { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
    false,
    ['sign']
  )
  return cachedSigningKey
}

// ── createJwt (RS256, P0 hardening) ──
// Switched from HS256 → RS256 in v2.3.0. Private key lives ONLY in the
// Worker secret store (env.LICENSE_PRIVATE_KEY, PEM PKCS#8). KeyGate PHP
// instances verify with the matching public key embedded in source.
async function createJwt(payload, env) {
  const header = base64UrlEncodeString(JSON.stringify({ alg: 'RS256', typ: 'JWT' }))
  const body = base64UrlEncodeString(JSON.stringify(payload))
  const data = `${header}.${body}`

  const key = await importLicensePrivateKey(env.LICENSE_PRIVATE_KEY)
  const signature = await crypto.subtle.sign(
    { name: 'RSASSA-PKCS1-v1_5' },
    key,
    new TextEncoder().encode(data)
  )
  return `${data}.${base64UrlEncode(signature)}`
}

// ── Legacy HS256 verify (used only by /api/migrate during transition) ──
async function verifyLegacyHs256(jwt, secret) {
  const parts = jwt.split('.')
  if (parts.length !== 3) return null
  const [headerB64, payloadB64, sigB64] = parts
  const header = JSON.parse(atob(headerB64.replace(/-/g, '+').replace(/_/g, '/')))
  if (header.alg !== 'HS256') return null
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['verify']
  )
  const sig = Uint8Array.from(
    atob(sigB64.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(sigB64.length / 4) * 4, '=')),
    c => c.charCodeAt(0)
  )
  const ok = await crypto.subtle.verify(
    'HMAC',
    key,
    sig,
    new TextEncoder().encode(`${headerB64}.${payloadB64}`)
  )
  if (!ok) return null
  return JSON.parse(atob(payloadB64.replace(/-/g, '+').replace(/_/g, '/')))
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

    // P0: GitHub Sponsors webhooks don't carry an instance_id. We store
    // the sponsorship with `pending_claim:true` and the customer must call
    // POST /api/claim to bind their KeyGate instance and receive a JWT.
    // No wildcard JWT issued at this stage.
    await env.LICENSES.put(`license:${email}`, JSON.stringify({
      tier,
      sponsor,
      email,
      name,
      pending_claim: true,
      created_at: new Date().toISOString(),
      amount_cents: amountCents,
      is_one_time: isOneTime,
      max_technicians: limits.max_technicians,
      max_keys: limits.max_keys,
    }), { expirationTtl: isOneTime ? 10 * 365 * 86400 : 400 * 86400 })

    // TODO: Send email with license key via SendGrid
    // For now, store it and provide a retrieval endpoint

    return new Response(JSON.stringify({
      ok: true,
      message: `Sponsorship recorded for ${email} (${tier} tier). Customer must call POST /api/claim to bind their installation.`,
      tier,
      pending_claim: true,
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

  const jwt = await createJwt(payload, env)

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

    // P0: LemonSqueezy may carry instance_id as custom_data; if absent, mark pending.
    const lsInstanceId = payload.meta?.custom_data?.instance_id || null

    const baseRecord = {
      tier,
      email,
      name,
      payment_provider: 'lemonsqueezy',
      created_at: new Date().toISOString(),
      is_lifetime: isLifetime,
      max_technicians: limits.max_technicians,
      max_keys: limits.max_keys,
    }

    let jwt = null
    if (lsInstanceId) {
      const licensePayload = {
        iss: 'keygate-license-server',
        tier,
        instance_id: lsInstanceId,
        email,
        name,
        payment_provider: 'lemonsqueezy',
        max_technicians: limits.max_technicians,
        max_keys: limits.max_keys,
        iat: Math.floor(Date.now() / 1000),
        exp: isLifetime
          ? Math.floor(Date.now() / 1000) + (10 * 365 * 86400)
          : Math.floor(Date.now() / 1000) + (400 * 86400),
      }
      jwt = await createJwt(licensePayload, env)
      baseRecord.jwt = jwt
      baseRecord.instance_id = lsInstanceId
    } else {
      baseRecord.pending_claim = true
    }

    await env.LICENSES.put(`license:${email}`, JSON.stringify(baseRecord),
      { expirationTtl: isLifetime ? 10 * 365 * 86400 : 400 * 86400 })

    return new Response(JSON.stringify({
      ok: true,
      message: jwt
        ? `License generated for ${email} (${tier} tier via LemonSqueezy)`
        : `Sponsorship recorded for ${email} — customer must call /api/claim with instance_id`,
      pending_claim: !jwt,
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
    // Order ID format: "keygate_{email_base64}_{tier}_{instance_id_base64}"
    // Old 3-part format also accepted but produces a pending_claim record.
    const parts = orderId.split('_')
    if (parts.length < 3 || parts[0] !== 'keygate') {
      return new Response(JSON.stringify({ ok: true, message: 'Not a KeyGate order' }), { status: 200 })
    }

    let email, tier, instanceId
    try {
      email = atob(parts[1])
      tier = parts[2] || 'pro'
      instanceId = parts.length >= 4 ? atob(parts[3]) : null
    } catch {
      return new Response(JSON.stringify({ error: 'Invalid order ID format' }), { status: 400 })
    }

    const limits = TIER_LIMITS[tier] || TIER_LIMITS.pro
    const baseRecord = {
      tier,
      email,
      payment_provider: 'tbank',
      amount_kopecks: amount,
      created_at: new Date().toISOString(),
      max_technicians: limits.max_technicians,
      max_keys: limits.max_keys,
    }

    let jwt = null
    if (instanceId) {
      const licensePayload = {
        iss: 'keygate-license-server',
        tier,
        instance_id: instanceId,
        email,
        payment_provider: 'tbank',
        max_technicians: limits.max_technicians,
        max_keys: limits.max_keys,
        iat: Math.floor(Date.now() / 1000),
        exp: Math.floor(Date.now() / 1000) + (400 * 86400),
      }
      jwt = await createJwt(licensePayload, env)
      baseRecord.jwt = jwt
      baseRecord.instance_id = instanceId
    } else {
      baseRecord.pending_claim = true
    }

    await env.LICENSES.put(`license:${email}`, JSON.stringify(baseRecord),
      { expirationTtl: 400 * 86400 })

    return new Response(JSON.stringify({
      ok: true,
      message: jwt
        ? `License generated for ${email} (${tier} tier via T-Bank)`
        : `Payment recorded for ${email} — customer must call /api/claim with instance_id`,
      pending_claim: !jwt,
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

// ── /api/claim — bind a pending_claim sponsorship to instance_id (P0.5) ──
//
// Body: { email, instance_id, sponsor_login? }
// Looks up KV record. Requires pending_claim:true. Once claimed, mints an
// RS256 JWT bound to the supplied instance_id. Subsequent claim attempts
// are rejected (one-shot binding) unless the founder manually clears
// pending_claim via the future P4 admin dashboard.
async function handleClaim(request, env) {
  const { email, instance_id, sponsor_login } = await request.json()
  if (!email || !instance_id) {
    return new Response(JSON.stringify({ error: 'email and instance_id required' }), { status: 400 })
  }

  const stored = await env.LICENSES.get(`license:${email}`, 'json')
  if (!stored) {
    return new Response(JSON.stringify({ error: 'No sponsorship found for this email' }), { status: 404 })
  }
  if (stored.revoked) {
    return new Response(JSON.stringify({ error: 'License revoked' }), { status: 403 })
  }
  if (!stored.pending_claim) {
    return new Response(JSON.stringify({ error: 'License already claimed; contact support to rebind' }), { status: 409 })
  }
  // Optional sanity check — sponsor_login matches stored sponsor, if provided.
  if (sponsor_login && stored.sponsor && stored.sponsor !== sponsor_login) {
    return new Response(JSON.stringify({ error: 'Sponsor login mismatch' }), { status: 403 })
  }

  const tier = stored.tier
  const limits = TIER_LIMITS[tier] || TIER_LIMITS.community
  const isLifetime = !!stored.is_one_time || !!stored.is_lifetime
  const exp = isLifetime
    ? Math.floor(Date.now() / 1000) + (10 * 365 * 86400)
    : Math.floor(Date.now() / 1000) + (400 * 86400)

  const licensePayload = {
    iss: 'keygate-license-server',
    tier,
    instance_id,
    email,
    name: stored.name || email.split('@')[0],
    payment_provider: stored.payment_provider || 'github_sponsors',
    max_technicians: limits.max_technicians,
    max_keys: limits.max_keys,
    iat: Math.floor(Date.now() / 1000),
    exp,
  }
  const jwt = await createJwt(licensePayload, env)

  // Update KV record — pending_claim cleared, jwt + instance_id stored.
  delete stored.pending_claim
  stored.jwt = jwt
  stored.instance_id = instance_id
  stored.claimed_at = new Date().toISOString()
  await env.LICENSES.put(`license:${email}`, JSON.stringify(stored),
    { expirationTtl: isLifetime ? 10 * 365 * 86400 : 400 * 86400 })

  return new Response(JSON.stringify({
    success: true,
    license_key: jwt,
    tier,
    expires_at: new Date(exp * 1000).toISOString(),
  }), { status: 200 })
}

// ── /api/migrate — re-issue legacy HS256 token as RS256 (P0 transition) ──
//
// Body: { license_key (legacy HS256), instance_id }
// Verifies the legacy token with LEGACY_HS256_SECRET, then mints a fresh
// RS256 JWT bound to the supplied instance_id. Does NOT change the email
// binding or tier. Available for 90 days post-deploy; remove the secret
// from Worker after that.
async function handleMigrate(request, env) {
  const { license_key, instance_id } = await request.json()
  if (!license_key || !instance_id) {
    return new Response(JSON.stringify({ error: 'license_key and instance_id required' }), { status: 400 })
  }
  if (!env.LEGACY_HS256_SECRET) {
    return new Response(JSON.stringify({ error: 'Legacy migration window has closed' }), { status: 410 })
  }

  const payload = await verifyLegacyHs256(license_key, env.LEGACY_HS256_SECRET)
  if (!payload) {
    return new Response(JSON.stringify({ error: 'Legacy JWT signature invalid' }), { status: 400 })
  }
  if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
    return new Response(JSON.stringify({ error: 'Legacy JWT expired; purchase a new license' }), { status: 403 })
  }

  // Mint RS256 with same tier/email/exp but bind to caller's instance_id.
  const newPayload = {
    ...payload,
    instance_id,
    iss: 'keygate-license-server',
    iat: Math.floor(Date.now() / 1000),
    _migrated_from: 'HS256',
  }
  delete newPayload._alg
  const jwt = await createJwt(newPayload, env)

  // Refresh KV if we have an email anchor.
  if (payload.email) {
    const stored = await env.LICENSES.get(`license:${payload.email}`, 'json') || {}
    stored.jwt = jwt
    stored.instance_id = instance_id
    stored.tier = payload.tier
    stored.email = payload.email
    delete stored.pending_claim
    stored.migrated_at = new Date().toISOString()
    await env.LICENSES.put(`license:${payload.email}`, JSON.stringify(stored),
      { expirationTtl: Math.max(86400, payload.exp - Math.floor(Date.now() / 1000)) })
  }

  return new Response(JSON.stringify({
    success: true,
    license_key: jwt,
    tier: payload.tier,
    expires_at: new Date(payload.exp * 1000).toISOString(),
  }), { status: 200 })
}

// ── /api/dev-issue — local dev license (gated by DEV_TOKEN) ────────────
//
// Body: { tier, email, instance_id, dev_token }
// Replaces the old "PHP signs locally with hardcoded secret" path. Now
// the founder hits the Worker directly; no signing capability ever lives
// on the customer's PHP host.
async function handleDevIssue(request, env) {
  const { tier, email, instance_id, dev_token } = await request.json()
  if (!env.DEV_TOKEN) {
    return new Response(JSON.stringify({ error: 'Dev issuance disabled on this Worker' }), { status: 403 })
  }
  if (dev_token !== env.DEV_TOKEN) {
    return new Response(JSON.stringify({ error: 'Invalid dev token' }), { status: 401 })
  }
  if (!tier || !instance_id) {
    return new Response(JSON.stringify({ error: 'tier and instance_id required' }), { status: 400 })
  }
  const limits = TIER_LIMITS[tier] || TIER_LIMITS.community
  const payload = {
    iss: 'keygate-license-server',
    tier,
    instance_id,
    email: email || 'dev@keygate.local',
    payment_provider: 'dev',
    max_technicians: limits.max_technicians,
    max_keys: limits.max_keys,
    iat: Math.floor(Date.now() / 1000),
    exp: Math.floor(Date.now() / 1000) + (90 * 86400),  // 90-day dev license
  }
  const jwt = await createJwt(payload, env)
  return new Response(JSON.stringify({
    success: true,
    license_key: jwt,
    tier,
    expires_at: new Date(payload.exp * 1000).toISOString(),
  }), { status: 200 })
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
      } else if (path === '/api/claim' && request.method === 'POST') {
        response = await handleClaim(request, env)
      } else if (path === '/api/migrate' && request.method === 'POST') {
        response = await handleMigrate(request, env)
      } else if (path === '/api/dev-issue' && request.method === 'POST') {
        response = await handleDevIssue(request, env)
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
