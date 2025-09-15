<?php
// File: /includes/hooks/health_monitor.php

require_once __DIR__ . '/notification_config.php';

// Monitor WHMCS system health every hour
add_hook('DailyCronJob', 1, function($vars) {
    performHealthChecks();
});

function performHealthChecks() {
    $issues = [];
    
    // 1. Database Connection Check
    try {
        $pdo = Capsule::connection()->getPdo();
        if (!$pdo) {
            $issues[] = "Database connection failed";
        }
    } catch (Exception $e) {
        $issues[] = "Database error: " . $e->getMessage();
    }
    
    // 2. Check Critical Tables
    $criticalTables = ['tblclients', 'tblinvoices', 'tblorders', 'tbltickets'];
    foreach ($criticalTables as $table) {
        try {
            $count = Capsule::table($table)->count();
            if ($count === false) {
                $issues[] = "Table {$table} is inaccessible";
            }
        } catch (Exception $e) {
            $issues[] = "Table {$table} error: " . $e->getMessage();
        }
    }
    
    // 3. Disk Space Check
    $diskUsage = disk_free_space('/') / disk_total_space('/') * 100;
    if ($diskUsage < 10) { // Less than 10% free
        $issues[] = "Low disk space: " . number_format(100 - $diskUsage, 1) . "% used";
    }
    
    // 4. Configuration Files Check
    $configFile = dirname(__DIR__, 2) . '/configuration.php';
    if (!file_exists($configFile) || !is_readable($configFile)) {
        $issues[] = "Configuration file is missing or unreadable";
    }
    
    // 5. License Check
    try {
        $licenseKey = \WHMCS\Config\Setting::getValue('License');
        if (empty($licenseKey)) {
            $issues[] = "WHMCS license key is missing";
        }
    } catch (Exception $e) {
        $issues[] = "License check failed: " . $e->getMessage();
    }
    
    // 6. Template Directory Check
    $templatePath = dirname(__DIR__, 2) . '/templates';
    if (!is_dir($templatePath) || !is_readable($templatePath)) {
        $issues[] = "Templates directory is inaccessible";
    }
    
    // 7. Cron Job Health
    $lastCron = \WHMCS\Config\Setting::getValue('CronLastRun');
    if (!empty($lastCron)) {
        $lastRun = strtotime($lastCron);
        $hoursSince = (time() - $lastRun) / 3600;
        if ($hoursSince > 25) { // More than 25 hours since last cron
            $issues[] = "Cron job hasn't run in " . number_format($hoursSince, 1) . " hours";
        }
    }
    
    // 8. PHP Memory Usage
    $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB
    $memoryLimit = ini_get('memory_limit');
    $memoryLimitBytes = return_bytes($memoryLimit);
    $memoryPercent = ($memoryUsage * 1024 * 1024) / $memoryLimitBytes * 100;
    
    if ($memoryPercent > 80) {
        $issues[] = "High memory usage: " . number_format($memoryPercent, 1) . "%";
    }
    
    // 9. Failed Login Attempts (Security)
    try {
        $failedLogins = Capsule::table('tblactivitylog')
            ->where('date', '>', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->where('description', 'like', '%Failed Login%')
            ->count();
            
        if ($failedLogins > 10) {
            $issues[] = "High failed login attempts: {$failedLogins} in last hour";
        }
    } catch (Exception $e) {
        $issues[] = "Could not check login attempts: " . $e->getMessage();
    }
    
    // 10. Overdue Invoices Check
    try {
        $overdueInvoices = Capsule::table('tblinvoices')
            ->where('status', 'Unpaid')
            ->where('duedate', '<', date('Y-m-d'))
            ->count();
            
        if ($overdueInvoices > 50) { // Adjust threshold as needed
            $issues[] = "High number of overdue invoices: {$overdueInvoices}";
        }
    } catch (Exception $e) {
        $issues[] = "Could not check overdue invoices: " . $e->getMessage();
    }
    
    // Send notifications if issues found
    if (!empty($issues)) {
        $title = "🚨 WHMCS Health Issues Detected";
        $message = "Health check found issues:\n\n" . implode("\n", $issues);
        sendDualNotification($title, $message, 4, 'warning,health');
    } else {
        // Send daily health OK message (optional)
        $title = "✅ WHMCS Health Check";
        $message = "All systems operational\n" .
                   "Memory: " . number_format($memoryUsage, 1) . "MB\n" .
                   "Disk: " . number_format(100 - $diskUsage, 1) . "% free\n" .
                   "Last cron: " . (isset($hoursSince) ? number_format($hoursSince, 1) . "h ago" : "Unknown");
        sendDualNotification($title, $message, 1, 'check_mark,health');
    }
}

function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int) $val;
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}

// Monitor real-time performance issues
function logSlowQuery($query, $time) {
    if ($time > 2) { // Queries taking longer than 2 seconds
        $title = "⏱️ Slow Query Detected";
        $message = "Slow database query:\n" .
                   "Time: " . number_format($time, 2) . "s\n" .
                   "Query: " . substr($query, 0, 100) . "...";
        sendDualNotification($title, $message, 3, 'snail,database');
    }
}
?>