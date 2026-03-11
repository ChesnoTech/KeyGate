<?php
/**
 * DEPRECATED — rbac.php is replaced by acl.php (Phase 3 refactoring).
 *
 * This file exists only as a safety shim. All callers should require
 * 'functions/acl.php' directly, which provides requirePermission(),
 * aclCheck(), and aclRequire().
 */

error_log('DEPRECATED: functions/rbac.php included — use functions/acl.php instead. Caller: ' . debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file']);

require_once __DIR__ . '/acl.php';
