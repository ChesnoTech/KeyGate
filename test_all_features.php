<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['admin_token'] = 'bade73ef7e1c65140434e4d1916d58be3d2c75adafb69bb9ac4d5000b4ba1efb';
$_SESSION['admin_id'] = 1;
$_SESSION['session_id'] = 1;

echo "<h1>Testing All Admin Features</h1>";
echo "<style>body{font-family:Arial;padding:20px;} .success{color:green;} .error{color:red;} .test{margin:10px 0; padding:10px; border:1px solid #ddd;}</style>";

// Test 1: Dashboard Stats
echo "<div class='test'><h2>Test 1: Dashboard Statistics</h2>";
$_GET['action'] = 'get_stats';
ob_start();
include 'admin_v2.php';
$result = ob_get_clean();
$data = json_decode($result, true);
if ($data && $data['success']) {
    echo "<p class='success'>✓ Dashboard stats loaded</p>";
    echo "<pre>" . print_r($data['stats'], true) . "</pre>";
} else {
    echo "<p class='error'>✗ Dashboard stats failed</p>";
    echo "<pre>$result</pre>";
}
unset($_GET['action']);
echo "</div>";

// Test 2: List Keys
echo "<div class='test'><h2>Test 2: List Keys</h2>";
session_start();
$_SESSION['admin_token'] = 'bade73ef7e1c65140434e4d1916d58be3d2c75adafb69bb9ac4d5000b4ba1efb';
$_GET['action'] = 'list_keys';
$_GET['page'] = 1;
$_GET['filter'] = 'all';
$_GET['search'] = '';
ob_start();
include 'admin_v2.php';
$result = ob_get_clean();
$data = json_decode($result, true);
if ($data && $data['success']) {
    echo "<p class='success'>✓ Keys loaded: " . count($data['keys']) . " keys found</p>";
} else {
    echo "<p class='error'>✗ List keys failed</p>";
    echo "<pre>$result</pre>";
}
unset($_GET['action'], $_GET['page'], $_GET['filter'], $_GET['search']);
echo "</div>";

// Test 3: List Technicians
echo "<div class='test'><h2>Test 3: List Technicians</h2>";
session_start();
$_SESSION['admin_token'] = 'bade73ef7e1c65140434e4d1916d58be3d2c75adafb69bb9ac4d5000b4ba1efb';
$_GET['action'] = 'list_techs';
$_GET['page'] = 1;
$_GET['search'] = '';
ob_start();
include 'admin_v2.php';
$result = ob_get_clean();
$data = json_decode($result, true);
if ($data && $data['success']) {
    echo "<p class='success'>✓ Technicians loaded: " . count($data['technicians']) . " technicians found</p>";
} else {
    echo "<p class='error'>✗ List technicians failed</p>";
    echo "<pre>$result</pre>";
}
unset($_GET['action'], $_GET['page'], $_GET['search']);
echo "</div>";

// Test 4: List History
echo "<div class='test'><h2>Test 4: List Activation History</h2>";
session_start();
$_SESSION['admin_token'] = 'bade73ef7e1c65140434e4d1916d58be3d2c75adafb69bb9ac4d5000b4ba1efb';
$_GET['action'] = 'list_history';
$_GET['page'] = 1;
$_GET['filter'] = 'all';
$_GET['search'] = '';
ob_start();
include 'admin_v2.php';
$result = ob_get_clean();
$data = json_decode($result, true);
if ($data && $data['success']) {
    echo "<p class='success'>✓ Activation history loaded: " . count($data['history']) . " entries found</p>";
} else {
    echo "<p class='error'>✗ List history failed</p>";
    echo "<pre>$result</pre>";
}
unset($_GET['action'], $_GET['page'], $_GET['filter'], $_GET['search']);
echo "</div>";

// Test 5: List Logs
echo "<div class='test'><h2>Test 5: List Activity Logs</h2>";
session_start();
$_SESSION['admin_token'] = 'bade73ef7e1c65140434e4d1916d58be3d2c75adafb69bb9ac4d5000b4ba1efb';
$_GET['action'] = 'list_logs';
$_GET['page'] = 1;
$_GET['search'] = '';
ob_start();
include 'admin_v2.php';
$result = ob_get_clean();
$data = json_decode($result, true);
if ($data && $data['success']) {
    echo "<p class='success'>✓ Activity logs loaded: " . count($data['logs']) . " logs found</p>";
} else {
    echo "<p class='error'>✗ List logs failed</p>";
    echo "<pre>$result</pre>";
}
echo "</div>";

echo "<h2>All Tests Complete!</h2>";
echo "<p><a href='admin_v2.php'>Go to Admin Dashboard</a></p>";
?>
