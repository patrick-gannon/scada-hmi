<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? '';
$user   = current_user();

try {
    $db = get_db();

    // ── GET live readings ───────────────────────────────────────────────────
    if ($action === 'live') {
        $rows = $db->query("
            SELECT e.node_id,
                   COALESCE(n.display_name, e.node_id) AS display_name,
                   e.temperature, e.humidity, e.recorded_at,
                   TIMESTAMPDIFF(SECOND, e.recorded_at, NOW()) AS age_seconds
            FROM environment e
            LEFT JOIN hmi_nodes n ON n.node_id = e.node_id
            WHERE e.recorded_at = (
                SELECT MAX(e2.recorded_at) FROM environment e2 WHERE e2.node_id = e.node_id
            )
            ORDER BY e.node_id
        ")->fetchAll();

        $interval_row = $db->query("SELECT setting_value FROM settings WHERE setting_name='log_interval'")->fetch();
        $interval = (int)($interval_row['setting_value'] ?? 300);
        // Allow 1.5× the interval as buffer for slow cycles, plus a flat 60s grace period
        $cutoff = (int)($interval * 1.5) + 60;

        foreach ($rows as &$r) {
            $age = (int)$r['age_seconds'];
            $r['online'] = $age < $cutoff;
            $r['age_seconds'] = $age;
        }
        echo json_encode(['ok' => true, 'nodes' => $rows, 'log_interval' => $interval]);
        exit;
    }

    // ── GET settings ───────────────────────────────────────────────────────
    if ($action === 'settings') {
        $rows = $db->query("SELECT setting_name, setting_value FROM settings")->fetchAll();
        $settings = array_column($rows, 'setting_value', 'setting_name');
        echo json_encode(['ok' => true, 'settings' => $settings]);
        exit;
    }

    // ── GET nodes list ─────────────────────────────────────────────────────
    if ($action === 'nodes') {
        $rows = $db->query("SELECT * FROM hmi_nodes ORDER BY node_id")->fetchAll();
        echo json_encode(['ok' => true, 'nodes' => $rows]);
        exit;
    }

    // ── GET audit log ──────────────────────────────────────────────────────
    if ($action === 'audit') {
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);
        $rows = $db->prepare("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
        $rows->bindValue(2, $offset, PDO::PARAM_INT);
        $rows->execute();
        $total = $db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
        echo json_encode(['ok' => true, 'log' => $rows->fetchAll(), 'total' => (int)$total]);
        exit;
    }

    // ── GET alarms ─────────────────────────────────────────────────────────
    if ($action === 'alarms') {
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $rows = $db->prepare("
            SELECT a.*, COALESCE(n.display_name, a.node_id) AS display_name
            FROM alarm_log a
            LEFT JOIN hmi_nodes n ON n.node_id = a.node_id
            ORDER BY a.created_at DESC LIMIT ?
        ");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
        $rows->execute();
        echo json_encode(['ok' => true, 'alarms' => $rows->fetchAll()]);
        exit;
    }

    // ── GET thresholds ─────────────────────────────────────────────────────
    if ($action === 'thresholds') {
        $rows = $db->query("SELECT * FROM thresholds ORDER BY node_id")->fetchAll();
        echo json_encode(['ok' => true, 'thresholds' => $rows]);
        exit;
    }

    // ── GET history (sparkline data) ───────────────────────────────────────
    if ($action === 'history') {
        $node  = $_GET['node'] ?? '';
        $hours = min((int)($_GET['hours'] ?? 1), 24);
        $stmt  = $db->prepare("
            SELECT temperature, humidity, recorded_at
            FROM environment
            WHERE node_id = ? AND recorded_at >= NOW() - INTERVAL {$hours} HOUR
            ORDER BY recorded_at ASC
        ");
        $stmt->execute([$node]);
        echo json_encode(['ok' => true, 'history' => $stmt->fetchAll()]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
