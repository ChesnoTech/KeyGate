<?php
/**
 * KeyGate — Environment Variable Validation
 *
 * Run at container startup or via healthcheck to catch misconfiguration early.
 * Usage: php validate-env.php
 *
 * Exit code 0 = all required variables set
 * Exit code 1 = missing required variables
 */

$required = [
    'DB_HOST'        => 'Database hostname (e.g., db)',
    'DB_NAME'        => 'Database name (e.g., oem_activation)',
    'DB_USER'        => 'Database username',
    'DB_PASS'        => 'Database password',
    'REDIS_HOST'     => 'Redis hostname (e.g., redis)',
    'REDIS_PASSWORD'  => 'Redis authentication password',
];

$missing = [];
$insecure = [];

foreach ($required as $var => $desc) {
    $val = $_ENV[$var] ?? getenv($var) ?: '';
    if (empty($val)) {
        $missing[] = "  - $var: $desc";
    }
}

// Check for default/placeholder passwords
$dbPass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
if (in_array($dbPass, ['CHANGE_THIS_PASSWORD', 'CHANGE_ME_db_password', ''])) {
    $insecure[] = '  - DB_PASS contains a placeholder value — set a strong password';
}

$redisPass = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
if (in_array($redisPass, ['CHANGE_ME_redis_password', ''])) {
    $insecure[] = '  - REDIS_PASSWORD contains a placeholder value — set a strong password';
}

// Report results
$hasErrors = false;

if (!empty($missing)) {
    fwrite(STDERR, "\nFATAL: Missing required environment variables:\n");
    fwrite(STDERR, implode("\n", $missing) . "\n\n");
    fwrite(STDERR, "Copy .env.example to .env and fill in all values.\n\n");
    $hasErrors = true;
}

if (!empty($insecure)) {
    fwrite(STDERR, "\nWARNING: Insecure configuration detected:\n");
    fwrite(STDERR, implode("\n", $insecure) . "\n\n");
    $hasErrors = true;
}

if ($hasErrors) {
    exit(1);
}

echo "All required environment variables are set.\n";
exit(0);
