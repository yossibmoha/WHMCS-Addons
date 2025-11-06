<?php
/**
 * System Status Overview
 * Quick health check and status summary for the complete monitoring system
 * Usage: php system_status.php
 */

// Ensure proper error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required classes
require_once __DIR__ . '/classes/AlertManager.php';
require_once __DIR__ . '/classes/HistoricalDataManager.php';

/**
 * ANSI color codes for terminal output
 */
class Colors {
    const RESET = "\033[0m";
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const PURPLE = "\033[35m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";
    const BOLD = "\033[1m";
}

/**
 * Print colored output
 */
function printStatus($status, $message, $details = '') {
    $color = $status ? Colors::GREEN : Colors::RED;
    $icon = $status ? '✅' : '❌';
    
    echo $color . $icon . ' ' . $message . Colors::RESET;
    if ($details) {
        echo ' ' . Colors::CYAN . '(' . $details . ')' . Colors::RESET;
    }
    echo "\n";
}

function printHeader($text) {
    echo "\n" . Colors::BOLD . Colors::BLUE . "=== $text ===" . Colors::RESET . "\n";
}

function printWarning($message) {
    echo Colors::YELLOW . '⚠️  ' . $message . Colors::RESET . "\n";
}

function printInfo($message) {
    echo Colors::CYAN . 'ℹ️  ' . $message . Colors::RESET . "\n";
}

/**
 * Check if a file exists and is readable
 */
function checkFile($filepath, $description) {
    $exists = file_exists($filepath);
    $readable = $exists && is_readable($filepath);
    printStatus($readable, "$description", $exists ? ($readable ? 'OK' : 'Not readable') : 'Missing');
    return $readable;
}

/**
 * Check database connectivity and table existence
 */
function checkDatabase($dbPath, $description) {
    try {
        if (!file_exists($dbPath)) {
            printStatus(false, "$description", 'Database file missing');
            return false;
        }
        
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Test a simple query
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 5");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        printStatus(true, "$description", count($tables) . ' tables found');
        return true;
    } catch (Exception $e) {
        printStatus(false, "$description", $e->getMessage());
        return false;
    }
}

/**
 * Test URL connectivity
 */
function testURL($url, $description, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'WHMCS-Monitor-StatusCheck'
    ]);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $responseTime = round((microtime(true) - $start) * 1000, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $success = $response !== false && $httpCode >= 200 && $httpCode < 400;
    $details = $success ? "{$responseTime}ms (HTTP $httpCode)" : ($error ?: "HTTP $httpCode");
    
    printStatus($success, "$description", $details);
    return $success;
}

/**
 * Main system status check
 */
