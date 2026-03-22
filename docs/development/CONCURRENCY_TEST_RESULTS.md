# KeyGate - Concurrency Testing Results

**Test Date:** 2026-01-25
**Environment:** Docker (PHP 8.3.30 + MariaDB 10.11.15)
**Status:** 🔴 **CRITICAL BUG FOUND - Race Condition in Key Allocation**

---

## Executive Summary

**Answer to User's Question:** "Did you test the behavior of handling multi activation requests at the same time from different technicians or from the same technician?"

**YES - Concurrency testing has now been performed** with the following results:

- ✅ **Test 3 PASSED**: Same technician, same order → GET_LOCK() works correctly
- 🔴 **Test 1 FAILED**: Different technicians → FOR UPDATE race condition detected
- ⏸️ **Test 2-6**: Not yet completed due to critical bug found

---

## Critical Bug Discovered

### 🔴 Bug #16: Race Condition in FOR UPDATE Locking

**Severity:** CRITICAL
**Impact:** Multiple technicians can be allocated the SAME OEM key simultaneously

**Test That Failed:**
```bash
# 5 different technicians requesting keys concurrently
TEST001 → order MT001
TECH002 → order MT002
TECH003 → order MT003
TECH004 → order MT004
TECH005 → order MT005
```

**Expected Result:**
- 5 unique keys allocated
- Each technician gets a different key

**Actual Result:**
- 4 technicians (TEST001, TECH003, TECH004, TECH005) got the SAME key: `ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ` (key_id=3)
- Only 1 technician (TECH002) got a different key: `BBBBB-BBBBB-BBBBB-BBBBB-BBBBB` (key_id=4)

**Database Evidence:**
```sql
SELECT s.technician_id, s.key_id, k.product_key
FROM active_sessions s
JOIN oem_keys k ON s.key_id = k.id
WHERE s.order_number LIKE 'MT%';

Result:
technician_id | key_id | product_key
TEST001       | 3      | ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ
TECH002       | 4      | BBBBB-BBBBB-BBBBB-BBBBB-BBBBB
TECH003       | 3      | ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ  ← DUPLICATE!
TECH004       | 3      | ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ  ← DUPLICATE!
TECH005       | 3      | ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ  ← DUPLICATE!
```

**Verification:**
```sql
-- Confirm only ONE key with product_key ZZZZZ exists
SELECT id, product_key, key_status FROM oem_keys WHERE product_key LIKE 'ZZZZZ%';

Result:
id | product_key                   | key_status
3  | ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ | unused

-- This confirms the same key was allocated to 4 different sessions!
```

---

## Root Cause Analysis

### Current Implementation (config-production.php:217-301)

The `allocateKeyAtomically()` function uses:

1. **GET_LOCK()** advisory lock (line 227):
   ```php
   $lockName = "key_allocation_" . md5($technician_id . $order_number);
   $stmt = $pdo->prepare("SELECT GET_LOCK(?, 10) as acquired");
   $stmt->execute([$lockName]);
   ```
   - **Purpose**: Prevent same tech+order from allocating twice
   - **Scope**: Lock name is based on technician_id + order_number
   - **Problem**: Different technicians get DIFFERENT lock names → no collision prevention

2. **FOR UPDATE** row-level lock (line 248):
   ```php
   $stmt = $pdo->prepare("
       SELECT * FROM oem_keys
       WHERE key_status IN ('unused', 'retry')
       ...
       LIMIT 1
       FOR UPDATE
   ");
   ```
   - **Purpose**: Lock the selected key row
   - **Problem**: Race condition window BEFORE the SELECT executes

### Why FOR UPDATE Failed

**Race Condition Timeline:**
```
Time T0: Request A (TEST001) → Begin transaction
Time T0: Request B (TECH003) → Begin transaction
Time T0: Request C (TECH004) → Begin transaction
Time T0: Request D (TECH005) → Begin transaction

Time T1: Request A executes SELECT ... FOR UPDATE → selects key_id=3, locks it
Time T1: Request B executes SELECT ... FOR UPDATE → WAITS for lock on key_id=3
Time T1: Request C executes SELECT ... FOR UPDATE → WAITS for lock on key_id=3
Time T1: Request D executes SELECT ... FOR UPDATE → WAITS for lock on key_id=3

Time T2: Request A INSERTS session with key_id=3
Time T3: Request A COMMITS → releases lock on key_id=3

Time T4: Request B's SELECT returns → gets key_id=3 (lock released, key still "unused")
Time T4: Request C's SELECT returns → gets key_id=3 (lock released, key still "unused")
Time T4: Request D's SELECT returns → gets key_id=3 (lock released, key still "unused")

Time T5: Request B INSERTS session with key_id=3 → SUCCESS
Time T5: Request C INSERTS session with key_id=3 → SUCCESS
Time T5: Request D INSERTS session with key_id=3 → SUCCESS
```

