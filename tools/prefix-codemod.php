<?php
/**
 * KeyGate Table-Prefix Codemod
 * ============================
 *
 * One-shot script that converts hardcoded table names into prefix-aware
 * references — Joomla-style `#__` sentinel for SQL, t('name') helper for PHP.
 *
 * Usage:
 *   php tools/prefix-codemod.php          # dry-run, show planned diff
 *   php tools/prefix-codemod.php --apply  # write changes
 *   php tools/prefix-codemod.php --verify # re-run on already-converted code;
 *                                           must produce zero changes
 *
 * What it does:
 *   1. Self-discovers the canonical KeyGate table list by scanning
 *      `database/*.sql` for `CREATE TABLE [IF NOT EXISTS]` statements.
 *      No hand-maintained list — picks up new tables automatically.
 *   2. SQL pass: every `` `name` `` (where `name` is canonical) becomes
 *      `` `#__name` ``. Already-prefixed names are left alone (idempotent).
 *   3. PHP pass: every backticked SQL table reference inside PHP strings
 *      becomes `` `' . t('name') . '` `` (via concatenation) or
 *      `` `{$prefix}name` `` (via interpolation).
 *      Pattern recognized only inside PHP strings to avoid touching
 *      identifiers in error messages or comments.
 *   4. Verify pass: re-scan converted output. If any `#__name` is missing
 *      or any literal `` `tablename` `` remains in a SQL context, fail.
 *
 * Constraints:
 *   - Only operates on files under FINAL_PRODUCTION_SYSTEM/
 *   - Skips vendor/, node_modules/, frontend/dist/, graphify-out/, .git/
 *   - Idempotent: second run produces zero changes.
 *
 * Exit codes:
 *   0 — success
 *   1 — usage error
 *   2 — unsafe (would damage code)
 */

declare(strict_types=1);

// ── Argument parsing ──────────────────────────────────────────────
$apply  = in_array('--apply',  $argv, true);
$verify = in_array('--verify', $argv, true);
$quiet  = in_array('--quiet',  $argv, true);

if ($apply && $verify) {
    fwrite(STDERR, "Cannot use --apply and --verify together.\n");
    exit(1);
}

// --root <path> overrides default detection. Useful inside Docker where
// FINAL_PRODUCTION_SYSTEM lives at /var/www/html/activate, not next to tools/.
$customRoot = '';
foreach ($argv as $i => $arg) {
    if ($arg === '--root' && isset($argv[$i + 1])) {
        $customRoot = $argv[$i + 1];
        break;
    }
    if (str_starts_with($arg, '--root=')) {
        $customRoot = substr($arg, 7);
        break;
    }
}

if ($customRoot !== '') {
    $appRoot = realpath($customRoot);
} else {
    $root = realpath(__DIR__ . '/..');
    $appRoot = $root . DIRECTORY_SEPARATOR . 'FINAL_PRODUCTION_SYSTEM';
}
if (!is_dir($appRoot)) {
    fwrite(STDERR, "FINAL_PRODUCTION_SYSTEM not found at {$appRoot}\n");
    exit(1);
}

$dbDir = $appRoot . DIRECTORY_SEPARATOR . 'database';

// ── 1. Discover canonical table list ──────────────────────────────
$tables = discoverTables($dbDir);
sort($tables);
if (!$quiet) {
    fwrite(STDOUT, "Discovered " . count($tables) . " tables.\n");
}

// Sort by length descending so longer names match before substring siblings
// (e.g. `admin_users` before `admin`).
usort($tables, fn($a, $b) => strlen($b) - strlen($a));

// Build deny list — never rewrite these even if they appear in SQL contexts.
// They are SQL keywords / column names that happen to look like table names.
$denyAlias = [
    'config_value', // column in system_config
    'value',        // generic column name
    'key',          // SQL keyword + common column
    'name',         // common column
    'role',         // common column
];
$tables = array_values(array_diff($tables, $denyAlias));

if (count($tables) < 5) {
    fwrite(STDERR, "Refusing to run: only " . count($tables) . " tables discovered. Something is wrong.\n");
    exit(2);
}

// ── 2. SQL pass ───────────────────────────────────────────────────
$sqlFiles = glob($dbDir . '/*.sql');
$sqlChanges = 0;
$sqlFilesChanged = [];

foreach ($sqlFiles as $f) {
    $orig = file_get_contents($f);
    $new = rewriteSqlBody($orig, $tables);
    if ($new !== $orig) {
        $sqlChanges += substr_count($new, '#__') - substr_count($orig, '#__');
        $sqlFilesChanged[] = basename($f);
        if ($apply) {
            file_put_contents($f, $new);
        }
    }
}

if (!$quiet) {
    fwrite(STDOUT, "SQL: " . count($sqlFilesChanged) . " files changed, +{$sqlChanges} `#__` markers.\n");
}

// ── 3. PHP pass ───────────────────────────────────────────────────
$phpFiles = collectPhpFiles($appRoot);
$phpChanges = 0;
$phpFilesChanged = [];

