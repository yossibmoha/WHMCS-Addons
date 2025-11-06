<?php
// File: /includes/hooks/server_notifications.php

require_once __DIR__ . '/notification_config.php';

// Server/Service Created
add_hook('AfterModuleCreate', 1, function($vars) {
    $title = "🖥️ Server Created";
    $message = "New server/service created:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Product: {$vars['productname']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Domain: {$vars['domain']}\n" .
               "Server: {$vars['servername']}";
    
    sendDualNotification($title, $message, 3, 'desktop_computer,sparkles');
});

// Server/Service Suspended
add_hook('AfterModuleSuspend', 1, function($vars) {
    $title = "⏸️ Server Suspended";
    $message = "Server/service suspended:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Product: {$vars['productname']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Domain: {$vars['domain']}\n" .
               "Reason: {$vars['suspendreason']}";
    
    sendDualNotification($title, $message, 4, 'warning,desktop_computer');
});

// Server/Service Terminated
add_hook('AfterModuleTerminate', 1, function($vars) {
    $title = "🗑️ Server Terminated";
    $message = "Server/service terminated:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Product: {$vars['productname']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Domain: {$vars['domain']}";
    
    sendDualNotification($title, $message, 4, 'wastebasket,desktop_computer');
});

// Server/Service Unsuspended
add_hook('AfterModuleUnsuspend', 1, function($vars) {
    $title = "▶️ Server Unsuspended";
    $message = "Server/service unsuspended:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Product: {$vars['productname']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Domain: {$vars['domain']}";
    
    sendDualNotification($title, $message, 3, 'check_mark_button,desktop_computer');
});

// Module Command Errors
add_hook('AfterModuleCommandError', 1, function($vars) {
    $title = "🚨 Module Error";
    $message = "Module command failed:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Command: {$vars['command']}\n" .
               "Error: {$vars['error']}\n" .
               "Server: {$vars['servername']}";
    
    sendDualNotification($title, $message, 5, 'x,desktop_computer');
});
?>