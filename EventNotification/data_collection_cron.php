<?php
/**
 * Data Collection Cron Job
 * Collects system metrics and stores them for historical analysis
 * Run this script every 5 minutes for optimal data collection
 * Usage: php data_collection_cron.php
 */

// Ensure script runs only in CLI mode
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/classes/HistoricalDataManager.php';

// Configuration
$logFile = __DIR__ . '/storage/logs/data_collection.log';
$lockFile = __DIR__ . '/storage/data_collection.lock';

/**
 * Log message with timestamp
 */
function logMessage($message, $logFile) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    if (php_sapi_name() === 'cli') {
        echo $logEntry;
    }
}

/**
 * Acquire lock to prevent multiple instances
 */
function acquireLock($lockFile) {
    if (file_exists($lockFile)) {
        $pid = (int)file_get_contents($lockFile);
        
        if ($pid > 0 && file_exists("/proc/$pid")) {
            return false;
        }
        
        unlink($lockFile);
    }
    
    file_put_contents($lockFile, getmypid());
    return true;
}

/**
 * Release lock
 */
function releaseLock($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

/**
 * Collect system metrics
 */
function collectSystemMetrics($historyManager) {
    $metrics = [];
    
    // CPU usage
    $cpuUsage = getCPUUsage();
    if ($cpuUsage !== false) {
        $historyManager->recordSystemMetric('cpu_usage', $cpuUsage, '%', 'system');
        $metrics['cpu_usage'] = $cpuUsage;
    }
    
    // Memory usage
    $memUsage = getMemoryUsage();
    if ($memUsage !== false) {
        $historyManager->recordSystemMetric('memory_usage', $memUsage['percentage'], '%', 'system');
        $historyManager->recordSystemMetric('memory_used', $memUsage['used'], 'MB', 'system');
        $historyManager->recordSystemMetric('memory_free', $memUsage['free'], 'MB', 'system');
        $metrics['memory_usage'] = $memUsage['percentage'];
    }
    
    // Disk usage
    $diskUsage = getDiskUsage('/');
    if ($diskUsage !== false) {
        $historyManager->recordSystemMetric('disk_usage', $diskUsage['percentage'], '%', 'system');
        $historyManager->recordSystemMetric('disk_used', $diskUsage['used'], 'GB', 'system');
        $historyManager->recordSystemMetric('disk_free', $diskUsage['free'], 'GB', 'system');
        $metrics['disk_usage'] = $diskUsage['percentage'];
    }
    
    // Load average
    $loadAvg = getLoadAverage();
    if ($loadAvg !== false) {
        $historyManager->recordSystemMetric('load_1min', $loadAvg['1min'], '', 'system');
        $historyManager->recordSystemMetric('load_5min', $loadAvg['5min'], '', 'system');
        $historyManager->recordSystemMetric('load_15min', $loadAvg['15min'], '', 'system');
        $metrics['load_avg'] = $loadAvg['1min'];
    }
    
    // Network connections
    $netConnections = getNetworkConnections();
    if ($netConnections !== false) {
        $historyManager->recordSystemMetric('network_connections', $netConnections, 'count', 'system');
        $metrics['network_connections'] = $netConnections;
    }
    
    // Process count
    $processCount = getProcessCount();
    if ($processCount !== false) {
        $historyManager->recordSystemMetric('process_count', $processCount, 'count', 'system');
        $metrics['process_count'] = $processCount;
    }
    
    return $metrics;
}

/**
 * Collect WHMCS-specific metrics
 */
function collectWHMCSMetrics($historyManager) {
    $whmcsMetrics = [];
    
    // Test WHMCS website response time
    $responseTime = testWHMCSResponse();
    if ($responseTime !== false) {
        $whmcsMetrics['response_time'] = $responseTime;
    }
    
    // Test API response time
    $apiResponseTime = testWHMCSAPI();
    if ($apiResponseTime !== false) {
        $whmcsMetrics['api_response_time'] = $apiResponseTime;
    }
    
    // Database performance test
    $dbTime = testDatabasePerformance();
    if ($dbTime !== false) {
        $whmcsMetrics['database_query_time'] = $dbTime;
    }
    
    // Get WHMCS statistics if API is available
    $stats = getWHMCSStats();
    if ($stats) {
        $whmcsMetrics = array_merge($whmcsMetrics, $stats);
    }
    
    // Record all metrics
    if (!empty($whmcsMetrics)) {
        $historyManager->recordWHMCSMetrics($whmcsMetrics);
    }
    
    return $whmcsMetrics;
}

/**
 * Check service availability
 */
function checkServiceAvailability($historyManager) {
    $services = [
        'nginx' => 80,
        'apache2' => 80,
        'mysql' => 3306,
        'mariadb' => 3306,
        'redis' => 6379,
        'memcached' => 11211
    ];
    
    foreach ($services as $service => $port) {
        $start = microtime(true);
        $status = 'down';
        $responseTime = null;
        $errorMessage = null;
        
        // Check if service is running
        $connection = @fsockopen('localhost', $port, $errno, $errstr, 5);
        
        if ($connection) {
            $status = 'up';
            $responseTime = round((microtime(true) - $start) * 1000, 2);
            fclose($connection);
        } else {
            $errorMessage = "$errno: $errstr";
        }
        
        $historyManager->recordAvailability($service, $status, $responseTime, $errorMessage);
    }
}

/**
 * Get CPU usage percentage
 */
function getCPUUsage() {
    if (PHP_OS_FAMILY === 'Linux') {
        $load = exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2}' | awk -F'%' '{print $1}'");
        if (is_numeric($load)) {
            return round((float)$load, 2);
        }
    }
    
    return false;
}