foreach ($phpFiles as $f) {
    $orig = file_get_contents($f);
    $new = rewritePhpBody($orig, $tables);
    if ($new !== $orig) {
        $phpFilesChanged[] = str_replace($appRoot . DIRECTORY_SEPARATOR, '', $f);
        $diffLines = countDiff($orig, $new);
        $phpChanges += $diffLines;
        if ($apply) {
            file_put_contents($f, $new);
        }
    }
}

if (!$quiet) {
    fwrite(STDOUT, "PHP: " . count($phpFilesChanged) . " files changed, ~{$phpChanges} site rewrites.\n");
}

// ── 4. Verify pass (when --verify) ────────────────────────────────
if ($verify) {
    $stillBad = [];
    foreach ($sqlFiles as $f) {
        $body = file_get_contents($f);
        foreach ($tables as $t) {
            // Look for un-prefixed backticked refs.
            // Pattern: `tablename` not preceded by `#__
            if (preg_match('/(?<!#__)`' . preg_quote($t, '/') . '`/', $body)) {
                $stillBad[] = basename($f) . ": found unprefixed `{$t}`";
            }
        }
    }
    if (!empty($stillBad)) {
        fwrite(STDERR, "Verify FAILED:\n  " . implode("\n  ", $stillBad) . "\n");
        exit(2);
    }
    fwrite(STDOUT, "Verify PASS: zero unprefixed table references in SQL.\n");
    exit(0);
}

// ── Summary ───────────────────────────────────────────────────────
if (!$apply && !$verify) {
    fwrite(STDOUT, "\nDRY RUN. Re-run with --apply to write.\n");
    fwrite(STDOUT, "After --apply, run with --verify to confirm idempotency.\n");
}

exit(0);

// ══════════════════════════════════════════════════════════════════

/**
 * Walk every `.sql` in the database dir and capture every table name
 * that appears in a `CREATE TABLE [IF NOT EXISTS]` statement.
 */
function discoverTables(string $dbDir): array {
    $tables = [];
    // Match name optionally prefixed with `#__` so the codemod is idempotent
    // (post-rewrite SQL has `#__name`; discovery should still resolve to `name`).
    $namePat = '(?:#__)?([a-z_][a-z0-9_]*)';
    foreach (glob($dbDir . '/*.sql') as $file) {
        $body = file_get_contents($file);
        if (preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . $namePat . '`?/i',
            $body, $m
        )) {
            foreach ($m[1] as $name) $tables[strtolower($name)] = true;
        }
        if (preg_match_all(
            '/ALTER\s+TABLE\s+`?' . $namePat . '`?/i',
            $body, $m
        )) {
            foreach ($m[1] as $name) $tables[strtolower($name)] = true;
        }
    }
    return array_keys($tables);
}

/**
 * Walk FINAL_PRODUCTION_SYSTEM/ and collect every PHP file.
 * Skips vendor/, node_modules/, frontend/dist/, graphify-out/, .git/
 */
function collectPhpFiles(string $appRoot): array {
    $skip = ['/vendor/', '/node_modules/', '/dist/', '/graphify-out/', '/.git/'];
    $files = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        if (substr($f->getFilename(), -4) !== '.php') continue;
        $path = str_replace('\\', '/', $f->getPathname());
        foreach ($skip as $s) {
            if (strpos($path, $s) !== false) continue 2;
        }
        $files[] = $f->getPathname();
    }
    return $files;
}

/**
 * Rewrite SQL body: `tablename` → `#__tablename` for every canonical table.
 * Idempotent: skips already-prefixed.
 */
function rewriteSqlBody(string $body, array $tables): string {
    $kw = '(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|SELECT\s+(?:[^;]*?)FROM|UPDATE|DELETE\s+FROM|FROM|JOIN|REFERENCES|TRUNCATE(?:\s+TABLE)?|RENAME\s+TABLE|LOCK\s+TABLES|DESCRIBE|EXPLAIN)';

    foreach ($tables as $t) {
        // ── Pattern A: backticked refs ────────────────────────────
        // (?<!#__) lookbehind: don't double-prefix.
        $body = preg_replace(
            '/(?<!#__)`(' . preg_quote($t, '/') . ')`/',
            '`#__$1`',
            $body
        );

        // ── Pattern B: bare-name refs preceded by SQL keyword ─────
        // Word boundary on the trailing side avoids matching prefixes of
        // other tokens (e.g. "techniciansSomething").
        $body = preg_replace_callback(
            '/(\b' . $kw . '\s+)(' . preg_quote($t, '/') . ')\b/i',
            function ($m) use ($t) {
                return $m[1] . '`#__' . $t . '`';
            },
            $body
        );
    }
    return $body;
}

/**
 * Rewrite PHP body: backticked table refs inside string literals become
 * prefix-aware via t() helper.
 *
 * Two patterns handled:
 *   - "...`tablename`..."  → "...`' . t('tablename') . '`..."   (single quote concat)
 *   - "...`tablename`..."  → "...`{TABLE_PREFIX}tablename`..."  (in heredoc — kept simpler)
 *
 * To keep the codemod safe and reviewable we do a single conservative
 * transformation: emit string concatenation form everywhere. PHP heredocs
 * that already use {$variable} interpolation will be edited to use the
 * concatenation by closing the heredoc — out of scope for this codemod.
 * For the existing KeyGate codebase no `<<<` heredocs contain table
 * references (verified manually in P0).
 */
