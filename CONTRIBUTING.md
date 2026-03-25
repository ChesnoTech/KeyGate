# Contributing to KeyGate

## Git Flow Branching Strategy

```
main (production)         ←── tagged releases (v2.1.0, v2.2.0)
  ↑ PR (require CI green)      Protected: no direct push
develop (integration)     ←── all features merge here first
  ↑ PR (require CI green)      Protected: no direct push
feature/my-feature        ←── one branch per feature/fix
hotfix/urgent-fix         ←── branch from main → merge to main + develop
release/v2.2.0            ←── branch from develop → merge to main + develop
```

### Branch Types

| Prefix | Base Branch | Merges Into | Purpose |
|--------|------------|-------------|---------|
| `feature/` | develop | develop | New features |
| `fix/` | develop | develop | Bug fixes |
| `hotfix/` | main | main + develop | Urgent production fixes |
| `release/` | develop | main + develop | Release preparation |
| `docs/` | develop | develop | Documentation only |
| `ci/` | develop | develop | CI/CD changes |

### Workflow

```bash
# 1. Create a feature branch
git checkout develop
git pull origin develop
git checkout -b feature/my-new-feature

# 2. Work on your branch
git add -A && git commit -m "Add new widget controller"

# 3. Push and create PR
git push origin feature/my-new-feature
gh pr create --base develop --title "Add widget feature"

# 4. CI must pass before merge (PHP Lint + Frontend Build & Test)

# 5. Release flow (when ready to ship):
git checkout develop
git checkout -b release/v2.2.0
# Update VERSION.php, then PR to main
gh pr create --base main --title "Release v2.2.0"
# After merge, tag: git tag v2.2.0 && git push origin v2.2.0
```

### Branch Naming Examples

```
feature/add-technician-export
feature/dpk-xml-parser
fix/login-session-timeout
fix/key-pool-alert-email
hotfix/activation-crash-win11
release/v2.2.0
```

## Development Setup

```bash
# Clone
git clone https://github.com/ChesnoTech/KeyGate.git
cd KeyGate
cp .env.example .env         # Edit with your passwords

# Start Docker stack
docker compose up -d

# Frontend dev server
cd FINAL_PRODUCTION_SYSTEM/frontend
npm install
npm run dev                   # http://localhost:5173

# Run tests
npm test                      # 14 tests across 3 suites
```

## Before Submitting a PR

- [ ] `cd frontend && npm test` — all 14 tests pass
- [ ] `npm run build` — no TypeScript errors
- [ ] `docker compose exec web php -l <file>` — PHP lint
- [ ] Docker stack starts cleanly
- [ ] New translations in `en.json` + `ru.json`
- [ ] New actions in `api-contracts.test.ts`
- [ ] No `echo json_encode()` — use `jsonResponse()`
- [ ] No `Get-WmiObject` — use `Get-CimInstance`
- [ ] Admin password remains `Admin2024!` in dev

## Code Style

| Pattern | Do This | Not This |
|---------|---------|----------|
| SQL params | `WHERE id = ?` with `execute([$id])` | `WHERE id = $id` |
| JSON response | `jsonResponse(['success' => true])` | `echo json_encode(...)` |
| File includes | `require_once __DIR__ . '/../config.php'` | `require_once '../config.php'` |
| Error messages | `'An error occurred'` + `error_log($e)` | `$e->getMessage()` to client |
| WMI (PS1) | `Get-CimInstance Win32_BaseBoard` | `Get-WmiObject Win32_BaseBoard` |
| Race conditions | `$pdo->beginTransaction()` | Check-then-insert without TX |

## Adding a New Feature

See the full guide in [CLAUDE.md](CLAUDE.md#contributing-guide).

## License

KeyGate is licensed under the Business Source License 1.1.
See [LICENSE](LICENSE) for details.
