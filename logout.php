<?php
declare(strict_types=1);
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_POST['csrf'])
    || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
    http_response_code(400); exit('Bad request');
}
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_destroy();
header('Location: login.php'); exit;