/**
 * Get memory usage
 */
function getMemoryUsage() {
    if (PHP_OS_FAMILY === 'Linux') {
        $free = shell_exec('free -m');
        if ($free) {
            $lines = explode("\n", $free);
            if (count($lines) >= 2) {
                preg_match_all('/\s+(\d+)/', $lines[1], $matches);
                if (count($matches[1]) >= 3) {
                    $total = (int)$matches[1][0];
                    $used = (int)$matches[1][1];
                    $free = (int)$matches[1][2];
                    
                    return [
                        'total' => $total,
                        'used' => $used,
                        'free' => $free,
                        'percentage' => round(($used / $total) * 100, 2)
                    ];
                }
            }
        }
    }
    
    return false;
}

/**
 * Get disk usage
 */
function getDiskUsage($path = '/') {
    $total = disk_total_space($path);
    $free = disk_free_space($path);
    
    if ($total && $free) {
        $used = $total - $free;
        
        return [
            'total' => round($total / (1024**3), 2), // GB
            'used' => round($used / (1024**3), 2),
            'free' => round($free / (1024**3), 2),
            'percentage' => round(($used / $total) * 100, 2)
        ];
    }
    
    return false;
}

/**
 * Get load average
 */
function getLoadAverage() {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load) {
            return [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
        }
    }
    
    return false;
}

/**
 * Get network connections count
 */
function getNetworkConnections() {
    if (PHP_OS_FAMILY === 'Linux') {
        $count = exec("netstat -tun | wc -l");
        if (is_numeric($count)) {
            return (int)$count - 2; // Subtract header lines
        }
    }
    
    return false;
}

/**
 * Get process count
 */
function getProcessCount() {
    if (PHP_OS_FAMILY === 'Linux') {
        $count = exec("ps aux | wc -l");
        if (is_numeric($count)) {
            return (int)$count - 1; // Subtract header line
        }
    }
    
    return false;
}

/**
 * Test WHMCS website response time
 */
