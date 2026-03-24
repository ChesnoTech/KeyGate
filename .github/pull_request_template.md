## Summary
<!-- Brief description of what this PR does -->

## Branch Type
- [ ] `feature/` — New feature (→ develop)
- [ ] `fix/` — Bug fix (→ develop)
- [ ] `hotfix/` — Urgent production fix (→ main + develop)
- [ ] `release/` — Release prep (→ main + develop)
- [ ] `docs/` — Documentation only
- [ ] `ci/` — CI/CD changes

## Changes
-
-

## Components Affected
- [ ] Activation Client (PS1 / CMD)
- [ ] Admin Panel (React frontend)
- [ ] API Backend (PHP controllers)
- [ ] Database / Migrations
- [ ] License Server (Cloudflare Worker)
- [ ] Docker / Deployment
- [ ] CI / GitHub Actions

## Testing Checklist
- [ ] `cd frontend && npm test` — 14 tests pass
- [ ] `npm run build` — no TypeScript errors
- [ ] PHP lint: `docker compose exec web php -l <file>`
- [ ] Docker stack starts cleanly (`docker compose up -d`)
- [ ] New i18n keys added to `en.json` + `ru.json`
- [ ] New admin actions added to `api-contracts.test.ts`
- [ ] Tested in browser / on workstation

## Screenshots
<!-- If UI changes, paste before/after screenshots -->

## Related Issues
<!-- Closes #123, Fixes #456 -->