function checkSystemStatus() {
    $basePath = __DIR__ . '/';
    $overallStatus = true;
    
    // Header
    echo Colors::BOLD . Colors::PURPLE . "\n";
    echo "🚀 WHMCS Monitoring System - Status Check\n";
    echo "=========================================\n" . Colors::RESET;
    echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n";
    
    // Core System Files
    printHeader("Core System Files");
    $coreFiles = [
        'classes/AlertManager.php' => 'Alert Manager Class',
        'classes/HistoricalDataManager.php' => 'Historical Data Manager',
        'api/alert_api.php' => 'Alert Management API',
        'api/historical_data_api.php' => 'Historical Data API',
        'includes/hooks/whmcs_notification_config.php' => 'Notification Config',
        'deploy.sh' => 'Deployment Script',
        'setup-ntfy-security.sh' => 'Security Setup Script'
    ];
    
    foreach ($coreFiles as $file => $desc) {
        $status = checkFile($basePath . $file, $desc);
        if (!$status) $overallStatus = false;
    }
    
    // WHMCS Hook Files
    printHeader("WHMCS Hook Files");
    $hookFiles = [
        'includes/hooks/whmcs_user_hooks.php' => 'User Event Hooks',
        'includes/hooks/whmcs_order_hooks.php' => 'Order Event Hooks',
        'includes/hooks/whmcs_server_hooks.php' => 'Server Event Hooks',
        'includes/hooks/whmcs_support_hooks.php' => 'Support Event Hooks',
        'includes/hooks/whmcs_error_hooks.php' => 'Error Event Hooks',
        'includes/hooks/whmcs_health_monitor.php' => 'Health Monitor Hooks',
        'includes/hooks/whmcs_performance_monitor.php' => 'Performance Monitor Hooks'
    ];
    
    foreach ($hookFiles as $file => $desc) {
        checkFile($basePath . $file, $desc);
    }
    
    // Monitoring Scripts
    printHeader("Monitoring Scripts");
    $scripts = [
        'whmcs_api_monitor.php' => 'External API Monitor',
        'server_monitor_script.sh' => 'Server Monitor Script',
        'alert_escalation_cron.php' => 'Alert Escalation Cron',
        'data_collection_cron.php' => 'Data Collection Cron'
    ];
    
    foreach ($scripts as $file => $desc) {
        checkFile($basePath . $file, $desc);
    }
    
    // Dashboard Files
    printHeader("Dashboard Files");
    $dashboards = [
        'monitoring_dashboard.html' => 'Original Dashboard',
        'monitoring_dashboard_enhanced.html' => 'Enhanced Dashboard'
    ];
    
    foreach ($dashboards as $file => $desc) {
        checkFile($basePath . $file, $desc);
    }
    
    // Database Status
    printHeader("Database Status");
    $databases = [
        'storage/alerts.db' => 'Alert Management Database',
        'storage/historical_data.db' => 'Historical Data Database'
    ];
    
    $dbStatus = true;
    foreach ($databases as $db => $desc) {
        if (!checkDatabase($basePath . $db, $desc)) {
            $dbStatus = false;
        }
    }
    
    // Test System Components
    printHeader("System Component Tests");
    
    // Test AlertManager functionality
    try {
        $alertManager = new AlertManager($basePath);
        $stats = $alertManager->getAlertStats(1);
        printStatus(true, 'Alert Manager', 'Functional');
    } catch (Exception $e) {
        printStatus(false, 'Alert Manager', $e->getMessage());
        $overallStatus = false;
    }
    
    // Test HistoricalDataManager functionality
    try {
        $historyManager = new HistoricalDataManager($basePath);
        $summary = $historyManager->getPerformanceSummary(1);
        printStatus(true, 'Historical Data Manager', 'Functional');
    } catch (Exception $e) {
        printStatus(false, 'Historical Data Manager', $e->getMessage());
        $overallStatus = false;
    }
    
    // Directory Structure
    printHeader("Directory Structure");
    $directories = [
        'storage/' => 'Storage Directory',
        'storage/logs/' => 'Logs Directory', 
        'config/' => 'Configuration Directory',
        'api/' => 'API Directory',
        'classes/' => 'Classes Directory'
    ];
    
    foreach ($directories as $dir => $desc) {
        $path = $basePath . $dir;
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        printStatus($writable, $desc, $exists ? ($writable ? 'Writable' : 'Not writable') : 'Missing');
        
        if (!$exists) {
            printWarning("Creating directory: $path");
            if (!mkdir($path, 0755, true)) {
                $overallStatus = false;
            }
        }
    }
    
    // Check PHP Extensions
    printHeader("PHP Environment");
    $extensions = ['curl', 'json', 'sqlite3', 'openssl'];
    foreach ($extensions as $ext) {
        $loaded = extension_loaded($ext);
        printStatus($loaded, "PHP Extension: $ext", $loaded ? 'Loaded' : 'Missing');
        if (!$loaded) $overallStatus = false;
    }
    
    // Check PHP version
    $phpVersion = PHP_VERSION;
    $phpOk = version_compare($phpVersion, '7.4.0', '>=');
    printStatus($phpOk, "PHP Version", $phpVersion);
    
    // Connectivity Tests (if URLs are configured)
    if (getenv('NTFY_SERVER_URL')) {
        printHeader("Connectivity Tests");
        testURL(getenv('NTFY_SERVER_URL'), 'ntfy Server');
    }
    
    if (getenv('WHMCS_URL')) {
        testURL(getenv('WHMCS_URL'), 'WHMCS Installation');
    }
    
    // System Summary
    printHeader("System Summary");
    
    if ($overallStatus) {
        echo Colors::GREEN . Colors::BOLD . "🎉 System Status: HEALTHY" . Colors::RESET . "\n";
        echo "All core components are functional and ready for use.\n";
    } else {
        echo Colors::RED . Colors::BOLD . "⚠️  System Status: ISSUES DETECTED" . Colors::RESET . "\n";
        echo "Some components need attention before the system is fully operational.\n";
    }
    
    // Quick Stats
    try {
        $alertManager = new AlertManager($basePath);
        $openAlerts = $alertManager->getOpenAlerts(5);
        echo "\nQuick Stats:\n";
        echo "- Open Alerts: " . count($openAlerts) . "\n";
        
        if (file_exists($basePath . 'storage/historical_data.db')) {
            $historyManager = new HistoricalDataManager($basePath);
            $summary = $historyManager->getPerformanceSummary(24);
            echo "- Historical Data: " . ($summary ? 'Available' : 'No data') . "\n";
        }
    } catch (Exception $e) {
        // Silently handle stats errors
    }
    
    // Next Steps
    printHeader("Next Steps");
    
    if (!$overallStatus) {
        printWarning("Fix the issues identified above before proceeding");
    }
    
    printInfo("1. Configure your environment settings in the notification config files");
    printInfo("2. Run the deployment script: sudo ./deploy.sh production /var/www/whmcs");
    printInfo("3. Set up security: ./setup-ntfy-security.sh");
    printInfo("4. Configure your iPhone with the ntfy app");
    printInfo("5. Test notifications and monitoring");
    
    echo "\n" . Colors::BOLD . "For detailed setup instructions, see DEPLOYMENT_GUIDE.md" . Colors::RESET . "\n";
    
    return $overallStatus;
}

// Run the status check
$isHealthy = checkSystemStatus();
exit($isHealthy ? 0 : 1);
?>
