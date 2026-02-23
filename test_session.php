<?php
session_start();
$_SESSION['admin_token'] = 'bade73ef7e1c65140434e4d1916d58be3d2c75adafb69bb9ac4d5000b4ba1efb';
$_SESSION['admin_id'] = 1;
$_SESSION['session_id'] = 1;

header('Location: admin_v2.php');
?>