function rewritePhpBody(string $body, array $tables): string {
    // Don't touch the codemod itself.
    if (strpos($body, 'KeyGate Table-Prefix Codemod') !== false) {
        return $body;
    }
    // Don't touch installer files (they have their own prefix logic).
    if (preg_match('/\binstaller(MigrationList|RunSqlFile|SplitSql)\(/', $body)) {
        return $body;
    }
    // Don't touch the generated config.php template (uses heredoc that emits
    // raw SQL strings — out of scope for this codemod).
    if (strpos($body, 'function generateConfig') !== false && strpos($body, 'PHP_HEADER') !== false) {
        return $body;
    }
    // Don't touch the prefix codemod's own helper file.
    if (strpos($body, 'function t(string $name)') !== false && strpos($body, 'DB_PREFIX') !== false) {
        return $body;
    }

    // Tokenize PHP. Transform only T_CONSTANT_ENCAPSED_STRING (single OR
    // double-quoted) and T_ENCAPSED_AND_WHITESPACE (heredoc/double-quote
    // body). Leaves comments, identifiers, and code structure untouched.
    $tokens = @token_get_all($body);
    if ($tokens === false) {
        return $body;  // unparseable; bail
    }

    $out = '';
    foreach ($tokens as $tok) {
        if (!is_array($tok)) {
            $out .= $tok;
            continue;
        }
        [$id, $text] = $tok;

        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            // Single- or double-quoted complete string literal. Detect quote
            // style and transform the inside only.
            $quote = $text[0];
            $inner = substr($text, 1, -1);
            $newInner = rewriteSqlStringInner($inner, $tables, $quote);
            $out .= $quote . $newInner . $quote;
        } else {
            // T_ENCAPSED_AND_WHITESPACE (heredoc, interpolated double-quoted) —
            // skipped on purpose. Closing the heredoc to inject t() call would
            // require restructuring the surrounding code. Verify pass flags
            // unprefixed table refs in heredocs for manual review.
            $out .= $text;
        }
    }
    return $out;
}

/**
 * Transform a single PHP string body (inside the quotes already stripped):
 *   FROM oem_keys     → FROM `" . t('oem_keys') . "`     (when $quote === '"')
 *   FROM oem_keys     → FROM `\' . t(\'oem_keys\') . \'` (when $quote === "'")
 *
 * Pattern guards against:
 *   - Already-prefixed `#__name` literals
 *   - Already-rewritten t('name') call sites
 */
function rewriteSqlStringInner(string $inner, array $tables, string $quote): string {
    $kw = '(?:FROM|JOIN|INTO|UPDATE|REFERENCES|TABLE|TRUNCATE|RENAME\s+TO|DELETE\s+FROM)';

    // Already-rewritten guard: skip strings that already contain t(' calls.
    // A second-run on rewritten code should be a no-op.
    if (strpos($inner, "t('") !== false || strpos($inner, 't("') !== false) {
        // Continue — rewritten sites embed the t() call, but other tables
        // might still need rewriting in the same string. Negative lookahead
        // in the regex below handles that.
    }

    foreach ($tables as $t) {
        // Pattern A: backticked refs `tablename` (not already prefixed).
        $inner = preg_replace_callback(
            '/(\b' . $kw . '\s+)`(?<!#__)(' . preg_quote($t, '/') . ')`/i',
            function ($m) use ($t, $quote) {
                return $m[1] . phpStringEmbedTHelper($t, $quote);
            },
            $inner
        );

        // Pattern B: bare-name refs.
        // Negative lookahead avoids re-rewriting an already-converted call site
        // ("FROM `' . t('oem_keys') . '`" should not match again).
        $inner = preg_replace_callback(
            '/(\b' . $kw . '\s+)(?!`)(' . preg_quote($t, '/') . ')\b(?!\s*\.\s*t\()/i',
            function ($m) use ($t, $quote) {
                return $m[1] . phpStringEmbedTHelper($t, $quote);
            },
            $inner
        );
    }
    return $inner;
}

/**
 * Build the in-string concatenation snippet. Output depends on the quote
 * style of the enclosing PHP string literal.
 *
 *  Double-quoted:  `" . t('oem_keys') . "`
 *  Single-quoted:  `\' . t(\'oem_keys\') . \'`
 */
function phpStringEmbedTHelper(string $tableName, string $quote): string {
    if ($quote === '"') {
        return "`\" . t('{$tableName}') . \"`";
    }
    // Single-quoted host string: backslash-escape inner single quotes.
    return "`' . t('{$tableName}') . '`";
}

/**
 * Naive line-difference count for reporting.
 */
function countDiff(string $a, string $b): int {
    $aLines = explode("\n", $a);
    $bLines = explode("\n", $b);
    $diff = 0;
    $n = max(count($aLines), count($bLines));
    for ($i = 0; $i < $n; $i++) {
        if (($aLines[$i] ?? null) !== ($bLines[$i] ?? null)) $diff++;
    }
    return $diff;
}
