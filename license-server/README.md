# KeyGate License Server

Cloudflare Worker that handles license generation and validation for KeyGate.

## Setup

1. Install Wrangler CLI: `npm install -g wrangler`
2. Login: `wrangler login`
3. Create KV namespace: `wrangler kv:namespace create LICENSES`
4. Update `wrangler.toml` with the KV namespace ID
5. Set secrets:
   ```bash
   wrangler secret put GITHUB_WEBHOOK_SECRET
   wrangler secret put JWT_SECRET
   ```
6. Deploy: `wrangler deploy`

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/webhook/github-sponsor` | GitHub Sponsors webhook receiver |
| POST | `/api/register` | Register for community license |
| POST | `/api/validate` | Validate a license key |
| GET | `/api/health` | Health check |

## GitHub Sponsors Setup

1. Go to your GitHub Sponsors settings
2. Add webhook URL: `https://keygate-license-server.YOUR-SUBDOMAIN.workers.dev/webhook/github-sponsor`
3. Set the webhook secret (same as `GITHUB_WEBHOOK_SECRET`)
4. Select event: `Sponsorships`

## License Tiers

| Sponsor Amount | Tier | Limits |
|---|---|---|
| Free (register) | Community | 1 tech, 50 keys |
| $9/month | Pro | Unlimited |
| $29/month | Enterprise | Unlimited + SSO/multi-site |
| $99 one-time | Pro (lifetime) | Unlimited, 10yr expiry |
