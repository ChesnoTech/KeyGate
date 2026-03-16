<?php
/**
 * Root index — redirects to admin panel.
 *
 * If the installer hasn't been run yet, redirects to /install/ instead.
 */

// Check if install.lock exists (installer completed)
if (!file_exists(__DIR__ . '/install.lock') && is_dir(__DIR__ . '/install')) {
    header('Location: install/');
    exit;
}

// Redirect to admin panel
header('Location: secure-admin.php');
exit;
