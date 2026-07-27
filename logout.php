<?php
require_once __DIR__ . '/config.php';

// Clear session_token in database so account is freed up for future logins
if (isset($_SESSION['user_id'])) {
    try {
        $db = new OWI_DB();
        $db->execute("UPDATE users SET session_token = NULL, last_activity = 0 WHERE id = ?", [$_SESSION['user_id']]);
    } catch (Exception $e) {}
}

// Clear session variables
$_SESSION = array();

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit;
