<?php
require_once __DIR__ . '/security.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

verify_csrf_or_fail();
destroy_secure_session();
header('Location: login.php');
exit;
?>
