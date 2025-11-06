<?php
// File: /includes/hooks/error_notifications.php

require_once __DIR__ . '/notification_config.php';

// Admin Login
add_hook('AdminLogin', 1, function($vars) {
    $title = "🔐 Admin Login";
    $message = "Administrator logged in:\n" .
               "Admin: {$vars['username']}\n" .
               "IP: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotification($title, $message, 3, 'shield,key');
});

// Admin Logout
add_hook('AdminLogout', 1, function($vars) {
    $title = "🚪 Admin Logout";
    $message = "Administrator logged out:\n" .
               "Admin: {$vars['username']}\n" .
               "Session Duration: Auto-calculated";
    
    sendDualNotification($title, $message, 1, 'door,wave');
});

// License Check Failed
add_hook('LicenseCheckFailed', 1, function($vars) {
    $title = "⚠️ License Check Failed";
    $message = "WHMCS license verification failed:\n" .
               "Domain: {$vars['domain']}\n" .
               "IP: {$vars['ip']}\n" .
               "Error: {$vars['error']}";
    
    sendDualNotification($title, $message, 5, 'warning,scroll');
});

// Daily Cron Job
add_hook('DailyCronJob', 1, function($vars) {
    $title = "🕒 Daily Cron Completed";
    $message = "Daily cron job executed:\n" .
               "Time: " . date('Y-m-d H:i:s') . "\n" .
               "Status: Completed";
    
    sendDualNotification($title, $message, 1, 'clock,check_mark');
});

// Domain Sync
add_hook('DomainValidation', 1, function($vars) {
    if ($vars['status'] == 'error') {
        $title = "🌐 Domain Validation Error";
        $message = "Domain validation failed:\n" .
                   "Domain: {$vars['domain']}\n" .
                   "Error: {$vars['error']}";
        
        sendDualNotification($title, $message, 4, 'globe_with_meridians,x');
    }
});

// Custom Error Handler for Critical Errors
function whmcsErrorHandler($errno, $errstr, $errfile, $errline) {
    // Only handle critical errors
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    $errorTypes = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning', 
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice'
    ];
    
    // Only notify for serious errors
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $errorType = isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'Unknown Error';
        
        $title = "🚨 WHMCS System Error";
        $message = "Critical error occurred:\n" .
                   "Type: {$errorType}\n" .
                   "Message: {$errstr}\n" .
                   "File: " . basename($errfile) . "\n" .
                   "Line: {$errline}\n" .
                   "Time: " . date('Y-m-d H:i:s');
        
        sendDualNotification($title, $message, 5, 'rotating_light,x');
    }
    
    return false; // Let PHP handle the error normally
}

// Register the error handler
set_error_handler('whmcsErrorHandler');

// Database Connection Issues
add_hook('DatabaseError', 1, function($vars) {
    $title = "🗄️ Database Error";
    $message = "Database error occurred:\n" .
               "Error: {$vars['error']}\n" .
               "Query: " . substr($vars['query'], 0, 100) . "...\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotification($title, $message, 5, 'floppy_disk,x');
});
?>