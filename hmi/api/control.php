<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/kasa_control.php';

header('Content-Type: application/json');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'POST required']));
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$user   = current_user();

try {
    $db = get_db();
    $kasa = new KasaController();

    // ── Update setting (admin only) ────────────────────────────────────────
    if ($action === 'set_setting') {
        require_admin();
        $name  = $body['name']  ?? '';
        $value = $body['value'] ?? '';

        $allowed = ['log_interval', 'logging_active'];
        if (!in_array($name, $allowed, true)) die(json_encode(['error' => 'Invalid setting']));

        if ($name === 'log_interval') {
            $value = max(5, min(3600, (int)$value));
        }
        if ($name === 'logging_active') {
            $value = $value ? '1' : '0';
        }

        // The MySQL trigger will write to audit_log automatically,
        // but with username='system'. We update it to carry the operator name.
        $db->prepare("UPDATE settings SET setting_value=? WHERE setting_name=?")->execute([$value, $name]);

        // Patch the auto-generated audit row with the real username
        $db->prepare("
            UPDATE audit_log SET username=?
            WHERE action=? ORDER BY created_at DESC LIMIT 1
        ")->execute([$user['username'], "Updated $name"]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Update threshold ───────────────────────────────────────────────────
    if ($action === 'set_threshold') {
        require_admin();
        $node_id      = $body['node_id']      ?? 'global';
        $temp_high    = isset($body['temp_high'])    ? (float)$body['temp_high']    : null;
        $temp_low     = isset($body['temp_low'])     ? (float)$body['temp_low']     : null;
        $hum_high     = isset($body['humidity_high'])? (float)$body['humidity_high']: null;
        $hum_low      = isset($body['humidity_low']) ? (float)$body['humidity_low'] : null;
        $alert_email  = isset($body['alert_email'])  ? (int)(bool)$body['alert_email']  : 1;
        $alert_discord= isset($body['alert_discord'])? (int)(bool)$body['alert_discord']: 1;

        $db->prepare("
            INSERT INTO thresholds (node_id, temp_high, temp_low, humidity_high, humidity_low, alert_email, alert_discord)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                temp_high=VALUES(temp_high), temp_low=VALUES(temp_low),
                humidity_high=VALUES(humidity_high), humidity_low=VALUES(humidity_low),
                alert_email=VALUES(alert_email), alert_discord=VALUES(alert_discord)
        ")->execute([$node_id, $temp_high, $temp_low, $hum_high, $hum_low, $alert_email, $alert_discord]);

        $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
           ->execute([$user['username'], "Set thresholds: $node_id",
                      null, "T:{$temp_low}~{$temp_high} H:{$hum_low}~{$hum_high}"]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Rename node ────────────────────────────────────────────────────────
    if ($action === 'rename_node') {
        require_admin();
        $node_id = $body['node_id'] ?? '';
        $name    = substr(trim($body['display_name'] ?? ''), 0, 80);
        if (!$node_id) die(json_encode(['error' => 'node_id required']));

        $db->prepare("
            INSERT INTO hmi_nodes (node_id, display_name) VALUES (?,?)
            ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)
        ")->execute([$node_id, $name]);

        $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
           ->execute([$user['username'], "Renamed node: $node_id", null, $name]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Trigger Kasa switch (now with real control) ─────────────────────────────
    if ($action === 'kasa_toggle') {
        require_admin();
        $switch_id = $body['switch_id'] ?? '';
        $state     = isset($body['state']) ? (bool)$body['state'] : null;
        if ($state === null) die(json_encode(['error' => 'state required']));

        // Get plug details
        $plug = $db->prepare("SELECT plug_id, ip_address, display_name FROM kasa_plugs WHERE plug_id = ? AND is_active = 1");
        $plug->execute([$switch_id]);
        $plug_info = $plug->fetch();
        
        if (!$plug_info) {
            die(json_encode(['error' => 'Plug not found or inactive']));
        }

        // Control the plug
        $result = $kasa->controlPlug($plug_info['ip_address'], $state);
        
        if ($result['success']) {
            // Log the action
            $label = $state ? 'ON' : 'OFF';
            $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
               ->execute([$user['username'], "Kasa switch: $switch_id", null, $label]);
            
            // Log to plug actions
            $kasa->logPlugAction($switch_id, $state ? 'turn_on' : 'turn_off', 'manual', $user['username']);
            
            echo json_encode(['ok' => true, 'switch' => $switch_id, 'state' => $label, 'display_name' => $plug_info['display_name']]);
        } else {
            echo json_encode(['error' => 'Failed to control plug: ' . $result['error']]);
        }
        exit;
    }

    // ── Add Kasa plug ─────────────────────────────────────────────────────────
    if ($action === 'kasa_add_plug') {
        require_admin();
        $plug_id = $body['plug_id'] ?? '';
        $display_name = trim($body['display_name'] ?? '');
        $ip_address = $body['ip_address'] ?? '';
        $location = trim($body['location'] ?? '');
        
        if (!$plug_id || !$display_name || !$ip_address) {
            die(json_encode(['error' => 'plug_id, display_name, and ip_address required']));
        }
        
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            die(json_encode(['error' => 'Invalid IP address']));
        }

        $result = $kasa->addPlug($plug_id, $display_name, $ip_address, $location);
        
        if ($result) {
            $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
               ->execute([$user['username'], "Added Kasa plug: $plug_id", null, "$display_name ($ip_address)"]);
            
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Failed to add plug']);
        }
        exit;
    }

    // ── List Kasa plugs ───────────────────────────────────────────────────────
    if ($action === 'kasa_list_plugs') {
        require_admin();
        $plugs = $kasa->getAllPlugs();
        echo json_encode(['ok' => true, 'plugs' => $plugs]);
        exit;
    }

    // ── Add plug trigger ───────────────────────────────────────────────────────
    if ($action === 'kasa_add_trigger') {
        require_admin();
        $plug_id = $body['plug_id'] ?? '';
        $trigger_name = trim($body['trigger_name'] ?? '');
        $trigger_type = $body['trigger_type'] ?? '';
        $node_id = $body['node_id'] ?? null;
        $threshold_value = isset($body['threshold_value']) ? (float)$body['threshold_value'] : null;
        $time_value = $body['time_value'] ?? null;
        $action_type = $body['action'] ?? '';
        
        if (!$plug_id || !$trigger_name || !$trigger_type || !$action_type) {
            die(json_encode(['error' => 'plug_id, trigger_name, trigger_type, and action required']));
        }
        
        $allowed_types = ['manual', 'temp_high', 'temp_low', 'humidity_high', 'humidity_low', 'time_of_day'];
        $allowed_actions = ['turn_on', 'turn_off'];
        
        if (!in_array($trigger_type, $allowed_types) || !in_array($action_type, $allowed_actions)) {
            die(json_encode(['error' => 'Invalid trigger_type or action']));
        }
        
        if ($trigger_type !== 'manual' && $trigger_type !== 'time_of_day' && $threshold_value === null) {
            die(json_encode(['error' => 'threshold_value required for sensor triggers']));
        }
        
        if ($trigger_type === 'time_of_day' && !$time_value) {
            die(json_encode(['error' => 'time_value required for time_of_day triggers']));
        }

        $result = $kasa->addTrigger($plug_id, $trigger_name, $trigger_type, $node_id, $threshold_value, $time_value, $action_type);
        
        if ($result) {
            $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
               ->execute([$user['username'], "Added trigger: $trigger_name", null, "$trigger_type -> $action_type"]);
            
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Failed to add trigger']);
        }
        exit;
    }

    // ── List plug triggers ─────────────────────────────────────────────────────
    if ($action === 'kasa_list_triggers') {
        require_admin();
        $plug_id = $body['plug_id'] ?? '';
        if (!$plug_id) die(json_encode(['error' => 'plug_id required']));
        
        $triggers = $kasa->getPlugTriggers($plug_id);
        echo json_encode(['ok' => true, 'triggers' => $triggers]);
        exit;
    }

    // ── Manage HMI users (admin only) ──────────────────────────────────────
    if ($action === 'create_user') {
        require_admin();
        $uname = trim($body['username'] ?? '');
        $pass  = $body['password'] ?? '';
        $role  = in_array($body['role'] ?? '', ['admin','viewer']) ? $body['role'] : 'viewer';
        if (strlen($uname) < 2 || strlen($pass) < 8) die(json_encode(['error' => 'Username ≥2 chars, password ≥8 chars']));

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO hmi_users (username, password_hash, role) VALUES (?,?,?)")
           ->execute([$uname, $hash, $role]);

        $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
           ->execute([$user['username'], "Created user: $uname", null, $role]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_user') {
        require_admin();
        $uid  = (int)($body['id'] ?? 0);
        $role = in_array($body['role'] ?? '', ['admin','viewer']) ? $body['role'] : 'viewer';
        $active = isset($body['active']) ? (int)(bool)$body['active'] : 1;

        $target = $db->prepare("SELECT username FROM hmi_users WHERE id=?")->execute([$uid]) ? null : null;
        $target_row = $db->prepare("SELECT username FROM hmi_users WHERE id=?");
        $target_row->execute([$uid]);
        $target = $target_row->fetch()['username'] ?? 'unknown';

        $db->prepare("UPDATE hmi_users SET role=?, active=? WHERE id=?")->execute([$role, $active, $uid]);
        $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
           ->execute([$user['username'], "Updated user: $target", null, "role=$role active=$active"]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'change_password') {
        // Users can change their own password; admins can change anyone's
        $uid     = (int)($body['id'] ?? $user['id']);
        $newpass = $body['password'] ?? '';
        if ($uid !== $user['id'] && !is_admin()) die(json_encode(['error' => 'Forbidden']));
        if (strlen($newpass) < 8) die(json_encode(['error' => 'Password must be ≥8 characters']));

        $hash = password_hash($newpass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE hmi_users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
        $db->prepare("INSERT INTO audit_log (username, action, old_value, new_value) VALUES (?,?,?,?)")
           ->execute([$user['username'], "Password changed for uid=$uid", null, '***']);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── List HMI users (admin only) ────────────────────────────────────────
    if ($action === 'list_users') {
        require_admin();
        $rows = $db->query("SELECT id, username, role, active, created_at FROM hmi_users ORDER BY username")->fetchAll();
        echo json_encode(['ok' => true, 'users' => $rows]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);

} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        http_response_code(409);
        echo json_encode(['error' => 'Duplicate entry — that username or node already exists']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
