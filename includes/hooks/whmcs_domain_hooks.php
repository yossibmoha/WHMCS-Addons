<?php
// File: /includes/hooks/whmcs_domain_hooks.php

require_once __DIR__ . '/whmcs_notification_config.php';

// Domain Registration Success
add_hook('DomainRegisterCompleted', 1, function($vars) {
    $title = "🌐 Domain Registered";
    $message = "Domain registration completed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Registrar: {$vars['registrar']}\n" .
               "Registration Period: {$vars['regperiod']} year(s)";
    
    sendDualNotificationWithAlerts($title, $message, 3, 'globe_with_meridians,check_mark', 'domain', $vars);
});

// Domain Registration Failed - CRITICAL
add_hook('DomainRegisterFailed', 1, function($vars) {
    $title = "🚨 Domain Registration Failed";
    $message = "CRITICAL: Domain registration failed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Registrar: {$vars['registrar']}\n" .
               "Error: {$vars['error']}\n" .
               "Registration Period: {$vars['regperiod']} year(s)";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'globe_with_meridians,x', 'domain', $vars);
});

// Domain Renewal Success
add_hook('DomainRenewalCompleted', 1, function($vars) {
    $title = "🔄 Domain Renewed";
    $message = "Domain renewal completed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Registrar: {$vars['registrar']}\n" .
               "Renewal Period: {$vars['regperiod']} year(s)\n" .
               "New Expiry: {$vars['expirydate']}";
    
    sendDualNotificationWithAlerts($title, $message, 3, 'arrows_clockwise,globe_with_meridians', 'domain', $vars);
});

// Domain Renewal Failed - CRITICAL  
add_hook('DomainRenewalFailed', 1, function($vars) {
    $title = "🚨 Domain Renewal Failed";
    $message = "CRITICAL: Domain renewal failed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Registrar: {$vars['registrar']}\n" .
               "Error: {$vars['error']}\n" .
               "Expiry Date: {$vars['expirydate']}\n" .
               "⚠️ Domain may expire soon!";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,globe_with_meridians', 'domain', $vars);
});

// Domain Transfer Completed
add_hook('DomainTransferCompleted', 1, function($vars) {
    $title = "🔄 Domain Transfer Completed";
    $message = "Domain transfer completed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "From Registrar: {$vars['oldregistrar']}\n" .
               "To Registrar: {$vars['registrar']}\n" .
               "New Expiry: {$vars['expirydate']}";
    
    sendDualNotificationWithAlerts($title, $message, 3, 'arrow_right,globe_with_meridians', 'domain', $vars);
});

// Domain Transfer Failed
add_hook('DomainTransferFailed', 1, function($vars) {
    $title = "⚠️ Domain Transfer Failed";
    $message = "Domain transfer failed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Target Registrar: {$vars['registrar']}\n" .
               "Error: {$vars['error']}\n" .
               "EPP Code: " . (isset($vars['eppcode']) ? 'Provided' : 'Missing');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,globe_with_meridians', 'domain', $vars);
});

// Domain Expiring Soon - CRITICAL
add_hook('DomainPreExpiry', 1, function($vars) {
    $daysUntilExpiry = (strtotime($vars['expirydate']) - time()) / 86400;
    
    $title = "⏰ Domain Expiring Soon";
    $message = "Domain expiring in " . round($daysUntilExpiry) . " days:\n" .
               "Domain: {$vars['domain']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Expiry Date: {$vars['expirydate']}\n" .
               "Registrar: {$vars['registrar']}\n" .
               "Status: {$vars['status']}";
    
    // Higher priority for domains expiring very soon
    $priority = $daysUntilExpiry <= 7 ? 5 : ($daysUntilExpiry <= 30 ? 4 : 3);
    
    sendDualNotificationWithAlerts($title, $message, $priority, 'hourglass_flowing_sand,globe_with_meridians', 'domain', $vars);
});

// Domain Sync Completed
add_hook('DomainSyncCompleted', 1, function($vars) {
    // Only notify if there were changes or issues
    if (isset($vars['changes']) && !empty($vars['changes'])) {
        $title = "🔄 Domain Sync Completed";
        $message = "Domain synchronization completed:\n" .
                   "Domain: {$vars['domain']}\n" .
                   "Registrar: {$vars['registrar']}\n" .
                   "Changes: {$vars['changes']}\n" .
                   "New Expiry: {$vars['expirydate']}";
        
        sendDualNotificationWithAlerts($title, $message, 2, 'arrows_clockwise,globe_with_meridians', 'domain', $vars);
    }
});

// Domain Sync Failed
add_hook('DomainSyncFailed', 1, function($vars) {
    $title = "⚠️ Domain Sync Failed";
    $message = "Domain synchronization failed:\n" .
               "Domain: {$vars['domain']}\n" .
               "Registrar: {$vars['registrar']}\n" .
               "Error: {$vars['error']}\n" .
               "Last Sync: {$vars['lastsync']}";
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,arrows_clockwise', 'domain', $vars);
});
?>
