<?php
/**
 * Alert Management System for WHMCS Monitoring
 * Handles alert acknowledgment, escalation, and on-call rotation
 */

class AlertManager {
    private $dbPath;
    private $configPath;
    private $logPath;
    
    public function __construct($basePath = __DIR__ . '/../') {
        $this->dbPath = $basePath . 'storage/alerts.db';
        $this->configPath = $basePath . 'config/alert_config.json';
        $this->logPath = $basePath . 'storage/logs/alerts.log';
        
        $this->initializeDatabase();
    }
    
    /**
     * Initialize SQLite database for alert management
     */
    private function initializeDatabase() {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create tables if they don't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_id VARCHAR(64) UNIQUE NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                severity INTEGER DEFAULT 3,
                source VARCHAR(50) NOT NULL,
                status VARCHAR(20) DEFAULT 'open',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                acknowledged_at DATETIME NULL,
                acknowledged_by VARCHAR(100) NULL,
                resolved_at DATETIME NULL,
                resolved_by VARCHAR(100) NULL,
                escalation_level INTEGER DEFAULT 0,
                next_escalation DATETIME NULL,
                metadata TEXT NULL
            )
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS alert_actions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_id VARCHAR(64) NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                action_by VARCHAR(100) NOT NULL,
                action_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                notes TEXT NULL,
                FOREIGN KEY (alert_id) REFERENCES alerts (alert_id)
            )
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS escalation_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                severity INTEGER NOT NULL,
                level INTEGER NOT NULL,
                delay_minutes INTEGER NOT NULL,
                notify_method VARCHAR(50) NOT NULL,
                notify_target VARCHAR(255) NOT NULL,
                active INTEGER DEFAULT 1
            )
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS on_call_schedule (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name VARCHAR(100) NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                user_phone VARCHAR(20) NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                days_of_week VARCHAR(20) NOT NULL,
                priority INTEGER DEFAULT 1,
                active INTEGER DEFAULT 1
            )
        ");
        
        // Create indexes for better performance
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_status ON alerts(status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_severity ON alerts(severity)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_alerts_created ON alerts(created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_escalation ON alerts(next_escalation)");
        
        $this->initializeDefaultData($pdo);
    }
    
    /**
     * Initialize default escalation rules and on-call schedule
     */
    private function initializeDefaultData($pdo) {
        // Check if escalation rules exist
        $stmt = $pdo->query("SELECT COUNT(*) FROM escalation_rules");
        if ($stmt->fetchColumn() == 0) {
            // Default escalation rules
            $rules = [
                // Critical alerts (severity 5)
                [5, 0, 0, 'ntfy', 'whmcs-critical'],      // Immediate
                [5, 1, 15, 'email', 'admin@domain.com'],   // 15 min
                [5, 2, 30, 'sms', '+1234567890'],         // 30 min
                
                // High priority alerts (severity 4)
                [4, 0, 5, 'ntfy', 'whmcs-alerts'],        // 5 min
                [4, 1, 30, 'email', 'admin@domain.com'],   // 30 min
                [4, 2, 60, 'sms', '+1234567890'],         // 1 hour
                
                // Medium priority alerts (severity 3)
                [3, 0, 0, 'ntfy', 'whmcs-alerts'],        // Immediate
                [3, 1, 60, 'email', 'admin@domain.com'],   // 1 hour
                
                // Low priority alerts (severity 1-2)
                [2, 0, 0, 'ntfy', 'whmcs-alerts'],        // Immediate
                [1, 0, 0, 'ntfy', 'whmcs-alerts'],        // Immediate
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO escalation_rules (severity, level, delay_minutes, notify_method, notify_target)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($rules as $rule) {
                $stmt->execute($rule);
            }
        }
        
        // Check if on-call schedule exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM on_call_schedule");
        if ($stmt->fetchColumn() == 0) {
            // Default on-call schedule
            $schedule = [
                ['Primary Admin', 'admin@domain.com', '+1234567890', '00:00:00', '23:59:59', '1,2,3,4,5,6,7', 1],
                ['Backup Admin', 'backup@domain.com', '+0987654321', '18:00:00', '08:00:00', '6,7', 2],
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO on_call_schedule (user_name, user_email, user_phone, start_time, end_time, days_of_week, priority)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($schedule as $person) {
                $stmt->execute($person);
            }
        }
    }
    
    /**
     * Create a new alert
     */
    public function createAlert($title, $message, $severity = 3, $source = 'whmcs', $metadata = []) {
        $alertId = $this->generateAlertId($title, $source);
        
        // Check if alert already exists (deduplication)
        if ($this->alertExists($alertId)) {
            $this->logAction("Duplicate alert suppressed: $alertId");
            return $alertId;
        }
        
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        // Calculate next escalation time
        $nextEscalation = $this->calculateNextEscalation($severity, 0);
        
        $stmt = $pdo->prepare("
            INSERT INTO alerts (alert_id, title, message, severity, source, next_escalation, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $alertId,
            $title,
            $message,
            $severity,
            $source,
            $nextEscalation,
            json_encode($metadata)
        ]);
        
        $this->logAction("Alert created: $alertId - $title (severity: $severity)");
        
        // Send initial notification
        $this->sendEscalationNotification($alertId, 0);
        
        return $alertId;
    }
    
    /**
     * Acknowledge an alert
     */
    public function acknowledgeAlert($alertId, $acknowledgedBy, $notes = '') {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        // Update alert
        $stmt = $pdo->prepare("
            UPDATE alerts 
            SET status = 'acknowledged', 
                acknowledged_at = CURRENT_TIMESTAMP, 
                acknowledged_by = ?,
                next_escalation = NULL
            WHERE alert_id = ? AND status = 'open'
        ");
        
        $result = $stmt->execute([$acknowledgedBy, $alertId]);
        
        if ($stmt->rowCount() > 0) {
            // Log action
            $this->logAction("Alert acknowledged: $alertId by $acknowledgedBy");
            
            // Record action
            $this->recordAction($alertId, 'acknowledge', $acknowledgedBy, $notes);
            
            // Send acknowledgment notification
            $this->sendAckNotification($alertId, $acknowledgedBy);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Resolve an alert
     */
    public function resolveAlert($alertId, $resolvedBy, $notes = '') {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            UPDATE alerts 
            SET status = 'resolved', 
                resolved_at = CURRENT_TIMESTAMP, 
                resolved_by = ?,
                next_escalation = NULL
            WHERE alert_id = ? AND status IN ('open', 'acknowledged')
        ");
        
        $result = $stmt->execute([$resolvedBy, $alertId]);
        
        if ($stmt->rowCount() > 0) {
            $this->logAction("Alert resolved: $alertId by $resolvedBy");
            $this->recordAction($alertId, 'resolve', $resolvedBy, $notes);
            $this->sendResolveNotification($alertId, $resolvedBy);
            return true;
        }
        
        return false;
    }
    
    /**
     * Process escalations (run via cron)
     */
    public function processEscalations() {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->query("
            SELECT alert_id, title, severity, escalation_level 
            FROM alerts 
            WHERE status = 'open' 
            AND next_escalation IS NOT NULL 
            AND next_escalation <= CURRENT_TIMESTAMP
            ORDER BY severity DESC, created_at ASC
        ");
        
        $escalatedCount = 0;
        
        while ($alert = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $newLevel = $alert['escalation_level'] + 1;
            
            // Send escalation notification
            if ($this->sendEscalationNotification($alert['alert_id'], $newLevel)) {
                // Update alert with new escalation level and next escalation time
                $nextEscalation = $this->calculateNextEscalation($alert['severity'], $newLevel);
                
                $updateStmt = $pdo->prepare("
                    UPDATE alerts 
                    SET escalation_level = ?, next_escalation = ?
                    WHERE alert_id = ?
                ");
                
                $updateStmt->execute([$newLevel, $nextEscalation, $alert['alert_id']]);
                
                $this->logAction("Alert escalated: {$alert['alert_id']} to level $newLevel");
                $this->recordAction($alert['alert_id'], 'escalate', 'system', "Escalated to level $newLevel");
                
                $escalatedCount++;
            }
        }
        
        if ($escalatedCount > 0) {
            $this->logAction("Processed $escalatedCount escalations");
        }
        
        return $escalatedCount;
    }
    
    /**
     * Get open alerts
     */
    public function getOpenAlerts($limit = 50) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            SELECT * FROM alerts 
            WHERE status IN ('open', 'acknowledged')
            ORDER BY severity DESC, created_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get alert details with actions
     */
    public function getAlertDetails($alertId) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        // Get alert
        $stmt = $pdo->prepare("SELECT * FROM alerts WHERE alert_id = ?");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$alert) {
            return null;
        }
        
        // Get actions
        $stmt = $pdo->prepare("
            SELECT * FROM alert_actions 
            WHERE alert_id = ? 
            ORDER BY action_at DESC
        ");
        $stmt->execute([$alertId]);
        $alert['actions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $alert;
    }
    
    /**
     * Get alert statistics
     */
    public function getAlertStats($days = 7) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $stats = [];
        
        // Total alerts by status
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count 
            FROM alerts 
            WHERE created_at >= ? 
            GROUP BY status
        ");
        $stmt->execute([$since]);
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Total alerts by severity
        $stmt = $pdo->prepare("
            SELECT severity, COUNT(*) as count 
            FROM alerts 
            WHERE created_at >= ? 
            GROUP BY severity
        ");
        $stmt->execute([$since]);
        $stats['by_severity'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Average resolution time
        $stmt = $pdo->prepare("
            SELECT AVG(
                (julianday(resolved_at) - julianday(created_at)) * 24 * 60
            ) as avg_resolution_minutes
            FROM alerts 
            WHERE status = 'resolved' 
            AND created_at >= ?
        ");
        $stmt->execute([$since]);
        $stats['avg_resolution_minutes'] = round($stmt->fetchColumn(), 2);
        
        // Escalation rate
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN escalation_level > 0 THEN 1 ELSE 0 END) as escalated
            FROM alerts 
            WHERE created_at >= ?
        ");
        $stmt->execute([$since]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['escalation_rate'] = $result['total'] > 0 
            ? round(($result['escalated'] / $result['total']) * 100, 2) 
            : 0;
        
        return $stats;
    }
    
    /**
     * Generate unique alert ID
     */
    private function generateAlertId($title, $source) {
        return substr(hash('sha256', $source . ':' . $title . ':' . date('Y-m-d')), 0, 16);
    }
    
    /**
     * Check if alert already exists today
     */
    private function alertExists($alertId) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM alerts 
            WHERE alert_id = ? 
            AND date(created_at) = date('now')
            AND status != 'resolved'
        ");
        $stmt->execute([$alertId]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Calculate next escalation time
     */
    private function calculateNextEscalation($severity, $currentLevel) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            SELECT delay_minutes FROM escalation_rules 
            WHERE severity = ? AND level = ? AND active = 1
            ORDER BY level LIMIT 1
        ");
        
        $stmt->execute([$severity, $currentLevel + 1]);
        $delay = $stmt->fetchColumn();
        
        if ($delay === false) {
            return null; // No more escalation levels
        }
        
        return date('Y-m-d H:i:s', time() + ($delay * 60));
    }
    
    /**
     * Send escalation notification
     */
    private function sendEscalationNotification($alertId, $level) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        // Get alert details
        $stmt = $pdo->prepare("SELECT * FROM alerts WHERE alert_id = ?");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$alert) return false;
        
        // Get escalation rule
        $stmt = $pdo->prepare("
            SELECT * FROM escalation_rules 
            WHERE severity = ? AND level = ? AND active = 1
        ");
        $stmt->execute([$alert['severity'], $level]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rule) return false;
        
        $levelText = $level == 0 ? 'NEW' : "ESCALATED (Level $level)";
        $title = "🚨 $levelText ALERT: {$alert['title']}";
        $message = "{$alert['message']}\n\nSeverity: {$alert['severity']}\nAlert ID: $alertId";
        
        switch ($rule['notify_method']) {
            case 'ntfy':
                return $this->sendNtfyNotification($rule['notify_target'], $title, $message, $alert['severity']);
            
            case 'email':
                return $this->sendEmailNotification($rule['notify_target'], $title, $message);
            
            case 'sms':
                return $this->sendSMSNotification($rule['notify_target'], $title);
            
            default:
                return false;
        }
    }
    
    /**
     * Send acknowledgment notification
     */
    private function sendAckNotification($alertId, $acknowledgedBy) {
        $title = "✅ Alert Acknowledged: $alertId";
        $message = "Alert has been acknowledged by $acknowledgedBy";
        
        return $this->sendNtfyNotification('whmcs-alerts', $title, $message, 1);
    }
    
    /**
     * Send resolution notification
     */
    private function sendResolveNotification($alertId, $resolvedBy) {
        $title = "✅ Alert Resolved: $alertId";
        $message = "Alert has been resolved by $resolvedBy";
        
        return $this->sendNtfyNotification('whmcs-alerts', $title, $message, 1);
    }
    
    /**
     * Send ntfy notification
     */
    private function sendNtfyNotification($topic, $title, $message, $priority = 3) {
        // Use the existing notification system
        if (function_exists('sendDualNotification')) {
            return sendDualNotification($title, $message, $priority, 'alert');
        }
        
        return false;
    }
    
    /**
     * Send email notification
     */
    private function sendEmailNotification($email, $subject, $body) {
        $headers = "From: WHMCS Alert Manager <alerts@yourdomain.com>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Priority: 1\r\n"; // High priority
        
        $htmlBody = "<h3>$subject</h3><p>" . nl2br(htmlspecialchars($body)) . "</p>";
        
        return mail($email, "[ALERT] $subject", $htmlBody, $headers);
    }
    
    /**
     * Send SMS notification (placeholder - integrate with your SMS provider)
     */
    private function sendSMSNotification($phone, $message) {
        // TODO: Integrate with SMS provider (Twilio, AWS SNS, etc.)
        $this->logAction("SMS notification would be sent to $phone: $message");
        return true;
    }
    
    /**
     * Record action in database
     */
    private function recordAction($alertId, $actionType, $actionBy, $notes = '') {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            INSERT INTO alert_actions (alert_id, action_type, action_by, notes)
            VALUES (?, ?, ?, ?)
        ");
        
        return $stmt->execute([$alertId, $actionType, $actionBy, $notes]);
    }
    
    /**
     * Log action to file
     */
    private function logAction($message) {
        $logEntry = date('Y-m-d H:i:s') . " - $message" . PHP_EOL;
        file_put_contents($this->logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Clean up old alerts
     */
    public function cleanupOldAlerts($daysToKeep = 30) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysToKeep days"));
        
        // Delete old resolved alerts
        $stmt = $pdo->prepare("
            DELETE FROM alerts 
            WHERE status = 'resolved' 
            AND resolved_at < ?
        ");
        $stmt->execute([$cutoffDate]);
        $deletedAlerts = $stmt->rowCount();
        
        // Delete orphaned actions
        $stmt = $pdo->exec("
            DELETE FROM alert_actions 
            WHERE alert_id NOT IN (SELECT alert_id FROM alerts)
        ");
        $deletedActions = $stmt->rowCount();
        
        $this->logAction("Cleanup: Deleted $deletedAlerts old alerts and $deletedActions orphaned actions");
        
        return ['alerts' => $deletedAlerts, 'actions' => $deletedActions];
    }
}
?>
