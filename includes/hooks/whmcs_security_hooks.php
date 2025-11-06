<?php
// File: /includes/hooks/whmcs_security_hooks.php

require_once __DIR__ . '/whmcs_notification_config.php';

// Admin Login Failed - CRITICAL SECURITY
add_hook('AdminLoginFailed', 1, function($vars) {
    $title = "🚨 Admin Login Failed";
    $message = "SECURITY ALERT: Failed admin login attempt:\n" .
               "Username: {$vars['username']}\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n" .
               "Time: " . date('Y-m-d H:i:s') . "\n" .
               "Reason: " . ($vars['reason'] ?? 'Invalid credentials');
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,shield', 'security', $vars);
});

// Client Login Banned/Blocked
add_hook('ClientLoginBanned', 1, function($vars) {
    $title = "🛡️ Client Login Banned";
    $message = "Client login has been banned:\n" .
               "Client ID: {$vars['userid']}\n" .
               "Email: {$vars['email']}\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Reason: {$vars['reason']}\n" .
               "Ban Duration: " . ($vars['duration'] ?? 'Permanent') . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'no_entry,shield', 'security', $vars);
});

// Two-Factor Authentication Failed
add_hook('TwoFactorAuthFailed', 1, function($vars) {
    $title = "⚠️ Two-Factor Auth Failed";
    $message = "2FA verification failed:\n" .
               "User Type: " . ($vars['adminuser'] ?? false ? 'Admin' : 'Client') . "\n" .
               "Username/Email: {$vars['username']}\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Attempts: " . ($vars['attempts'] ?? 1) . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    $priority = ($vars['attempts'] ?? 1) > 3 ? 4 : 3;
    sendDualNotificationWithAlerts($title, $message, $priority, 'warning,mobile_phone', 'security', $vars);
});

// Password Reset Request
add_hook('ClientPasswordReset', 1, function($vars) {
    $title = "🔑 Password Reset Requested";
    $message = "Password reset requested:\n" .
               "Client ID: {$vars['userid']}\n" .
               "Email: {$vars['email']}\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotificationWithAlerts($title, $message, 3, 'key,arrows_clockwise', 'security', $vars);
});

// Admin Password Reset
add_hook('AdminPasswordReset', 1, function($vars) {
    $title = "🔐 Admin Password Reset";
    $message = "Admin password reset requested:\n" .
               "Admin: {$vars['username']}\n" .
               "Email: {$vars['email']}\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'shield,key', 'security', $vars);
});

// Client Password Change
add_hook('ClientChangePassword', 1, function($vars) {
    $title = "🔒 Client Password Changed";
    $message = "Client changed their password:\n" .
               "Client ID: {$vars['userid']}\n" .
               "Email: {$vars['email']}\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotificationWithAlerts($title, $message, 2, 'lock,check_mark', 'security', $vars);
});

// Fraud Detection Triggered
add_hook('FraudCheckFailed', 1, function($vars) {
    $title = "🚨 Fraud Alert";
    $message = "FRAUD DETECTION: Suspicious activity detected:\n" .
               "Order ID: {$vars['orderid']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Email: {$vars['email']}\n" .
               "IP Address: {$vars['ip']}\n" .
               "Risk Score: {$vars['riskscore']}\n" .
               "Reason: {$vars['reason']}\n" .
               "⚠️ Manual review required";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,detective', 'security', $vars);
});

// IP Address Blocked
add_hook('IPAddressBlocked', 1, function($vars) {
    $title = "🚫 IP Address Blocked";
    $message = "IP address has been blocked:\n" .
               "IP Address: {$vars['ip']}\n" .
               "Reason: {$vars['reason']}\n" .
               "Duration: " . ($vars['duration'] ?? 'Permanent') . "\n" .
               "Blocked By: " . ($vars['blockedby'] ?? 'System') . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'no_entry,globe_with_meridians', 'security', $vars);
});

// Suspicious Activity Detection
add_hook('SuspiciousActivityDetected', 1, function($vars) {
    $title = "⚠️ Suspicious Activity";
    $message = "Suspicious activity detected:\n" .
               "Activity Type: {$vars['activity']}\n" .
               "User: " . ($vars['userid'] ?? 'Guest') . "\n" .
               "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n" .
               "Details: {$vars['details']}\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    $priority = isset($vars['severity']) ? $vars['severity'] : 3;
    sendDualNotificationWithAlerts($title, $message, $priority, 'warning,detective', 'security', $vars);
});

// Multiple Login Failures from Same IP
$failedLogins = [];
add_hook('ClientLoginFailed', 1, function($vars) use (&$failedLogins) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $currentTime = time();
    
    // Clean old entries (older than 1 hour)
    $failedLogins = array_filter($failedLogins, function($timestamp) use ($currentTime) {
        return ($currentTime - $timestamp) < 3600;
    });
    
    // Add current failure
    if (!isset($failedLogins[$ip])) {
        $failedLogins[$ip] = [];
    }
    $failedLogins[$ip][] = $currentTime;
    
    // Check if threshold exceeded (5 failures in 1 hour)
    if (count($failedLogins[$ip]) >= 5) {
        $title = "🚨 Multiple Login Failures";
        $message = "SECURITY ALERT: Multiple login failures from same IP:\n" .
                   "IP Address: $ip\n" .
                   "Failed Attempts: " . count($failedLogins[$ip]) . "\n" .
                   "Time Window: Last hour\n" .
                   "Latest Email: {$vars['email']}\n" .
                   "Consider IP blocking";
        
        sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,shield', 'security', [
            'ip' => $ip,
            'attempts' => count($failedLogins[$ip]),
            'latest_email' => $vars['email']
        ]);
        
        // Reset counter after alert
        $failedLogins[$ip] = [];
    }
});

// SSL Certificate Issues
add_hook('SSLCertificateError', 1, function($vars) {
    $title = "🔒 SSL Certificate Issue";
    $message = "SSL certificate problem detected:\n" .
               "Domain: {$vars['domain']}\n" .
               "Error: {$vars['error']}\n" .
               "Certificate Status: {$vars['status']}\n" .
               "Expiry Date: " . ($vars['expiry'] ?? 'Unknown') . "\n" .
               "⚠️ Immediate attention required";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,lock', 'security', $vars);
});

// File Permission Changes (if monitored)
add_hook('FilePermissionChanged', 1, function($vars) {
    $title = "📁 File Permission Changed";
    $message = "Critical file permissions modified:\n" .
               "File: {$vars['file']}\n" .
               "Old Permissions: {$vars['old_perms']}\n" .
               "New Permissions: {$vars['new_perms']}\n" .
               "Changed By: " . ($vars['changed_by'] ?? 'Unknown') . "\n" .
               "Time: " . date('Y-m-d H:i:s');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,file_folder', 'security', $vars);
});
?>
