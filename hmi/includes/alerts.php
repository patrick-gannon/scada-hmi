<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/kasa_control.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── helpers ────────────────────────────────────────────────────────────────

function send_discord_alert(string $message): void {
    $url = hmi_getenv('DISCORD_WEBHOOK_URL');
    if (!$url) return;

    $payload = json_encode(['content' => $message, 'username' => 'SCADA HMI']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function send_email_alert(string $subject, string $body): void {
    $to   = hmi_getenv('ALERT_EMAIL_TO');
    $user = hmi_getenv('SMTP_USER');
    $pass = hmi_getenv('SMTP_PASS');
    $host = hmi_getenv('SMTP_HOST', 'smtp.gmail.com');
    $port = (int) hmi_getenv('SMTP_PORT', '587');
    $from = hmi_getenv('ALERT_EMAIL_FROM', $user);

    if (!$to || !$user || !$pass) return;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;

        $mail->setFrom($from, 'SCADA HMI');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
    } catch (Exception $e) {
        error_log('SCADA mailer error: ' . $mail->ErrorInfo);
    }
}

// ── main dispatcher ────────────────────────────────────────────────────────

/**
 * Check latest readings for each node against that node's thresholds,
 * fire alerts for any breach, and record in alarm_log.
 * Call this from a cron job: * * * * * php /var/www/.../includes/check_alerts.php
 */
function check_and_dispatch_alerts(): array {
    $db  = get_db();
    $kasa = new KasaController();
    $fired = [];

    // Get all nodes with a recent reading (last 10 minutes)
    $nodes = $db->query("
        SELECT e.node_id, e.temperature, e.humidity, e.recorded_at,
               COALESCE(n.display_name, e.node_id) AS display_name
        FROM environment e
        LEFT JOIN hmi_nodes n ON n.node_id = e.node_id
        WHERE e.recorded_at >= NOW() - INTERVAL 10 MINUTE
        ORDER BY e.node_id, e.recorded_at DESC
    ")->fetchAll();

    // De-duplicate: keep only the latest per node
    $latest = [];
    foreach ($nodes as $row) {
        if (!isset($latest[$row['node_id']])) $latest[$row['node_id']] = $row;
    }

    // Get thresholds
    $thresholds = $db->query("SELECT * FROM thresholds")->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
    // keyed by node_id; also check for 'global' node_id as fallback

    foreach ($latest as $node_id => $row) {
        // Check automatic Kasa triggers first
        $kasa->checkAutomaticTriggers($node_id, $row['temperature'], $row['humidity']);
        
        // Use node-specific threshold only if it has at least one value set,
        // otherwise fall back to global
        $node_t   = $thresholds[$node_id] ?? null;
        $global_t = $thresholds['global'] ?? null;
        $has_values = $node_t && array_filter([
            $node_t['temp_high'], $node_t['temp_low'],
            $node_t['humidity_high'], $node_t['humidity_low']
        ], fn($v) => $v !== null && $v !== '');
        $t = $has_values ? $node_t : $global_t;
        if (!$t) continue;

        $checks = [
            ['field' => 'temperature', 'val' => $row['temperature'],
             'hi' => $t['temp_high'] ?? null, 'lo' => $t['temp_low'] ?? null, 'unit' => '°C'],
            ['field' => 'humidity',    'val' => $row['humidity'],
             'hi' => $t['humidity_high'] ?? null, 'lo' => $t['humidity_low'] ?? null, 'unit' => '%'],
        ];

        foreach ($checks as $c) {
            $breached = false;
            $direction = '';
            if ($c['hi'] !== null && $c['val'] > (float)$c['hi']) { $breached = true; $direction = 'HIGH'; }
            if ($c['lo'] !== null && $c['val'] < (float)$c['lo']) { $breached = true; $direction = 'LOW'; }

            if (!$breached) continue;

            // Check cooldown — don't re-alert within 30 minutes for same node+field
            $recent = $db->prepare("
                SELECT id FROM alarm_log
                WHERE node_id = ? AND field = ? AND created_at >= NOW() - INTERVAL 30 MINUTE
                LIMIT 1
            ");
            $recent->execute([$node_id, $c['field']]);
            if ($recent->fetch()) continue;

            // Log it
            $db->prepare("INSERT INTO alarm_log (node_id, field, value, direction, threshold) VALUES (?,?,?,?,?)")
               ->execute([$node_id, $c['field'], $c['val'], $direction,
                          $direction === 'HIGH' ? $c['hi'] : $c['lo']]);

            $msg = sprintf(
                "⚠️ SCADA ALERT — %s\n%s %s: %.2f%s (%s threshold: %.2f%s)\nTime: %s",
                $row['display_name'], strtoupper($c['field']), $direction,
                $c['val'], $c['unit'], $direction, ($direction === 'HIGH' ? $c['hi'] : $c['lo']), $c['unit'],
                $row['recorded_at']
            );

            send_discord_alert($msg);
            send_email_alert("SCADA ALERT: {$row['display_name']} {$c['field']} {$direction}", $msg);
            $fired[] = $msg;
        }
    }
    return $fired;
}

// Allow running as a CLI cron job
if (PHP_SAPI === 'cli') {
    $results = check_and_dispatch_alerts();
    echo count($results) > 0 ? implode("\n---\n", $results) . "\n" : "No alerts fired.\n";
}
