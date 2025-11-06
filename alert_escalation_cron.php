<?php
/**
 * Alert Escalation Cron Job
 * Run this script every 5 minutes to process alert escalations
 * Usage: php alert_escalation_cron.php
 */

// Ensure script runs only in CLI mode
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/classes/AlertManager.php';

// Configuration
$logFile = __DIR__ . '/storage/logs/escalation_cron.log';
$lockFile = __DIR__ . '/storage/escalation.lock';

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
 * Check if another instance is running
 */
function acquireLock($lockFile) {
    if (file_exists($lockFile)) {
        $pid = (int)file_get_contents($lockFile);
        
        // Check if process is still running (Unix/Linux systems)
        if ($pid > 0 && file_exists("/proc/$pid")) {
            return false;
        }
        
        // Remove stale lock file
        unlink($lockFile);
    }
    
    // Create lock file with current process ID
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
 * Main execution
 */
try {
    // Ensure storage directory exists
    $storageDir = dirname($logFile);
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    logMessage("Starting escalation cron job", $logFile);
    
    // Acquire lock to prevent multiple instances
    if (!acquireLock($lockFile)) {
        logMessage("Another instance is already running, exiting", $logFile);
        exit(0);
    }
    
    // Initialize AlertManager
    $alertManager = new AlertManager(__DIR__ . '/');
    
    // Process escalations
    $startTime = microtime(true);
    $escalatedCount = $alertManager->processEscalations();
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    
    logMessage("Processed $escalatedCount escalations in {$executionTime}ms", $logFile);
    
    // Cleanup old alerts (run once daily at 2 AM)
    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');
    
    if ($currentHour === 2 && $currentMinute < 5) {
        logMessage("Running daily cleanup", $logFile);
        $cleanupResult = $alertManager->cleanupOldAlerts(30);
        logMessage("Cleanup completed: {$cleanupResult['alerts']} alerts, {$cleanupResult['actions']} actions deleted", $logFile);
    }
    
    // Health check - alert if too many unacknowledged alerts
    $openAlerts = $alertManager->getOpenAlerts(100);
    $unacknowledgedCount = count(array_filter($openAlerts, function($alert) {
        return $alert['status'] === 'open';
    }));
    
    if ($unacknowledgedCount > 20) {
        logMessage("WARNING: $unacknowledgedCount unacknowledged alerts", $logFile);
        
        // Create high-priority alert about too many unacknowledged alerts
        $alertManager->createAlert(
            "High Volume of Unacknowledged Alerts",
            "There are currently $unacknowledgedCount unacknowledged alerts in the system. Please review and acknowledge or resolve alerts to prevent escalation overload.",
            4,
            'system',
            ['unacknowledged_count' => $unacknowledgedCount]
        );
    }
    
    logMessage("Escalation cron job completed successfully", $logFile);
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage(), $logFile);
    
    // Create critical alert about cron job failure
    if (isset($alertManager)) {
        try {
            $alertManager->createAlert(
                "Alert Escalation Cron Job Failed",
                "The alert escalation cron job failed with error: " . $e->getMessage(),
                5,
                'system',
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
        } catch (Exception $alertError) {
            logMessage("Failed to create alert about cron job failure: " . $alertError->getMessage(), $logFile);
        }
    }
    
    exit(1);
} finally {
    // Always release the lock
    releaseLock($lockFile);
}

exit(0);
?>
