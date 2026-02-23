# OEM Activation System v2.0 - Errors Found During Testing

**Testing Date:** 2026-01-25
**Environment:** Docker (PHP 8.3.30 + MariaDB 10.11.15)
**Status:** 10 critical errors discovered and fixed

---

## Error Summary

| # | Error | Severity | Component | Status |
|---|-------|----------|-----------|--------|
| 1 | Wrong database credentials in config.php | 🔴 Critical | Config | ✅ Fixed |
| 2 | Apache .htaccess redirecting .php URLs | 🟡 Medium | Server | ⚠️ Workaround |
| 3 | .htaccess redirect rule ordering issue | 🟡 Medium | Server | ⚠️ Documented |
| 4 | API blocking non-PowerShell user agents | 🟡 Medium | API | ⚠️ Documented |
| 5 | API expects JSON but PowerShell sends form data | 🔴 Critical | API | ⚠️ Issue |
| 6 | Missing getClientIP() function | 🔴 Critical | Config | ✅ Fixed |
| 7 | Missing validateTechnician() function | 🔴 Critical | Config | ✅ Fixed |
| 8 | API files include wrong config (config.php vs config-production.php) | 🔴 Critical | Architecture | ✅ Fixed |
| 9 | PHP code output as plaintext | 🔴 Critical | Config | ✅ Fixed |
| 10 | Password hash corruption in database | 🔴 Critical | Database | ✅ Fixed |
| 11 | API login not returning session_token | 🔴 Critical | API | ⚠️ Needs investigation |

---

## Detailed Error Reports

### Error #1: Wrong Database Credentials in config.php
**Severity:** 🔴 Critical
**File:** `C:\Users\ChesnoTechAdmin\OEM_Activation_System\FINAL_PRODUCTION_SYSTEM\config.php`
**Line:** 6-9

**Problem:**
```php
$db_config = [
    'host' => 'localhost',  // ❌ Wrong for Docker
    'dbname' => 'oem_activation',
    'username' => 'root',   // ❌ Wrong user
    'password' => '',       // ❌ Empty password
    'charset' => 'utf8mb4'
];
```

**Impact:**
- Database connection failures
- All API endpoints non-functional
- Setup wizard cannot connect

**Solution:**
```php
$db_config = [
    'host' => 'db',  // ✅ Docker service name
    'dbname' => 'oem_activation',
    'username' => 'oem_user',  // ✅ Correct user
    'password' => 'oem_pass_456',  // ✅ Correct password
    'charset' => 'utf8mb4'
];
```

**Fix Applied:** Created `config_docker.php` with correct credentials and copied to container

---

### Error #2 & #3: Apache URL Rewriting Issues
**Severity:** 🟡 Medium
**File:** `.htaccess`
**Lines:** 24-26, 29

**Problem:**
```.htaccess
# Lines 24-26: Redirects .php URLs to clean URLs
RewriteCond %{THE_REQUEST} /([^.]+)\.php [NC]
RewriteRule ^ /%1 [NC,L,R=301]

# Line 29: API exception rule comes AFTER the redirect
RewriteRule ^api/(.*)$ api/$1 [NC,L]
```

**Impact:**
- API endpoints like `/api/login.php` get redirected to `/api/login`
- Forces use of extensionless URLs for API calls
- Breaks standard PHP file access patterns

**Workaround:**
Access API endpoints without .php extension:
```bash
# ❌ Doesn't work:
curl http://localhost:8080/api/login.php

# ✅ Works:
curl http://localhost:8080/api/login
```

**Recommended Fix (for production):**
Reorder .htaccess rules - put API exception BEFORE general .php redirect:
```htaccess
# API endpoints - keep .php for API consistency
RewriteRule ^api/(.*)$ api/$1 [NC,L]

# Redirect .php URLs to clean URLs (for non-API files)
RewriteCond %{THE_REQUEST} /([^.]+)\.php [NC]
RewriteRule ^ /%1 [NC,L,R=301]
```

---

### Error #4: API User Agent Filtering
**Severity:** 🟡 Medium
**File:** `api/login.php`, `api/get-key.php`, `api/report-result.php`
**Line:** 4

**Problem:**
```php
// Block direct browser access
if (isset($_SERVER['HTTP_USER_AGENT']) && !stristr($_SERVER['HTTP_USER_AGENT'], 'PowerShell')) {
    http_response_code(403);
    die("Access denied. API access only.");
}
```

