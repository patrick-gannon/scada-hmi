<?php
/**
 * Cron script to execute time-based plug schedules
 * 
 * Run this every minute via cron:
 * * * * * /usr/bin/php /var/www/html/hmi/cron/check_schedules.php >> /var/log/scada_schedules.log 2>&1
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/kasa_control.php';

header('Content-Type: text/plain');

function log_msg($msg) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
}

$currentDay = (int)date('w'); // 0=Sunday, 1=Monday, etc.
$currentHour = (int)date('H');
$currentMinute = (int)date('i');
$currentMinutes = $currentHour * 60 + $currentMinute;

try {
    $db = get_db();
    $kasa = new KasaController();
    
    // Get all active time_of_day triggers
    $stmt = $db->prepare("
        SELECT pt.*, kp.ip_address, kp.display_name as plug_name
        FROM plug_triggers pt
        JOIN kasa_plugs kp ON pt.plug_id = kp.plug_id
        WHERE pt.is_active = 1
        AND kp.is_active = 1
        AND pt.trigger_type = 'time_of_day'
    ");
    $stmt->execute();
    $schedules = $stmt->fetchAll();
    
    $executed = 0;
    $skipped = 0;
    
    foreach ($schedules as $schedule) {
        // Check if today is in allowed days
        $allowedDays = str_split($schedule['days_of_week'] ?: '0123456');
        if (!in_array((string)$currentDay, $allowedDays)) {
            $skipped++;
            continue;
        }
        
        // Check if current time matches (within 1 minute)
        $scheduleTime = strtotime($schedule['time_value']);
        $scheduleHour = (int)date('H', $scheduleTime);
        $scheduleMinute = (int)date('i', $scheduleTime);
        $scheduleMinutes = $scheduleHour * 60 + $scheduleMinute;
        
        // Trigger if within 1 minute of the target time
        if (abs($currentMinutes - $scheduleMinutes) > 1) {
            $skipped++;
            continue;
        }
        
        // Check if this schedule was already executed in the last 23 hours
        $recentCheck = $db->prepare("
            SELECT id FROM plug_actions_log 
            WHERE plug_id = ? AND trigger_type = 'time_of_day' 
            AND trigger_name = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 23 HOUR)
            ORDER BY created_at DESC LIMIT 1
        ");
        $recentCheck->execute([$schedule['plug_id'], $schedule['trigger_name']]);
        if ($recentCheck->fetch()) {
            log_msg("SKIPPED: {$schedule['trigger_name']} for {$schedule['plug_name']} - already executed today");
            $skipped++;
            continue;
        }
        
        // Execute the action
        $state = $schedule['action'] === 'turn_on';
        $result = $kasa->controlPlug($schedule['ip_address'], $state);
        
        if ($result['success']) {
            // Log the action
            $kasa->logPlugAction(
                $schedule['plug_id'],
                $schedule['action'],
                'time_of_day',
                $schedule['trigger_name'],
                'cron',
                null,
                null,
                $schedule['time_value']
            );
            
            log_msg("EXECUTED: {$schedule['trigger_name']} - {$schedule['plug_name']} {$schedule['action']} at {$schedule['time_value']}");
            $executed++;
        } else {
            log_msg("FAILED: {$schedule['trigger_name']} - {$schedule['plug_name']} - Error: {$result['error']}");
        }
    }
    
    log_msg("Done. Executed: $executed, Skipped: $skipped, Total schedules: " . count($schedules));
    
} catch (Exception $e) {
    log_msg("ERROR: " . $e->getMessage());
    exit(1);
}
