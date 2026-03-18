<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    header('Content-Type: application/json');

    if (($body['action'] ?? '') === 'logout') {
        do_logout();
        echo json_encode(['ok' => true]);
        exit;
    }

    $ok = attempt_login($body['username'] ?? '', $body['password'] ?? '');
    if ($ok) {
        $u = current_user();
        echo json_encode(['ok' => true, 'user' => $u]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
    }
    exit;
}

// GET — redirect to login page
header('Location: /environment-monitor/login.php');