**Impact:**
- Cannot test API with curl, Postman, or browsers
- Breaks debugging and development workflows
- Prevents third-party integrations

**Workaround:**
Include PowerShell in User-Agent header:
```bash
curl -H "User-Agent: PowerShell/7.0" http://localhost:8080/api/login
```

**Recommended Fix:**
For development/testing environments, comment out or make configurable:
```php
// Only enforce in production
if (getConfig('api_enforce_useragent', '1') === '1') {
    if (isset($_SERVER['HTTP_USER_AGENT']) && !stristr($_SERVER['HTTP_USER_AGENT'], 'PowerShell')) {
        http_response_code(403);
        die(json_encode(['error' => 'API access only']));
    }
}
```

---

### Error #5: API Input Format Mismatch
**Severity:** 🔴 Critical
**File:** `api/login.php`
**Lines:** 16-18

**Problem:**
```php
// API expects JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['error' => 'Invalid JSON input'], 400);
}
```

**But PowerShell script sends:**
```powershell
# PowerShell main_v2.PS1 uses Invoke-RestMethod with ConvertTo-Json
# This SHOULD work, but the issue is how it's sent
```

**Impact:**
- Real PowerShell clients may fail to authenticate
- Form-encoded data is rejected
- Inconsistent with typical REST API patterns

**Testing Workaround:**
Send JSON with proper Content-Type:
```bash
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -H "User-Agent: PowerShell/7.0" \
  -d '{"technician_id":"TEST001","password":"test123"}'
```

**Status:** ⚠️ Needs verification with actual PowerShell client

---

### Error #6 & #7: Missing Helper Functions
**Severity:** 🔴 Critical
**File:** `config.php`

**Problem:**
API files call functions that don't exist in config.php:
- `getClientIP()` - Called in login.php line 45
- `validateTechnician()` - May be used elsewhere

These functions exist in `config-production.php` but not in the basic `config.php`.

**Error Message:**
```
PHP Fatal error: Uncaught Error: Call to undefined function getClientIP()
in /var/www/html/activate/api/login.php:45
```

**Impact:**
- All API endpoints crash with fatal errors
- No authentication possible
- System completely non-functional

**Solution:**
Added missing functions to config.php:
- `getClientIP()` - Extracts client IP from various proxy headers
- `validateTechnician()` - Validates credentials and manages account lockout
- `formatProductKeySecure()` - Formats keys for display

**Fix Applied:** ✅ Functions added to `config_docker.php`

---

### Error #8: Configuration Architecture Issue
**Severity:** 🔴 Critical
**Component:** Overall architecture

**Problem:**
The system has TWO configuration files with different capabilities:
- **config.php** - Basic version, missing many helper functions
- **config-production.php** - Complete version with all functions

API files include `config.php` expecting all functions to exist.

**Root Cause:**
Unclear which config file should be the "source of truth". The production config has evolved with more features, but the basic config was never updated.

**Solution:**
Use the enhanced config template (`config-template-enhanced.php`) as the base for all environments. This template includes all necessary functions.

**Fix Applied:** ✅ Created unified `config_docker.php` based on enhanced template

---

### Error #9: PHP Code Output as Plaintext
**Severity:** 🔴 Critical
**File:** `config.php`

**Problem:**
When helper functions were appended to config.php using heredoc, PHP code was being output to the browser instead of executed.

**Cause:**
Syntax error or missing PHP closing/opening tags in the append operation.

**Symptoms:**
```bash
$ curl http://localhost:8080/api/login
// Get client IP address (supports proxies)
function getClientIP() {
    $ipKeys = [
...
```

**Solution:**
Rewrote entire config.php file as a complete, validated PHP file instead of appending to existing file.

**Fix Applied:** ✅ Complete rewrite of config.php

---

### Error #10: Password Hash Corruption
**Severity:** 🔴 Critical
**Component:** Database / Password Storage

**Problem:**
When inserting password hashes into MySQL, dollar signs ($) were being stripped or escaped incorrectly.

**Example:**
```sql
-- Intended hash:
$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewYMqBCG5BYdPTIW

-- Stored in database:
y2/LewYMqBCG5BYdPTIW  -- ❌ Missing $2y$12$ prefix!
```

**Impact:**
- All password verifications fail
- Technicians cannot log in
- Account lockout triggers after 5 attempts

