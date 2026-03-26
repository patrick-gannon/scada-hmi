<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/kasa_control.php';

/**
 * Check and execute time-based triggers
 * This should be run every minute via cron: * * * * * php /path/to/time_triggers.php
 */
function checkTimeBasedTriggers(): array {
    $db = get_db();
    $kasa = new KasaController();
    $executed = [];
    
    // Get all active time-based triggers
    $stmt = $db->prepare("
        SELECT pt.*, kp.ip_address, kp.display_name
        FROM plug_triggers pt
        JOIN kasa_plugs kp ON pt.plug_id = kp.plug_id
        WHERE pt.is_active = 1 
        AND kp.is_active = 1
        AND pt.trigger_type = 'time_of_day'
    ");
    
    $stmt->execute();
    $triggers = $stmt->fetchAll();
    
    $currentTime = date('H:i');
    $currentMinutes = (int)date('H') * 60 + (int)date('i');
    
    foreach ($triggers as $trigger) {
        if (!$trigger['time_value']) continue;
        
        $triggerMinutes = (int)date('H', strtotime($trigger['time_value'])) * 60 + (int)date('i', strtotime($trigger['time_value']));
        
        // Check if current time is within 1 minute of trigger time
        if (abs($currentMinutes - $triggerMinutes) <= 1) {
            // Check if this trigger was already executed in the last 2 hours
            $recentCheck = $db->prepare("
                SELECT id FROM plug_actions_log 
                WHERE plug_id = ? AND trigger_type = 'time_of_day' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                ORDER BY created_at DESC LIMIT 1
            ");
            $recentCheck->execute([$trigger['plug_id']]);
            
            if (!$recentCheck->fetch()) {
                // Execute the trigger
                $result = $kasa->controlPlug($trigger['ip_address'], $trigger['action'] === 'turn_on');
                
                if ($result['success']) {
                    $kasa->logPlugAction(
                        $trigger['plug_id'],
                        $trigger['action'],
                        'time_of_day',
                        'system',
                        null,
                        null,
                        $trigger['time_value']
                    );
                    
                    $executed[] = [
                        'plug' => $trigger['display_name'],
                        'action' => $trigger['action'],
                        'time' => $trigger['time_value']
                    ];
                }
            }
        }
    }
    
    return $executed;
}

// Allow running as a CLI cron job
if (PHP_SAPI === 'cli') {
    $results = checkTimeBasedTriggers();
    if (count($results) > 0) {
        foreach ($results as $result) {
            echo "Time trigger executed: {$result['plug']} -> {$result['action']} at {$result['time']}\n";
        }
    } else {
        echo "No time triggers executed.\n";
    }
}
