<?php
// File: /includes/hooks/whmcs_cron_hooks.php

require_once __DIR__ . '/whmcs_notification_config.php';

// Pre-Cron Job Hook
add_hook('PreCronJob', 1, function($vars) {
    // Log start of important cron jobs only
    $importantJobs = [
        'Daily',
        'Invoice',
        'Overdue',
        'Domain Sync',
        'Backup'
    ];
    
    if (in_array($vars['stage'], $importantJobs)) {
        $title = "⏰ Cron Job Starting";
        $message = "Cron job initiated:\n" .
                   "Stage: {$vars['stage']}\n" .
                   "Started: " . date('Y-m-d H:i:s') . "\n" .
                   "PID: " . getmypid();
        
        sendDualNotificationWithAlerts($title, $message, 1, 'clock,arrow_forward', 'cron', $vars);
    }
});

// Post-Cron Job Hook  
add_hook('PostCronJob', 1, function($vars) {
    $executionTime = $vars['executiontime'] ?? 0;
    $errors = $vars['errors'] ?? 0;
    
    // Only alert on long execution times or errors
    if ($executionTime > 600 || $errors > 0) { // 10 minutes
        $title = $errors > 0 ? "⚠️ Cron Job Completed with Issues" : "⏰ Long Cron Job Completed";
        $message = "Cron job finished:\n" .
                   "Stage: {$vars['stage']}\n" .
                   "Execution Time: {$executionTime}s\n" .
                   "Errors: $errors\n" .
                   "Warnings: " . ($vars['warnings'] ?? 0) . "\n" .
                   "Completed: " . date('Y-m-d H:i:s');
        
        $priority = $errors > 0 ? 4 : 3;
        sendDualNotificationWithAlerts($title, $message, $priority, 'clock,check_mark', 'cron', $vars);
    }
});

// Cron Job Error - CRITICAL
add_hook('CronJobError', 1, function($vars) {
    $title = "🚨 Cron Job Error";
    $message = "CRITICAL: Cron job failed:\n" .
               "Stage: {$vars['stage']}\n" .
               "Error: {$vars['error']}\n" .
               "Error Code: " . ($vars['errorcode'] ?? 'Unknown') . "\n" .
               "File: " . ($vars['file'] ?? 'Unknown') . "\n" .
               "Line: " . ($vars['line'] ?? 'Unknown') . "\n" .
               "Time: " . date('Y-m-d H:i:s') . "\n" .
               "⚠️ May affect system operations";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,clock', 'cron', $vars);
});

// Cron Job Timeout
add_hook('CronJobTimeout', 1, function($vars) {
    $title = "⏱️ Cron Job Timeout";
    $message = "Cron job exceeded time limit:\n" .
               "Stage: {$vars['stage']}\n" .
               "Time Limit: {$vars['timelimit']}s\n" .
               "Actual Runtime: {$vars['runtime']}s\n" .
               "Status: Terminated\n" .
               "⚠️ Job may be incomplete";
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,clock', 'cron', $vars);
});

// Daily Cron Statistics
add_hook('DailyCronJobCompleted', 1, function($vars) {
    $totalErrors = array_sum($vars['errors'] ?? []);
    $totalTime = array_sum($vars['executiontimes'] ?? []);
    
    if ($totalErrors > 0 || $totalTime > 1800) { // 30 minutes total
        $title = "📊 Daily Cron Report";
        $message = "Daily cron job summary:\n" .
                   "Total Stages: " . count($vars['stages'] ?? []) . "\n" .
                   "Total Time: {$totalTime}s\n" .
                   "Total Errors: $totalErrors\n" .
                   "Failed Stages: " . count(array_filter($vars['errors'] ?? [])) . "\n" .
                   "Date: " . date('Y-m-d');
        
        $priority = $totalErrors > 5 ? 4 : 3;
        sendDualNotificationWithAlerts($title, $message, $priority, 'calendar,clock', 'cron', $vars);
    }
});

// Cron Job Memory Limit Exceeded
add_hook('CronJobMemoryLimit', 1, function($vars) {
    $title = "🧠 Cron Memory Limit Exceeded";
    $message = "Cron job hit memory limit:\n" .
               "Stage: {$vars['stage']}\n" .
               "Memory Used: {$vars['memoryused']}\n" .
               "Memory Limit: {$vars['memorylimit']}\n" .
               "Peak Usage: {$vars['peakmemory']}\n" .
               "⚠️ Consider increasing memory limit";
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,brain', 'cron', $vars);
});

