<?php
// Synthetic identities on a loopback-only, isolated test server. Never deploy this file.
require __DIR__ . '/security.php';
start_secure_session();
$_SESSION['authenticated'] = true;
$_SESSION['username'] = ($_GET['user'] ?? '') === 'other' ? 'other' : 'fixture';
$_SESSION['role'] = ($_GET['role'] ?? '') === 'admin' ? 'admin' : 'user';
header('Content-Type: application/json');
echo json_encode(['csrf' => csrf_token()]);