function testWHMCSResponse() {
    $whmcsUrl = $_ENV['WHMCS_URL'] ?? 'https://localhost/whmcs';
    
    $start = microtime(true);
    $ch = curl_init($whmcsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'WHMCS-Monitor/DataCollector'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseTime = round((microtime(true) - $start) * 1000, 2);
    curl_close($ch);
    
    if ($response !== false && $httpCode == 200) {
        return $responseTime;
    }
    
    return false;
}

/**
 * Test WHMCS API response time
 */
function testWHMCSAPI() {
    $whmcsUrl = $_ENV['WHMCS_URL'] ?? 'https://localhost/whmcs';
    $apiId = $_ENV['WHMCS_API_ID'] ?? '';
    $apiSecret = $_ENV['WHMCS_API_SECRET'] ?? '';
    
    if (empty($apiId) || empty($apiSecret)) {
        return false;
    }
    
    $postfields = [
        'action' => 'GetStats',
        'identifier' => $apiId,
        'secret' => $apiSecret,
        'responsetype' => 'json',
    ];
    
    $start = microtime(true);
    $ch = curl_init($whmcsUrl . '/includes/api.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postfields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $responseTime = round((microtime(true) - $start) * 1000, 2);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['result']) && $data['result'] == 'success') {
        return $responseTime;
    }
    
    return false;
}

/**
 * Test database performance with simple query
 */
function testDatabasePerformance() {
    try {
        $start = microtime(true);
        $pdo = new PDO('mysql:host=localhost;dbname=information_schema', 'root', '');
        $stmt = $pdo->query('SELECT COUNT(*) FROM TABLES');
        $result = $stmt->fetchColumn();
        $queryTime = round((microtime(true) - $start) * 1000, 2);
        
        if ($result !== false) {
            return $queryTime;
        }
    } catch (Exception $e) {
        // Database connection failed
    }
    
    return false;
}

/**
 * Get WHMCS statistics via API
 */
function getWHMCSStats() {
    $whmcsUrl = $_ENV['WHMCS_URL'] ?? 'https://localhost/whmcs';
    $apiId = $_ENV['WHMCS_API_ID'] ?? '';
    $apiSecret = $_ENV['WHMCS_API_SECRET'] ?? '';
    
    if (empty($apiId) || empty($apiSecret)) {
        return null;
    }
    
    $postfields = [
        'action' => 'GetStats',
        'identifier' => $apiId,
        'secret' => $apiSecret,
        'responsetype' => 'json',
    ];
    
    $ch = curl_init($whmcsUrl . '/includes/api.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postfields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (isset($data['result']) && $data['result'] == 'success' && isset($data['stats'])) {
        return [
            'active_users' => $data['stats']['clients']['active'] ?? null,
            'pending_orders' => $data['stats']['orders']['pending'] ?? null,
            'open_tickets' => $data['stats']['tickets']['open'] ?? null,
            'overdue_invoices' => $data['stats']['invoices']['overdue'] ?? null,
            'metadata' => $data['stats']
        ];
    }
    
    return null;
}

/**
 * Main execution
 */
try {
    // Ensure storage directory exists
    $storageDir = dirname($logFile);
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    logMessage("Starting data collection", $logFile);
    
    // Acquire lock
    if (!acquireLock($lockFile)) {
        logMessage("Another instance is already running, exiting", $logFile);
        exit(0);
    }
    
    // Initialize HistoricalDataManager
    $historyManager = new HistoricalDataManager(__DIR__ . '/');
    
    $startTime = microtime(true);
    
    // Collect system metrics
    logMessage("Collecting system metrics", $logFile);
    $systemMetrics = collectSystemMetrics($historyManager);
    
    // Collect WHMCS metrics
    logMessage("Collecting WHMCS metrics", $logFile);
    $whmcsMetrics = collectWHMCSMetrics($historyManager);
    
    // Check service availability
    logMessage("Checking service availability", $logFile);
    checkServiceAvailability($historyManager);
    
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    
    $metricsCount = count($systemMetrics) + count($whmcsMetrics);
    logMessage("Collected $metricsCount metrics in {$executionTime}ms", $logFile);
    
    // Cleanup old data (run once daily at 3 AM)
    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');
    
    if ($currentHour === 3 && $currentMinute < 5) {
        logMessage("Running daily cleanup", $logFile);
        $cleanupResult = $historyManager->cleanupOldData(90); // Keep 90 days
        logMessage("Cleanup completed: " . json_encode($cleanupResult), $logFile);
    }
    
    logMessage("Data collection completed successfully", $logFile);
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage(), $logFile);
    
    // Try to create alert about data collection failure
    if (class_exists('AlertManager')) {
        try {
            $alertManager = new AlertManager(__DIR__ . '/');
            $alertManager->createAlert(
                "Data Collection Failed",
                "Historical data collection failed with error: " . $e->getMessage(),
                4,
                'system',
                ['error' => $e->getMessage()]
            );
        } catch (Exception $alertError) {
            logMessage("Failed to create alert: " . $alertError->getMessage(), $logFile);
        }
    }
    
    exit(1);
} finally {
    releaseLock($lockFile);
}

exit(0);
?>