**The Problem:**
- `FOR UPDATE` only locks the row DURING THE SELECT
- After Request A commits, the lock is released
- Requests B, C, D's SELECT statements see key_id=3 still has `key_status='unused'`
- No UPDATE statement changes key_status, so subsequent SELECTs keep returning the same key

---

## Why This Happens

### Missing Key Status Update

**Current Code** (config-production.php:250-280):
```php
// SELECT key with FOR UPDATE
$stmt = $pdo->prepare("SELECT * FROM oem_keys ... FOR UPDATE");
$stmt->execute(...);
$key = $stmt->fetch(PDO::FETCH_ASSOC);

// INSERT into active_sessions
$stmt = $pdo->prepare("INSERT INTO active_sessions (...)  VALUES (...)");
$stmt->execute([...]);

// ❌ PROBLEM: key_status is NEVER updated to 'allocated' or similar
// The key remains 'unused' in oem_keys table
// Next SELECT ... FOR UPDATE will find the same key again!
```

**What's Missing:**
```php
// After allocating the key, BEFORE commit:
$stmt = $pdo->prepare("
    UPDATE oem_keys
    SET key_status = 'allocated'  -- Or similar status
    WHERE id = ?
");
$stmt->execute([$key['id']]);
```

### Why key_status Isn't Updated

Looking at the schema and business logic:

**oem_keys.key_status ENUM values** (install.sql:42):
```sql
`key_status` enum('unused','good','bad','retry') DEFAULT 'unused'
```

**There is NO 'allocated' status!**

The system expects:
- `unused` → Key available for allocation
- `good` → Activation succeeded (set by report-result.php)
- `bad` → Key failed 3+ times (set by report-result.php)
- `retry` → Key failed 1-2 times (set by report-result.php)

**Design Flaw:**
- The system relies on the active_sessions table to track which keys are "in use"
- But during concurrent allocation, the FOR UPDATE lock is released BEFORE other transactions check active_sessions
- This creates a race condition window

---

## Test Results Summary

### ✅ Test 3: Same Technician, Same Order (PASSED)

**Test:**
```bash
# 3 concurrent requests for TEST001 + order CONC1
curl POST /api/get-key -d '{"technician_id":"TEST001","order_number":"CONC1"}' &
curl POST /api/get-key -d '{"technician_id":"TEST001","order_number":"CONC1"}' &
curl POST /api/get-key -d '{"technician_id":"TEST001","order_number":"CONC1"}' &
```

**Result:**
- Request 1: Created new session with token `61bddb...`
- Request 2: "Resuming existing session" (same token `61bddb...`)
- Request 3: "Resuming existing session" (same token `61bddb...`)

**Database Verification:**
```sql
SELECT COUNT(*), session_token
FROM active_sessions
WHERE technician_id='TEST001' AND order_number='CONC1';

Result: 1 session (same token)
```

**Analysis:**
✅ **GET_LOCK() works correctly** for same technician + same order
- Lock name: `key_allocation_` + MD5(`TEST001` + `CONC1`) = same lock for all 3 requests
- First request acquires lock, allocates key, creates session
- Requests 2 & 3 wait for lock, then find existing session via `getActiveSession()`
- All return the same key

**Verdict:** ✅ **PASS** - GET_LOCK() prevents duplicate allocation for identical tech+order

---

### 🔴 Test 1: Different Technicians (FAILED)

**Test:**
```bash
# 5 concurrent requests from different technicians
TEST001 + order MT001 &
TECH002 + order MT002 &
TECH003 + order MT003 &
TECH004 + order MT004 &
TECH005 + order MT005 &
```

**Result:**
- 4 technicians got key_id=3 (ZZZZZ key)
- 1 technician got key_id=4 (BBBBB key)

**Analysis:**
🔴 **FOR UPDATE does NOT prevent race condition** for different technicians
- Each technician has different lock name (different tech_id + order_number)
- All transactions proceed in parallel
- FOR UPDATE only locks DURING the SELECT
- After first commit, subsequent SELECTs return the same key (still "unused")

**Verdict:** 🔴 **FAIL** - Critical race condition allows duplicate key allocation

---

## Fix Recommendations

### Option 1: Add 'allocated' Status to ENUM (Recommended)

**Schema Change:**
```sql
ALTER TABLE oem_keys
MODIFY COLUMN key_status
ENUM('unused','allocated','good','bad','retry') DEFAULT 'unused';
```

