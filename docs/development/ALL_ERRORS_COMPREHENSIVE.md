> **⚠️ Historical Document** — References to `main_v2.PS1` are outdated.
> Current file: `activation/main_v3.PS1`. v2 was retired March 2026.

# OEM Activation System - Complete Error Report

**Testing Date:** 2026-01-25
**Environment:** Docker (PHP 8.3.30 + MariaDB 10.11.15)
**Total Errors Found:** 13 critical issues

---

## Error Summary Table

| # | Error | Severity | Status | Fix Complexity |
|---|-------|----------|--------|----------------|
| 1 | Wrong database credentials in config.php | 🔴 Critical | ✅ Fixed | Easy |
| 2 | Apache .htaccess PHP redirect ordering | 🟡 Medium | ⚠️ Workaround | Medium |
| 3 | API blocks non-PowerShell user agents | 🟡 Medium | ⚠️ Documented | Easy |
| 4 | Missing getClientIP() function | 🔴 Critical | ✅ Fixed | Easy |
| 5 | Missing validateTechnician() function | 🔴 Critical | ✅ Fixed | Easy |
| 6 | Config architecture split (config.php vs config-production.php) | 🔴 Critical | ✅ Fixed | Medium |
| 7 | PHP code output as plaintext | 🔴 Critical | ✅ Fixed | Easy |
| 8 | Password hash corruption | 🔴 Critical | ✅ Fixed | Easy |
| 9 | Login response missing session_token | 🟢 Not a bug | ✅ Understood | N/A |
| 10 | API expects JSON but description says form data | 🟡 Medium | ⚠️ Documented | Easy |
| 11 | Missing getActiveSession() function | 🔴 Critical | ✅ Fixed | Easy |
| 12 | oem_keys table missing roll_serial in INSERT | 🟡 Medium | ⚠️ Documented | Easy |
| 13 | Nested transaction in allocateKeyAtomically() | 🔴 Critical | 🔴 Needs Fix | Medium |

---

## Architecture Understanding

### Authentication Flow (Correct Design)
```
1. Client → login.php
   Input: {technician_id, password}
   Output: {success, technician_id, full_name, must_change_password}
   NO session_token (this is by design!)

2. Client → get-key.php
   Input: {technician_id, order_number}
   Process: Creates session + allocates key
   Output: {success, session_token, product_key, oem_identifier, ...}

3. Client → report-result.php
   Input: {session_token, result, details}
   Process: Records activation result
   Output: {success, message}
```

**Key Insight:** Session tokens are created during key allocation, NOT during login. This is a stateless-ish design where login just validates credentials.

---

## Detailed Errors

### Error #1: Database Credentials ✅ FIXED
**File:** config.php
**Problem:** Hard-coded localhost instead of Docker service name
**Fix:** Use config-production.php which reads from environment variables

### Error #2: .htaccess URL Rewriting ⚠️ WORKAROUND
**File:** .htaccess lines 24-29
**Problem:** .php files are redirected to extensionless URLs BEFORE API exception rule
**Workaround:** Use `/api/login` instead of `/api/login.php`
**Proper Fix:** Reorder rules - put API exception before general redirect

### Error #3: User Agent Filtering ⚠️ DOCUMENTED
**Files:** All API files (login.php, get-key.php, report-result.php)
**Problem:** Blocks testing with curl/Postman
**Workaround:** Add `-H "User-Agent: PowerShell/7.0"`
**Recommendation:** Make configurable for dev/test environments

### Error #4-6: Missing Functions ✅ FIXED
**Problem:** config.php missing critical functions that exist in config-production.php
**Missing Functions:**
- `getClientIP()` - Get client IP with proxy support
- `validateTechnician()` - Validate credentials
- `getActiveSession()` - Check for existing sessions
- `allocateKeyAtomically()` - Atomic key allocation
- `cleanupExpiredSessions()` - Session cleanup

**Fix:** Use config-production.php as the main config file

### Error #7: PHP Code as Plaintext ✅ FIXED
**Problem:** When appending code to config.php, syntax errors caused code to output as text
**Fix:** Use complete, validated config-production.php

### Error #8: Password Hash Corruption ✅ FIXED
**Problem:** Shell escaping stripped $ characters from bcrypt hashes
**Example:** `$2y$12$hash...` became `y2/hash...`
**Fix:** Generate hash in PHP, store in variable, use variable in SQL

### Error #9: "Missing" session_token ✅ UNDERSTOOD
**Status:** NOT A BUG - This is the intended design
**Explanation:** Login validates credentials only. Session token comes from get-key.php
**Documentation Updated:** Architecture flow diagram added above

### Error #10: JSON vs Form Data ⚠️ DOCUMENTED
**Problem:** Code comments/documentation suggest form data, but API expects JSON
**Impact:** Can confuse developers
**Fix Needed:** Update comments and documentation

### Error #11: Missing getActiveSession() ✅ FIXED
**Problem:** get-key.php calls this function which doesn't exist in basic config.php
**Fix:** Included in config-production.php

### Error #12: Missing roll_serial Field ⚠️ DOCUMENTED
**Problem:** Test data INSERT missing required field
**Schema:** `roll_serial` is NOT NULL without default value
**Fix for Testing:**
```sql
INSERT INTO oem_keys (product_key, oem_identifier, roll_serial, barcode, key_status)
VALUES ('KEY', 'OEM-001', 'ROLL001', 'BAR001', 'unused');
```