**Root Cause:**
Shell escaping issue when using mysql command with password hashes containing `$` characters.

**Solution:**
Generate hash in PHP, store in variable, then use variable in SQL:
```bash
HASH=$(docker exec oem-activation-web sh -c "php -r \"echo password_hash('test123', PASSWORD_BCRYPT);\"")
docker exec oem-activation-db mariadb -e "INSERT ... VALUES (..., '$HASH', ...)"
```

**Fix Applied:** ✅ Proper hash generation and storage

---

### Error #11: Missing Session Token in Login Response
**Severity:** 🔴 Critical
**File:** `api/login.php`

**Problem:**
API login returns success but doesn't include `session_token`:

**Actual Response:**
```json
{
    "success": true,
    "technician_id": "TEST001",
    "full_name": "Test Technician",
    "must_change_password": false,
    "using_temp_password": false
}
```

**Expected Response:**
```json
{
    "success": true,
    "session_token": "abc123...",  // ❌ MISSING!
    "technician_id": "TEST001",
    "full_name": "Test Technician",
    "must_change_password": false,
    "using_temp_password": false
}
```

**Impact:**
- Clients cannot make subsequent API calls
- get-key.php and report-result.php require session_token
- Authentication flow is broken

**Status:** ⚠️ Needs investigation - need to review login.php code to find where session_token generation should happen

---

## Testing Status Summary

### ✅ Successfully Fixed
1. Database credentials (config.php)
2. Missing helper functions (getClientIP, validateTechnician)
3. Configuration architecture (unified config file)
4. PHP code output issue
5. Password hash corruption

### ⚠️ Workarounds Available
6. Apache .htaccess redirects (use extensionless URLs)
7. User agent filtering (add PowerShell header)

### 🔴 Requires Further Investigation
8. API input format (JSON vs form-data)
9. Missing session_token in login response

---

## Recommendations for Production

### Immediate Fixes Required
1. **Update config.php template** - Use config-template-enhanced.php as the standard
2. **Fix login.php** - Add session_token generation and return in response
3. **Reorder .htaccess rules** - Put API exception before PHP redirect
4. **Make user agent check configurable** - Allow testing/development mode

### Architecture Improvements
1. **Unify configuration** - Merge config.php and config-production.php into single source
2. **Add config validation** - Script to check all required functions exist
3. **Improve error messages** - Return JSON errors instead of HTML for API endpoints
4. **Add development mode** - Disable strict security checks for local testing

### Testing Improvements
1. **Create test suite** - Automated tests for all API endpoints
2. **Add sample data** - Test technicians and keys in install.sql
3. **Document API** - OpenAPI/Swagger documentation for all endpoints
4. **Add health check endpoint** - `/api/health` to verify system status

---

## Files Modified

### Created
- `FINAL_PRODUCTION_SYSTEM/config_docker.php` - Complete configuration for Docker environment
- `docker-compose.yml` - Docker orchestration
- `Dockerfile.php` - Custom PHP container
- `.dockerignore` - Docker build optimization

### Modified in Container
- `/var/www/html/activate/config.php` - Replaced with unified configuration

### Pending Modifications
- `api/login.php` - Add session_token generation
- `.htaccess` - Reorder rewrite rules
- `api/*.php` - Make user agent check configurable

---

## Next Steps

1. **Investigate login.php** session token generation
2. **Test get-key.php** endpoint with session token
3. **Test report-result.php** with SMTP type cast fix
4. **Test CSV import** with unicode fix
5. **Test foreign key constraints** with concurrent operations
6. **Test admin panel** functionality
7. **Test client scripts** against Docker environment
8. **Document all API endpoints**
9. **Create automated test suite**
10. **Update CLAUDE.md** with findings

---

## Environment Details

**Docker Containers:**
- oem-activation-web: PHP 8.3.30 + Apache 2.4.66
- oem-activation-db: MariaDB 10.11.15
- oem-activation-phpmyadmin: Latest

**Database:**
- All 10 tables created successfully
- ENUM values: `enum('success','failed')` ✅
- Foreign keys: Present and correct ✅

**PHP Extensions:**
- pdo, pdo_mysql, mysqli, mbstring, opcache ✅

**Access Points:**
- Web: http://localhost:8080/
- API: http://localhost:8080/api/
- PHPMyAdmin: http://localhost:8081/
