<?php
require_once __DIR__ . '/db.php';

class KasaController {
    private $db;
    private $pi_ip;
    
    public function __construct() {
        $this->db = get_db();
        // Get Pi IP from settings or use default
        $this->pi_ip = hmi_getenv('PI_IP_ADDRESS', '127.0.0.1');
        $this->pi_port = hmi_getenv('PI_PORT', '8081');
    }
    
    /**
     * Control a Kasa plug via Pi local controller
     */
    public function controlPlug($ipAddress, $state) {
        // Control via Pi local controller (tunnel or direct)
        $action = $state ? 'turn_on' : 'turn_off';
        $data = [
            'command' => 'control_plug',
            'plug_id' => $this->getPlugIdByIp($ipAddress),
            'state' => $state
        ];
        
        $ch = curl_init("http://{$this->pi_ip}:{$this->pi_port}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return $result ?: ['success' => false, 'error' => 'Invalid response'];
        } else {
            return ['success' => false, 'error' => 'Pi controller unavailable: HTTP ' . $http_code];
        }
    }
    
    /**
     * Direct plug control (fallback method)
     */
    private function controlPlugDirect($ipAddress, $state) {
        $action = $state ? 'on' : 'off';
        $cmd = "python3 -m kasa --host " . escapeshellarg($ipAddress) . " " . $action;
        
        $output = [];
        $return_code = 0;
        exec($cmd, $output, $return_code);
        
        if ($return_code === 0) {
            return ['success' => true, 'output' => implode("\n", $output)];
        } else {
            return ['success' => false, 'error' => implode("\n", $output)];
        }
    }
    
    /**
     * Get plug ID by IP address
     */
    private function getPlugIdByIp($ipAddress) {
        $stmt = $this->db->prepare("SELECT plug_id FROM kasa_plugs WHERE ip_address = ?");
        $stmt->execute([$ipAddress]);
        $result = $stmt->fetch();
        return $result ? $result['plug_id'] : null;
    }
    
    /**
     * Get plug status
     */
    public function getPlugStatus($ipAddress) {
        // Try via Pi local controller
        $plug_id = $this->getPlugIdByIp($ipAddress);
        if ($plug_id) {
            $data = ['command' => 'get_plug_status', 'plug_id' => $plug_id];
            
            $ch = curl_init("http://{$this->pi_ip}:{$this->pi_port}");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200) {
                $result = json_decode($response, true);
                if ($result && $result['success']) {
                    return $result;
                }
            }
        }
        
