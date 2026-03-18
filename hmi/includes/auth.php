<?php
require_once __DIR__ . '/db.php';

function session_start_once(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_path', '/');
    ini_set('session.use_strict_mode', '1');
    session_name('SCADA_HMI');
    session_start();
}

function current_user(): ?array {
    session_start_once();
    return $_SESSION['hmi_user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        $uri = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: /environment-monitor/login.php?next=' . $uri);
        exit;
    }
}

function require_admin(): void {
    require_login();
    $u = current_user();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die(json_encode(['error' => 'Admin access required']));
    }
}

function is_admin(): bool {
    $u = current_user();
    return ($u['role'] ?? '') === 'admin';
}

function attempt_login(string $username, string $password): bool {
    try {
        $db   = get_db();
        $stmt = $db->prepare("SELECT id, username, password_hash, role FROM hmi_users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) return false;

        session_start_once();
        session_regenerate_id(true);
        $_SESSION['hmi_user'] = [
            'id'       => $row['id'],
            'username' => $row['username'],
            'role'     => $row['role'],
        ];

        // Log the login
        $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
           ->execute([$row['username'], 'LOGIN', null, 'session started']);

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function do_logout(): void {
    $u = current_user();
    if ($u) {
        try {
            get_db()->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
                    ->execute([$u['username'], 'LOGOUT', null, 'session ended']);
        } catch (Exception) {}
    }
    session_start_once();
    session_destroy();
}
