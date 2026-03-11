# Contributing

## Development Setup

```bash
# Clone and start
git clone https://github.com/ChesnoTech/OEM_Activation_System.git
cd OEM_Activation_System
cp .env.example .env         # Edit with your passwords
docker compose up -d          # Start backend stack

# Frontend dev server
cd FINAL_PRODUCTION_SYSTEM/frontend
npm install
npm run dev                   # http://localhost:5173
```

## Branch Strategy

- `main` — production-ready, CI must pass
- `develop` — active development, merge to main when stable
- Feature branches — branch from `develop`, PR back to `develop`

## Before Submitting a PR

- [ ] `cd FINAL_PRODUCTION_SYSTEM/frontend && npm test` — all tests pass
- [ ] `php -l your-file.php` — no syntax errors on changed PHP files
- [ ] Translations added to both `i18n/en.json` and `i18n/ru.json`
- [ ] New routes wrapped in `<RequirePermission>`
- [ ] Use `jsonResponse()` not `echo json_encode()`
- [ ] Use prepared statements for all SQL queries

## Code Style

| Pattern | Do This | Not This |
|---------|---------|----------|
| SQL params | `WHERE id = ?` with `execute([$id])` | `WHERE id = $id` |
| JSON response | `jsonResponse(['success' => true])` | `echo json_encode(...)` |
| File includes | `require_once __DIR__ . '/../config.php'` | `require_once '../config.php'` |
| Error messages | `'An error occurred'` + `error_log($e)` | `$e->getMessage()` to client |

## Adding a New Feature

See the full guide in [CLAUDE.md](CLAUDE.md#contributing-guide).