        return ['success' => false, 'error' => 'Pi controller unavailable'];
    }
    
    /**
     * Add a new plug to the system
     */
    public function addPlug($plugId, $displayName, $ipAddress, $location = '') {
        $stmt = $this->db->prepare("
            INSERT INTO kasa_plugs (plug_id, display_name, ip_address, location)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            ip_address = VALUES(ip_address),
            location = VALUES(location),
            updated_at = CURRENT_TIMESTAMP
        ");
        
        return $stmt->execute([$plugId, $displayName, $ipAddress, $location]);
    }
    
    /**
     * Get all plugs
     */
    public function getAllPlugs() {
        $stmt = $this->db->query("
            SELECT plug_id, display_name, ip_address, location, is_active, created_at, updated_at
            FROM kasa_plugs
            WHERE is_active = 1
            ORDER BY display_name
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Add a trigger for a plug
     */
    public function addTrigger($plugId, $triggerName, $triggerType, $nodeId = null, $thresholdValue = null, $timeValue = null, $action) {
        $stmt = $this->db->prepare("
            INSERT INTO plug_triggers (plug_id, trigger_name, trigger_type, node_id, threshold_value, time_value, action)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([$plugId, $triggerName, $triggerType, $nodeId, $thresholdValue, $timeValue, $action]);
    }
    
    /**
     * Get all triggers for a plug
     */
    public function getPlugTriggers($plugId) {
        $stmt = $this->db->prepare("
            SELECT id, trigger_name, trigger_type, node_id, threshold_value, time_value, action, is_active, created_at
            FROM plug_triggers
            WHERE plug_id = ? AND is_active = 1
            ORDER BY trigger_name
        ");
        
        $stmt->execute([$plugId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Log plug action
     */
    public function logPlugAction($plugId, $action, $triggerType, $triggeredBy = null, $nodeId = null, $sensorValue = null, $thresholdValue = null) {
        $stmt = $this->db->prepare("
            INSERT INTO plug_actions_log (plug_id, action, trigger_type, triggered_by, node_id, sensor_value, threshold_value)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([$plugId, $action, $triggerType, $triggeredBy, $nodeId, $sensorValue, $thresholdValue]);
    }
    
    /**
     * Check and execute automatic triggers based on sensor data and time
     */
    public function checkAutomaticTriggers($nodeId, $temperature, $humidity) {
        // Get all active automatic triggers for this node or global
        $stmt = $this->db->prepare("
            SELECT pt.*, kp.ip_address, kp.display_name
            FROM plug_triggers pt
            JOIN kasa_plugs kp ON pt.plug_id = kp.plug_id
            WHERE pt.is_active = 1 
            AND kp.is_active = 1
            AND pt.trigger_type != 'manual'
            AND (pt.node_id = ? OR pt.node_id IS NULL)
        ");
        
        $stmt->execute([$nodeId]);
        $triggers = $stmt->fetchAll();
        
        $currentTime = date('H:i');
        
        foreach ($triggers as $trigger) {
            $shouldTrigger = false;
            $sensorValue = null;
            $thresholdValue = null;
            
            switch ($trigger['trigger_type']) {
                case 'temp_high':
                    if ($temperature !== null && $temperature >= $trigger['threshold_value']) {
                        $shouldTrigger = true;
                        $sensorValue = $temperature;
                        $thresholdValue = $trigger['threshold_value'];
                    }
                    break;
                case 'temp_low':
                    if ($temperature !== null && $temperature <= $trigger['threshold_value']) {
                        $shouldTrigger = true;
                        $sensorValue = $temperature;
                        $thresholdValue = $trigger['threshold_value'];
                    }
                    break;
                case 'humidity_high':
                    if ($humidity !== null && $humidity >= $trigger['threshold_value']) {
                        $shouldTrigger = true;
                        $sensorValue = $humidity;
                        $thresholdValue = $trigger['threshold_value'];
                    }
                    break;
                case 'humidity_low':
                    if ($humidity !== null && $humidity <= $trigger['threshold_value']) {
                        $shouldTrigger = true;
                        $sensorValue = $humidity;
                        $thresholdValue = $trigger['threshold_value'];
                    }
                    break;
                case 'time_of_day':
                    // Check if current time matches trigger time (within 1 minute)
                    if ($trigger['time_value']) {
                        $triggerTime = date('H:i', strtotime($trigger['time_value']));
                        $currentMinutes = (int)date('H') * 60 + (int)date('i');
                        $triggerMinutes = (int)date('H', strtotime($trigger['time_value'])) * 60 + (int)date('i', strtotime($trigger['time_value']));
                        
                        // Trigger if within 1 minute of the target time
                        if (abs($currentMinutes - $triggerMinutes) <= 1) {
                            // Check if this trigger was already executed in the last 2 hours to avoid repeats
                            $recentCheck = $this->db->prepare("
                                SELECT id FROM plug_actions_log 
                                WHERE plug_id = ? AND trigger_type = 'time_of_day' 
                                AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                                ORDER BY created_at DESC LIMIT 1
                            ");
                            $recentCheck->execute([$trigger['plug_id']]);
                            if (!$recentCheck->fetch()) {
                                $shouldTrigger = true;
                                $thresholdValue = $trigger['time_value'];
                            }
                        }
                    }
                    break;
            }
            
            if ($shouldTrigger) {
                $result = $this->controlPlug($trigger['ip_address'], $trigger['action'] === 'turn_on');
                
                $this->logPlugAction(
                    $trigger['plug_id'],
                    $trigger['action'],
                    $trigger['trigger_type'],
                    'system',
                    $nodeId,
                    $sensorValue,
                    $thresholdValue
                );
            }
        }
    }
}