// Invoice Generation Cron
add_hook('InvoiceCreationCronCompleted', 1, function($vars) {
    $title = "📋 Invoice Generation Completed";
    $message = "Invoice generation cron finished:\n" .
               "Invoices Created: {$vars['created']}\n" .
               "Failed: {$vars['failed']}\n" .
               "Total Services: {$vars['totalservices']}\n" .
               "Execution Time: {$vars['executiontime']}s\n" .
               "Success Rate: " . round(($vars['created'] / $vars['totalservices']) * 100, 2) . "%";
    
    $priority = $vars['failed'] > 0 ? 3 : 1;
    sendDualNotificationWithAlerts($title, $message, $priority, 'receipt,clock', 'cron', $vars);
});

// Domain Sync Cron
add_hook('DomainSyncCronCompleted', 1, function($vars) {
    if ($vars['errors'] > 0 || $vars['changes'] > 0) {
        $title = "🌐 Domain Sync Completed";
        $message = "Domain synchronization finished:\n" .
                   "Domains Processed: {$vars['processed']}\n" .
                   "Changes Detected: {$vars['changes']}\n" .
                   "Errors: {$vars['errors']}\n" .
                   "Execution Time: {$vars['executiontime']}s";
        
        $priority = $vars['errors'] > 5 ? 4 : 2;
        sendDualNotificationWithAlerts($title, $message, $priority, 'globe_with_meridians,arrows_clockwise', 'cron', $vars);
    }
});

// Backup Cron Job
add_hook('BackupCronCompleted', 1, function($vars) {
    $title = $vars['success'] ? "💾 Backup Completed" : "🚨 Backup Failed";
    $message = "Database backup " . ($vars['success'] ? 'completed successfully' : 'failed') . ":\n" .
               "Backup File: {$vars['filename']}\n" .
               "File Size: {$vars['filesize']}\n" .
               "Execution Time: {$vars['executiontime']}s\n" .
               "Location: {$vars['location']}";
    
    if (!$vars['success']) {
        $message .= "\nError: {$vars['error']}";
    }
    
    $priority = $vars['success'] ? 2 : 5;
    $tags = $vars['success'] ? 'floppy_disk,check_mark' : 'rotating_light,floppy_disk';
    
    sendDualNotificationWithAlerts($title, $message, $priority, $tags, 'cron', $vars);
});

// Currency Update Cron
add_hook('CurrencyUpdateCronCompleted', 1, function($vars) {
    if ($vars['updated'] > 0 || $vars['errors'] > 0) {
        $title = "💱 Currency Rates Updated";
        $message = "Currency exchange rates updated:\n" .
                   "Currencies Updated: {$vars['updated']}\n" .
                   "Errors: {$vars['errors']}\n" .
                   "Last Update: " . date('Y-m-d H:i:s') . "\n" .
                   "Source: {$vars['source']}";
        
        $priority = $vars['errors'] > 0 ? 3 : 1;
        sendDualNotificationWithAlerts($title, $message, $priority, 'money_mouth_face,arrows_clockwise', 'cron', $vars);
    }
});

// Prune Activity Log Cron
add_hook('ActivityLogPruneCronCompleted', 1, function($vars) {
    $title = "🗂️ Activity Log Pruned";
    $message = "Activity log cleanup completed:\n" .
               "Records Removed: {$vars['removed']}\n" .
               "Records Remaining: {$vars['remaining']}\n" .
               "Cutoff Date: {$vars['cutoffdate']}\n" .
               "Execution Time: {$vars['executiontime']}s";
    
    sendDualNotificationWithAlerts($title, $message, 1, 'file_cabinet,broom', 'cron', $vars);
});

// Custom Cron Job Failed (for custom modules)
add_hook('CustomCronJobFailed', 1, function($vars) {
    $title = "⚠️ Custom Cron Job Failed";
    $message = "Custom cron job encountered an error:\n" .
               "Job Name: {$vars['jobname']}\n" .
               "Module: {$vars['module']}\n" .
               "Error: {$vars['error']}\n" .
               "Execution Time: {$vars['executiontime']}s\n" .
               "Last Success: " . ($vars['lastsuccess'] ?? 'Unknown');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,gear', 'cron', $vars);
});
?>
