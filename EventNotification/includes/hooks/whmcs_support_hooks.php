<?php
// File: /includes/hooks/support_notifications.php

require_once __DIR__ . '/notification_config.php';

// New Ticket Opened
add_hook('TicketOpen', 1, function($vars) {
    $title = "🎫 New Support Ticket";
    $message = "New ticket opened:\n" .
               "Ticket ID: #{$vars['ticketid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Client: {$vars['name']} ({$vars['email']})\n" .
               "Department: {$vars['deptname']}\n" .
               "Priority: {$vars['priority']}\n" .
               "Status: {$vars['status']}";
    
    $priority = ($vars['priority'] == 'High') ? 4 : 3;
    sendDualNotification($title, $message, $priority, 'tickets,envelope');
});

// Ticket Reply (from client)
add_hook('TicketUserReply', 1, function($vars) {
    $title = "💬 Ticket Reply (Client)";
    $message = "Client replied to ticket:\n" .
               "Ticket ID: #{$vars['ticketid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Client: {$vars['name']} ({$vars['email']})\n" .
               "Status: {$vars['status']}";
    
    sendDualNotification($title, $message, 3, 'speech_balloon,bust_in_silhouette');
});

// Admin Reply to Ticket
add_hook('TicketAdminReply', 1, function($vars) {
    $title = "👨‍💼 Ticket Reply (Admin)";
    $message = "Admin replied to ticket:\n" .
               "Ticket ID: #{$vars['ticketid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Admin: {$vars['adminusername']}\n" .
               "Status: {$vars['status']}";
    
    sendDualNotification($title, $message, 2, 'speech_balloon,man_technologist');
});

// Ticket Status Change
add_hook('TicketStatusChange', 1, function($vars) {
    $title = "📋 Ticket Status Changed";
    $message = "Ticket status updated:\n" .
               "Ticket ID: #{$vars['ticketid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Old Status: {$vars['oldstatus']}\n" .
               "New Status: {$vars['status']}";
    
    $priority = ($vars['status'] == 'Closed') ? 2 : 3;
    sendDualNotification($title, $message, $priority, 'clipboard,arrow_right');
});

// Ticket Closed
add_hook('TicketClose', 1, function($vars) {
    $title = "✅ Ticket Closed";
    $message = "Ticket closed:\n" .
               "Ticket ID: #{$vars['ticketid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Client: {$vars['name']} ({$vars['email']})";
    
    sendDualNotification($title, $message, 2, 'check_mark,tickets');
});

// Ticket Department Transfer
add_hook('TicketDeptChange', 1, function($vars) {
    $title = "🔄 Ticket Transferred";
    $message = "Ticket transferred:\n" .
               "Ticket ID: #{$vars['ticketid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "From: {$vars['olddeptname']}\n" .
               "To: {$vars['deptname']}";
    
    sendDualNotification($title, $message, 2, 'arrows_counterclockwise,tickets');
});
?>