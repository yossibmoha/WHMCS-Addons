<?php
// File: /includes/hooks/user_notifications.php

require_once __DIR__ . '/notification_config.php';

// User Registration Success
add_hook('ClientAdd', 1, function($vars) {
    $title = "New User Registration";
    $message = "New user registered:\n" .
               "Name: {$vars['firstname']} {$vars['lastname']}\n" .
               "Email: {$vars['email']}\n" .
               "Client ID: {$vars['userid']}";
    
    sendDualNotification($title, $message, 3, 'green_circle,bust_in_silhouette');
});

// Login Success
add_hook('ClientLogin', 1, function($vars) {
    $title = "Client Login";
    $message = "Client logged in:\n" .
               "Email: {$vars['email']}\n" .
               "IP: " . $_SERVER['REMOTE_ADDR'];
    
    sendDualNotification($title, $message, 1, 'green_circle,key');
});

// Failed Login Attempts
add_hook('ClientLoginFailed', 1, function($vars) {
    $title = "⚠️ Failed Login Attempt";
    $message = "Failed login attempt:\n" .
               "Email: {$vars['email']}\n" .
               "IP: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Reason: {$vars['reason']}";
    
    sendDualNotification($title, $message, 4, 'warning,shield');
});

// Client Area Access
add_hook('ClientAreaPage', 1, function($vars) {
    // Only notify for certain critical pages
    $criticalPages = ['clientarea', 'cart', 'login'];
    
    if (in_array($vars['templatefile'], $criticalPages)) {
        $title = "Client Area Access";
        $message = "Page accessed: {$vars['templatefile']}\n" .
                   "Client ID: " . (isset($_SESSION['uid']) ? $_SESSION['uid'] : 'Guest') . "\n" .
                   "IP: " . $_SERVER['REMOTE_ADDR'];
        
        sendDualNotification($title, $message, 1, 'computer,globe_with_meridians');
    }
});
?>