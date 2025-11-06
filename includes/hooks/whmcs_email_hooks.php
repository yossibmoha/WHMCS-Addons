<?php
// File: /includes/hooks/whmcs_email_hooks.php

require_once __DIR__ . '/whmcs_notification_config.php';

// Email Sent Successfully
add_hook('EmailSent', 1, function($vars) {
    // Only log critical emails or errors to avoid spam
    $criticalTemplates = [
        'Invoice Created',
        'Invoice Payment Reminder',
        'Service Suspended',
        'Domain Expiry Notice',
        'Password Reset Validation'
    ];
    
    if (in_array($vars['messagename'], $criticalTemplates)) {
        $title = "📧 Critical Email Sent";
        $message = "Important email delivered:\n" .
                   "Template: {$vars['messagename']}\n" .
                   "To: {$vars['email']}\n" .
                   "Subject: {$vars['subject']}\n" .
                   "Client ID: " . ($vars['userid'] ?? 'N/A');
        
        sendDualNotificationWithAlerts($title, $message, 1, 'check_mark,envelope', 'email', $vars);
    }
});

// Email Failed - CRITICAL
add_hook('EmailFailed', 1, function($vars) {
    $title = "🚨 Email Delivery Failed";
    $message = "CRITICAL: Email delivery failed:\n" .
               "Template: {$vars['messagename']}\n" .
               "To: {$vars['email']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Error: {$vars['error']}\n" .
               "Client ID: " . ($vars['userid'] ?? 'N/A') . "\n" .
               "⚠️ Client may not receive important notifications";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,envelope', 'email', $vars);
});

// Email Bounced
add_hook('EmailBounced', 1, function($vars) {
    $title = "↩️ Email Bounced";
    $message = "Email bounced back:\n" .
               "Template: {$vars['messagename']}\n" .
               "To: {$vars['email']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Bounce Reason: {$vars['bounceReason']}\n" .
               "Bounce Type: " . ($vars['bounceType'] ?? 'Unknown') . "\n" .
               "Client ID: " . ($vars['userid'] ?? 'N/A');
    
    $priority = ($vars['bounceType'] === 'hard') ? 4 : 3;
    sendDualNotificationWithAlerts($title, $message, $priority, 'warning,envelope', 'email', $vars);
});

// Mass Email Campaign Completed
add_hook('MassMailComplete', 1, function($vars) {
    $title = "📨 Mass Email Campaign Completed";
    $message = "Mass email campaign finished:\n" .
               "Campaign: {$vars['campaignname']}\n" .
               "Total Recipients: {$vars['totalrecipients']}\n" .
               "Sent Successfully: {$vars['sent']}\n" .
               "Failed: {$vars['failed']}\n" .
               "Bounced: {$vars['bounced']}\n" .
               "Success Rate: " . round(($vars['sent'] / $vars['totalrecipients']) * 100, 2) . "%";
    
    $priority = ($vars['failed'] + $vars['bounced']) > ($vars['totalrecipients'] * 0.1) ? 4 : 2;
    sendDualNotificationWithAlerts($title, $message, $priority, 'envelope_with_arrow,chart_increasing', 'email', $vars);
});

// SMTP Connection Failed
add_hook('SMTPConnectionFailed', 1, function($vars) {
    $title = "🚨 SMTP Connection Failed";
    $message = "CRITICAL: SMTP server connection failed:\n" .
               "SMTP Server: {$vars['host']}\n" .
               "Port: {$vars['port']}\n" .
               "Error: {$vars['error']}\n" .
               "⚠️ Email delivery is compromised";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,envelope', 'email', $vars);
});

// Email Queue Processing
add_hook('EmailQueueProcessed', 1, function($vars) {
    // Only alert if there are issues or large queue
    if ($vars['failed'] > 0 || $vars['queued'] > 100) {
        $title = "📬 Email Queue Processed";
        $message = "Email queue processing completed:\n" .
                   "Emails Processed: {$vars['processed']}\n" .
                   "Successful: {$vars['sent']}\n" .
                   "Failed: {$vars['failed']}\n" .
                   "Still Queued: {$vars['queued']}\n" .
                   "Processing Time: {$vars['processingtime']}s";
        
        $priority = $vars['failed'] > 10 ? 4 : 3;
        sendDualNotificationWithAlerts($title, $message, $priority, 'hourglass_done,envelope', 'email', $vars);
    }
});

// Email Template Missing
add_hook('EmailTemplateMissing', 1, function($vars) {
    $title = "⚠️ Email Template Missing";
    $message = "Email template not found:\n" .
               "Template Name: {$vars['templatename']}\n" .
               "Language: {$vars['language']}\n" .
               "Client ID: " . ($vars['userid'] ?? 'N/A') . "\n" .
               "Action: {$vars['action']}\n" .
               "⚠️ Default template used";
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,file_folder', 'email', $vars);
});

// Email Attachment Failed
add_hook('EmailAttachmentFailed', 1, function($vars) {
    $title = "📎 Email Attachment Failed";
    $message = "Email attachment could not be processed:\n" .
               "Template: {$vars['messagename']}\n" .
               "To: {$vars['email']}\n" .
               "Attachment: {$vars['filename']}\n" .
               "Error: {$vars['error']}\n" .
               "⚠️ Email sent without attachment";
    
    sendDualNotificationWithAlerts($title, $message, 3, 'warning,paperclip', 'email', $vars);
});

// Email Rate Limit Exceeded
add_hook('EmailRateLimitExceeded', 1, function($vars) {
    $title = "🚫 Email Rate Limit Exceeded";
    $message = "Email rate limit has been exceeded:\n" .
               "Current Rate: {$vars['currentrate']} emails/hour\n" .
               "Limit: {$vars['ratelimit']} emails/hour\n" .
               "Queued Emails: {$vars['queued']}\n" .
               "Provider: {$vars['provider']}\n" .
               "⚠️ Email delivery delayed";
    
    sendDualNotificationWithAlerts($title, $message, 4, 'warning,envelope', 'email', $vars);
});

// Unsubscribe Event
add_hook('EmailUnsubscribed', 1, function($vars) {
    $title = "📭 Email Unsubscribed";
    $message = "Client unsubscribed from emails:\n" .
               "Client ID: {$vars['userid']}\n" .
               "Email: {$vars['email']}\n" .
               "Unsubscribe Type: {$vars['type']}\n" .
               "Campaign: " . ($vars['campaign'] ?? 'All emails') . "\n" .
               "Reason: " . ($vars['reason'] ?? 'Not specified');
    
    sendDualNotificationWithAlerts($title, $message, 2, 'no_entry,envelope', 'email', $vars);
});

// Email Delivery Report
add_hook('EmailDailyReport', 1, function($vars) {
    // Daily email statistics
    $successRate = ($vars['total'] > 0) ? round(($vars['sent'] / $vars['total']) * 100, 2) : 0;
    
    if ($vars['failed'] > 0 || $successRate < 95) {
        $title = "📊 Daily Email Report";
        $message = "Daily email delivery summary:\n" .
                   "Total Emails: {$vars['total']}\n" .
                   "Sent Successfully: {$vars['sent']}\n" .
                   "Failed: {$vars['failed']}\n" .
                   "Bounced: {$vars['bounced']}\n" .
                   "Success Rate: {$successRate}%\n" .
                   "Date: " . date('Y-m-d');
        
        $priority = $successRate < 90 ? 4 : ($successRate < 95 ? 3 : 2);
        sendDualNotificationWithAlerts($title, $message, $priority, 'chart_increasing,envelope', 'email', $vars);
    }
});
?>