### Error #13: Nested Transaction 🔴 CRITICAL - NEEDS FIX
**File:** config-production.php line 219 + get-key.php line 38
**Problem:**
```php
// get-key.php line 38
$pdo->beginTransaction();

// Later calls allocateKeyAtomically() which does:
function allocateKeyAtomically($pdo, ...) {
    $pdo->beginTransaction();  // ❌ ERROR: Transaction already active!
```

**Error Message:** "There is already an active transaction"

**Solutions:**
1. **Option A:** Remove transaction from allocateKeyAtomically, let caller manage it
2. **Option B:** Check if transaction is active before starting:
   ```php
   if (!$pdo->inTransaction()) {
       $pdo->beginTransaction();
   }
   ```
3. **Option C:** Use separate connection for nested operations

**Recommended:** Option A - The function should be called within an existing transaction

---

## Files That Need Updates

### FINAL_PRODUCTION_SYSTEM Files
1. **config-production.php** (line 219)
   - Fix: Remove `beginTransaction()` from `allocateKeyAtomically()`
   - Or: Add transaction check

2. **.htaccess** (lines 24-29)
   - Reorder: Put API exception before .php redirect

3. **api/*.php** (line 4 in each)
   - Make user agent check configurable

4. **Documentation/Comments**
   - Update to reflect JSON input requirement
   - Add architecture flow diagram

### Docker Environment Files
1. **Dockerfile.php**
   - Add ENV variables for database config
   - Set DB_HOST=db, DB_NAME=oem_activation, etc.

2. **docker-compose.yml**
   - Pass env vars to web container
   - Or use .env file

---

## Current Testing Status

### ✅ Working Components
- Database connection (with config-production.php)
- Docker containers (PHP 8.3.30 + MariaDB 10.11.15)
- Database schema (10 tables, correct ENUM values, foreign keys)
- API login endpoint (validates credentials correctly)
- Password hashing/verification
- Account lockout mechanism

### 🔴 Broken Components
- get-key.php (nested transaction error)
- Full authentication flow (blocked by #13)

### ⏳ Not Yet Tested
- report-result.php with SMTP type cast fix
- CSV import with unicode fix
- Admin panel functionality
- Client PowerShell scripts end-to-end
- Concurrent activation handling

---

## Next Steps to Fix

### Immediate (Required for basic functionality)
1. Fix nested transaction in allocateKeyAtomically()
2. Test get-key.php endpoint
3. Test report-result.php endpoint

### Important (For production readiness)
4. Reorder .htaccess rules
5. Make user agent check configurable
6. Update Dockerfile with ENV variables
7. Update documentation/comments

### Nice to Have (For better developer experience)
8. Add API documentation (OpenAPI/Swagger)
9. Create automated test suite
10. Add /api/health endpoint

---

## Environment Variables Needed

For config-production.php to work in Docker:

```yaml
# In docker-compose.yml web service:
environment:
  - DB_HOST=db
  - DB_NAME=oem_activation
  - DB_USER=oem_user
  - DB_PASS=oem_pass_456
  - DB_PORT=3306
  - SESSION_TIMEOUT=30
  - MAX_FAILED_LOGINS=5
  - LOCKOUT_DURATION=15
```

---

## Key Files Reference

```
FINAL_PRODUCTION_SYSTEM/
├── config.php                    ❌ Incomplete (missing functions)
├── config-production.php         ✅ Complete (use this!)
├── .htaccess                     ⚠️ Needs reordering
├── api/
│   ├── login.php                 ✅ Works (design correct)
│   ├── get-key.php               🔴 Broken (nested transaction)
│   └── report-result.php         ⏳ Not tested
├── activation/
│   └── main_v2.PS1               ⏳ Not tested end-to-end
└── database/
    └── install.sql               ✅ Schema correct
```

---

## Testing Commands

```bash
# Test login (works)
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -H "User-Agent: PowerShell/7.0" \
  -d '{"technician_id":"TEST001","password":"test123"}'

# Test get-key (currently broken - nested transaction)
curl -X POST http://localhost:8080/api/get-key \
  -H "Content-Type: application/json" \
  -H "User-Agent: PowerShell/7.0" \
  -d '{"technician_id":"TEST001","order_number":"ORD01"}'

# Add test keys
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e \
  "INSERT INTO oem_keys (product_key, oem_identifier, roll_serial, key_status) \
   VALUES ('XXXXX-XXXXX-XXXXX-XXXXX-XXXXX', 'TEST-001', 'ROLL001', 'unused');"
```

---

## Conclusion

**13 errors found**, ranging from critical (preventing functionality) to medium (usability issues).

**5 errors fixed** in this session:
- Database credentials
- Missing functions (by using config-production.php)
- Password hash corruption
- Architecture misunderstanding clarified
- PHP code output issue

**1 critical error remaining**:
- Nested transaction in get-key.php (#13) - **BLOCKS FULL TESTING**

**7 medium/low priority issues documented**:
- .htaccess ordering, user agent filtering, etc.

**Recommendation:** Fix Error #13 first, then proceed with full end-to-end testing of the activation flow.