**Code Change** (config-production.php after line 269):
```php
// After INSERT into active_sessions, update key status
$stmt = $pdo->prepare("
    UPDATE oem_keys
    SET key_status = 'allocated',
        last_use_date = CURDATE(),
        last_use_time = CURTIME()
    WHERE id = ?
");
$stmt->execute([$key['id']]);
```

**SELECT Query Update** (line 238):
```php
$stmt = $pdo->prepare("
    SELECT * FROM oem_keys
    WHERE key_status IN ('unused', 'retry')  -- Remove 'allocated' keys
    ...
    FOR UPDATE
");
```

**Benefits:**
- Clear state tracking: unused → allocated → good/bad/retry
- FOR UPDATE now has exclusive access during allocation
- No race condition: allocated keys won't be selected again

**Trade-offs:**
- Schema migration required
- report-result.php logic unchanged (allocated → good/bad already makes sense)

---

### Option 2: Check active_sessions INSIDE Transaction

**Code Change** (config-production.php after FOR UPDATE):
```php
// After selecting key
$key = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if this key is already allocated in active_sessions
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count
    FROM active_sessions
    WHERE key_id = ? AND is_active = 1
");
$stmt->execute([$key['id']]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result['count'] > 0) {
    // Key already allocated, recursively try again
    error_log("Key #{$key['id']} already allocated, retrying...");
    $pdo->rollback();
    return allocateKeyAtomically($pdo, $technician_id, $order_number); // Retry
}

// Proceed with INSERT into active_sessions
```

**Benefits:**
- No schema change required
- Immediate fix

**Trade-offs:**
- Recursive retry could cause infinite loop if all keys allocated
- Performance overhead (extra SELECT per allocation)
- Doesn't fix the root cause, just adds detection

---

### Option 3: Use Serializable Isolation Level

**Code Change** (config-production.php before line 219):
```php
function allocateKeyAtomically($pdo, $technician_id, $order_number) {
    // Set transaction isolation to SERIALIZABLE for this connection
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");

    $lockName = "key_allocation_" . md5($technician_id . $order_number);
    $needsCommit = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $needsCommit = true;
        }

        // ... rest of function
    }
}
```

**Benefits:**
- Prevents phantom reads completely
- MySQL enforces stricter locking automatically

**Trade-offs:**
- Performance impact (more locking, potential deadlocks)
- May cause transaction failures under high concurrency
- Doesn't fix the semantic issue (key_status not updated)

---

## Recommended Solution

**Use Option 1: Add 'allocated' status**

**Implementation Steps:**
1. Add 'allocated' to key_status ENUM
2. Update allocateKeyAtomically() to set key_status='allocated' after INSERT
3. Update report-result.php to transition allocated → good/bad/retry
4. Test concurrent allocation with 10+ simultaneous requests
5. Verify no duplicate allocations occur

**Migration SQL:**
```sql
-- Step 1: Add 'allocated' to ENUM
ALTER TABLE oem_keys
MODIFY COLUMN key_status
ENUM('unused','allocated','good','bad','retry') DEFAULT 'unused';

-- Step 2: Update any existing allocated keys (keys with active sessions)
UPDATE oem_keys k
INNER JOIN active_sessions s ON k.id = s.key_id
SET k.key_status = 'allocated'
WHERE s.is_active = 1 AND k.key_status = 'unused';
```

---

## Test Status Checklist

- [x] Test 3: Same Technician Same Order → ✅ PASSED
- [x] Test 1: Different Technicians → 🔴 FAILED (race condition found)
- [ ] Test 2: Same Technician Different Orders → Blocked by bug
- [ ] Test 4: FOR UPDATE Verification → Blocked by bug
- [ ] Test 5: Load Test 50 Concurrent → Blocked by bug
- [ ] Test 6: Lock Timeout Handling → Not tested yet

**Status:** Testing suspended until Bug #16 is fixed

---

## Files to Modify

1. **database/install.sql** - Add 'allocated' to ENUM
2. **FINAL_PRODUCTION_SYSTEM/config-production.php** - Update allocateKeyAtomically()
3. **FINAL_PRODUCTION_SYSTEM/api/report-result.php** - Handle allocated → good/bad transitions
4. **WebRootAfterInstall/** - Replicate all changes

---

## Next Steps

1. ✅ Document the bug (this file)
2. ⏸️ Wait for user approval to implement fix
3. ⏸️ Implement Option 1 (add 'allocated' status)
4. ⏸️ Re-run Test 1 to verify fix
5. ⏸️ Complete remaining concurrency tests (Tests 2-6)
6. ⏸️ Create comprehensive test report

---

**Report Generated:** 2026-01-25 12:30:00 UTC
**Critical Bug Severity:** HIGH - Affects production use
**Immediate Action Required:** Fix before production deployment
