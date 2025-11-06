<?php
/**
 * Historical Data Manager for WHMCS Monitoring
 * Collects, stores, and analyzes monitoring data for trends and reporting
 */

class HistoricalDataManager {
    private $dbPath;
    private $logPath;
    
    public function __construct($basePath = __DIR__ . '/../') {
        $this->dbPath = $basePath . 'storage/historical_data.db';
        $this->logPath = $basePath . 'storage/logs/historical.log';
        
        $this->initializeDatabase();
    }
    
    /**
     * Initialize SQLite database for historical data
     */
    private function initializeDatabase() {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // System metrics table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                metric_type VARCHAR(50) NOT NULL,
                value REAL NOT NULL,
                unit VARCHAR(20) NULL,
                source VARCHAR(50) NOT NULL,
                metadata TEXT NULL
            )
        ");
        
        // WHMCS performance metrics
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS whmcs_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                response_time INTEGER NOT NULL,
                api_response_time INTEGER NULL,
                database_query_time INTEGER NULL,
                active_users INTEGER NULL,
                pending_orders INTEGER NULL,
                open_tickets INTEGER NULL,
                overdue_invoices INTEGER NULL,
                ssl_days_remaining INTEGER NULL,
                metadata TEXT NULL
            )
        ");
        
        // Event counters (for trend analysis)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS event_counters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                hour INTEGER NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                count INTEGER DEFAULT 1,
                severity INTEGER DEFAULT 3,
                UNIQUE(date, hour, event_type)
            )
        ");
        
        // Performance baselines
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS performance_baselines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric_name VARCHAR(100) NOT NULL UNIQUE,
                baseline_value REAL NOT NULL,
                threshold_warning REAL NOT NULL,
                threshold_critical REAL NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                samples_count INTEGER DEFAULT 0
            )
        ");
        
        // Availability tracking
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS availability_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                service_name VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL,
                response_time INTEGER NULL,
                error_message TEXT NULL,
                duration_minutes INTEGER NULL
            )
        ");
        
        // Create indexes for better performance
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_system_metrics_timestamp ON system_metrics(timestamp)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_system_metrics_type ON system_metrics(metric_type)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whmcs_metrics_timestamp ON whmcs_metrics(timestamp)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_event_counters_date ON event_counters(date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_availability_timestamp ON availability_log(timestamp)");
        
        $this->initializeBaselines($pdo);
    }
    
    /**
     * Initialize performance baselines
     */
    private function initializeBaselines($pdo) {
        $baselines = [
            ['cpu_usage', 50.0, 80.0, 95.0],
            ['memory_usage', 60.0, 85.0, 95.0],
            ['disk_usage', 50.0, 80.0, 90.0],
            ['response_time_ms', 1000.0, 3000.0, 5000.0],
            ['db_query_time_ms', 100.0, 500.0, 1000.0],
            ['api_response_time_ms', 500.0, 2000.0, 5000.0],
        ];
        
        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO performance_baselines 
            (metric_name, baseline_value, threshold_warning, threshold_critical)
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($baselines as $baseline) {
            $stmt->execute($baseline);
        }
    }
    
    /**
     * Record system metric
     */
    public function recordSystemMetric($metricType, $value, $unit = null, $source = 'system', $metadata = []) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            INSERT INTO system_metrics (metric_type, value, unit, source, metadata)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $metricType,
            $value,
            $unit,
            $source,
            json_encode($metadata)
        ]);
        
        // Check against baseline and create alert if threshold exceeded
        $this->checkThreshold($metricType, $value);
        
        return $result;
    }
    
    /**
     * Record WHMCS performance metrics
     */
    public function recordWHMCSMetrics($metrics) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            INSERT INTO whmcs_metrics (
                response_time, api_response_time, database_query_time, 
                active_users, pending_orders, open_tickets, 
                overdue_invoices, ssl_days_remaining, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $metrics['response_time'] ?? null,
            $metrics['api_response_time'] ?? null,
            $metrics['database_query_time'] ?? null,
            $metrics['active_users'] ?? null,
            $metrics['pending_orders'] ?? null,
            $metrics['open_tickets'] ?? null,
            $metrics['overdue_invoices'] ?? null,
            $metrics['ssl_days_remaining'] ?? null,
            json_encode($metrics['metadata'] ?? [])
        ]);
        
        // Check thresholds for key metrics
        if (isset($metrics['response_time'])) {
            $this->checkThreshold('response_time_ms', $metrics['response_time']);
        }
        
        if (isset($metrics['database_query_time'])) {
            $this->checkThreshold('db_query_time_ms', $metrics['database_query_time']);
        }
        
        return $result;
    }
    
    /**
     * Record event occurrence for trend analysis
     */
    public function recordEvent($eventType, $severity = 3, $count = 1) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $date = date('Y-m-d');
        $hour = (int)date('H');
        
        $stmt = $pdo->prepare("
            INSERT INTO event_counters (date, hour, event_type, count, severity)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(date, hour, event_type) DO UPDATE SET
            count = count + ?,
            severity = MAX(severity, ?)
        ");
        
        return $stmt->execute([$date, $hour, $eventType, $count, $severity, $count, $severity]);
    }
    
    /**
     * Record service availability
     */
    public function recordAvailability($serviceName, $status, $responseTime = null, $errorMessage = null) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        // Calculate downtime duration if service is back up
        $duration = null;
        if ($status === 'up') {
            $stmt = $pdo->prepare("
                SELECT timestamp FROM availability_log 
                WHERE service_name = ? AND status = 'down' 
                ORDER BY timestamp DESC LIMIT 1
            ");
            $stmt->execute([$serviceName]);
            $lastDown = $stmt->fetchColumn();
            
            if ($lastDown) {
                $downTime = strtotime($lastDown);
                $duration = round((time() - $downTime) / 60, 2); // minutes
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO availability_log (service_name, status, response_time, error_message, duration_minutes)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([$serviceName, $status, $responseTime, $errorMessage, $duration]);
    }
    
    /**
     * Get trend data for a specific metric
     */
    public function getTrendData($metricType, $hours = 24) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $stmt = $pdo->prepare("
            SELECT 
                datetime(timestamp) as timestamp,
                value,
                unit
            FROM system_metrics 
            WHERE metric_type = ? 
            AND timestamp >= ?
            ORDER BY timestamp ASC
        ");
        
        $stmt->execute([$metricType, $since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get WHMCS performance trends
     */
    public function getWHMCSTrends($hours = 24) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $stmt = $pdo->prepare("
            SELECT * FROM whmcs_metrics 
            WHERE timestamp >= ?
            ORDER BY timestamp ASC
        ");
        
        $stmt->execute([$since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get event frequency analysis
     */
    public function getEventAnalysis($days = 7) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d', time() - ($days * 86400));
        
        $stmt = $pdo->prepare("
            SELECT 
                event_type,
                SUM(count) as total_count,
                AVG(count) as avg_hourly,
                MAX(severity) as max_severity,
                COUNT(DISTINCT date) as active_days
            FROM event_counters 
            WHERE date >= ?
            GROUP BY event_type
            ORDER BY total_count DESC
        ");
        
        $stmt->execute([$since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calculate availability percentage
     */
    public function getAvailabilityStats($serviceName, $hours = 24) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        // Get total downtime in minutes
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN duration_minutes IS NOT NULL THEN duration_minutes ELSE 0 END) as total_downtime,
                COUNT(CASE WHEN status = 'down' THEN 1 END) as outages,
                AVG(CASE WHEN status = 'up' AND response_time IS NOT NULL THEN response_time END) as avg_response_time
            FROM availability_log 
            WHERE service_name = ? AND timestamp >= ?
        ");
        
        $stmt->execute([$serviceName, $since]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalMinutes = $hours * 60;
        $uptime = $totalMinutes - ($result['total_downtime'] ?? 0);
        $availability = $totalMinutes > 0 ? ($uptime / $totalMinutes) * 100 : 100;
        
        return [
            'availability_percentage' => round($availability, 3),
            'uptime_minutes' => $uptime,
            'downtime_minutes' => $result['total_downtime'] ?? 0,
            'outage_count' => $result['outages'] ?? 0,
            'avg_response_time' => round($result['avg_response_time'] ?? 0, 2)
        ];
    }
    
    /**
     * Get performance summary
     */
    public function getPerformanceSummary($hours = 24) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        // System metrics summary
        $stmt = $pdo->prepare("
            SELECT 
                metric_type,
                AVG(value) as avg_value,
                MIN(value) as min_value,
                MAX(value) as max_value,
                COUNT(*) as sample_count,
                unit
            FROM system_metrics 
            WHERE timestamp >= ?
            GROUP BY metric_type, unit
        ");
        
        $stmt->execute([$since]);
        $systemMetrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // WHMCS metrics summary
        $stmt = $pdo->prepare("
            SELECT 
                AVG(response_time) as avg_response_time,
                MIN(response_time) as min_response_time,
                MAX(response_time) as max_response_time,
                AVG(database_query_time) as avg_db_time,
                MAX(database_query_time) as max_db_time,
                AVG(open_tickets) as avg_open_tickets,
                MAX(open_tickets) as max_open_tickets
            FROM whmcs_metrics 
            WHERE timestamp >= ?
        ");
        
        $stmt->execute([$since]);
        $whmcsMetrics = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'period_hours' => $hours,
            'system_metrics' => $systemMetrics,
            'whmcs_metrics' => $whmcsMetrics,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Check if value exceeds baseline thresholds
     */
    private function checkThreshold($metricType, $value) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $stmt = $pdo->prepare("
            SELECT * FROM performance_baselines 
            WHERE metric_name = ?
        ");
        
        $stmt->execute([$metricType]);
        $baseline = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$baseline) {
            return;
        }
        
        // Create alert if threshold exceeded
        if ($value >= $baseline['threshold_critical']) {
            $this->createThresholdAlert($metricType, $value, 'critical', $baseline);
        } elseif ($value >= $baseline['threshold_warning']) {
            $this->createThresholdAlert($metricType, $value, 'warning', $baseline);
        }
        
        // Update baseline if needed (simple learning algorithm)
        $this->updateBaseline($metricType, $value);
    }
    
    /**
     * Create threshold alert
     */
    private function createThresholdAlert($metricType, $value, $level, $baseline) {
        // Only create alert if AlertManager is available
        if (class_exists('AlertManager')) {
            try {
                $alertManager = new AlertManager(__DIR__ . '/../');
                
                $severity = $level === 'critical' ? 5 : 4;
                $title = ucfirst($level) . " Threshold Exceeded: " . ucfirst(str_replace('_', ' ', $metricType));
                $message = "Metric '$metricType' has exceeded the $level threshold.\n\n";
                $message .= "Current value: $value\n";
                $message .= "Threshold: " . $baseline["threshold_$level"] . "\n";
                $message .= "Baseline: " . $baseline['baseline_value'];
                
                $alertManager->createAlert(
                    $title,
                    $message,
                    $severity,
                    'performance',
                    [
                        'metric_type' => $metricType,
                        'value' => $value,
                        'threshold' => $baseline["threshold_$level"],
                        'level' => $level
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to create threshold alert: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Update baseline with exponential moving average
     */
    private function updateBaseline($metricType, $value) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        // Simple exponential moving average with alpha = 0.1
        $stmt = $pdo->prepare("
            UPDATE performance_baselines 
            SET 
                baseline_value = baseline_value * 0.9 + ? * 0.1,
                samples_count = samples_count + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE metric_name = ?
        ");
        
        $stmt->execute([$value, $metricType]);
    }
    
    /**
     * Cleanup old data
     */
    public function cleanupOldData($daysToKeep = 90) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));
        
        $tables = [
            'system_metrics' => 'timestamp',
            'whmcs_metrics' => 'timestamp', 
            'availability_log' => 'timestamp'
        ];
        
        $deletedCounts = [];
        
        foreach ($tables as $table => $dateColumn) {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE $dateColumn < ?");
            $stmt->execute([$cutoffDate]);
            $deletedCounts[$table] = $stmt->rowCount();
        }
        
        // Clean event counters (keep aggregated data longer)
        $eventCutoff = date('Y-m-d', time() - (180 * 86400)); // 6 months
        $stmt = $pdo->prepare("DELETE FROM event_counters WHERE date < ?");
        $stmt->execute([$eventCutoff]);
        $deletedCounts['event_counters'] = $stmt->rowCount();
        
        $this->logMessage("Cleanup completed: " . json_encode($deletedCounts));
        
        return $deletedCounts;
    }
    
    /**
     * Log message to file
     */
    private function logMessage($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Export data to CSV
     */
    public function exportToCSV($table, $hours = 24, $outputPath = null) {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE timestamp >= ? ORDER BY timestamp");
        $stmt->execute([$since]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($data)) {
            return false;
        }
        
        $outputPath = $outputPath ?: __DIR__ . "/../storage/exports/{$table}_" . date('Y-m-d_H-i-s') . '.csv';
        
        // Ensure export directory exists
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $fp = fopen($outputPath, 'w');
        
        // Write header
        fputcsv($fp, array_keys($data[0]));
        
        // Write data
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }
        
        fclose($fp);
        
        return $outputPath;
    }
}
?>
