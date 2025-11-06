<?php
// File: /includes/hooks/whmcs_payment_hooks.php

require_once __DIR__ . '/whmcs_notification_config.php';

// Invoice Payment Success - CORRECTED HOOK
add_hook('InvoicePaid', 1, function($vars) {
    $title = "💳 Payment Received";
    $message = "Payment successfully processed:\n" .
               "Invoice ID: #{$vars['invoiceid']}\n" .
               "Amount: {$vars['amount']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Payment Gateway: {$vars['paymentmethod']}\n" .
               "Transaction ID: {$vars['transid']}";
    
    sendDualNotificationWithAlerts($title, $message, 3, 'credit_card,check_mark_button', 'payment', $vars);
});

// Invoice Refunded
add_hook('InvoiceRefunded', 1, function($vars) {
    $title = "↩️ Payment Refunded";
    $message = "Payment has been refunded:\n" .
               "Invoice ID: #{$vars['invoiceid']}\n" .
               "Refund Amount: {$vars['amount']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Gateway: {$vars['paymentmethod']}\n" .
               "Transaction ID: {$vars['transid']}\n" .
               "Reason: " . ($vars['reason'] ?? 'Not specified');
    
    sendDualNotificationWithAlerts($title, $message, 4, 'money_mouth_face,arrow_left', 'payment', $vars);
});

// Invoice Cancelled  
add_hook('InvoiceCancelled', 1, function($vars) {
    $title = "❌ Invoice Cancelled";
    $message = "Invoice has been cancelled:\n" .
               "Invoice ID: #{$vars['invoiceid']}\n" .
               "Amount: {$vars['total']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Due Date: {$vars['duedate']}\n" .
               "Reason: " . ($vars['reason'] ?? 'Not specified');
    
    sendDualNotificationWithAlerts($title, $message, 3, 'x,receipt', 'payment', $vars);
});

// Overdue Invoice Notice - CRITICAL
add_hook('InvoiceFirstOverdueNotice', 1, function($vars) {
    $daysPastDue = (time() - strtotime($vars['duedate'])) / 86400;
    
    $title = "🔴 Invoice Overdue";
    $message = "Invoice is now overdue:\n" .
               "Invoice ID: #{$vars['invoiceid']}\n" .
               "Amount: {$vars['total']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Due Date: {$vars['duedate']}\n" .
               "Days Overdue: " . round($daysPastDue) . "\n" .
               "Client ID: {$vars['userid']}";
    
    $priority = $daysPastDue > 30 ? 5 : 4;
    sendDualNotificationWithAlerts($title, $message, $priority, 'rotating_light,receipt', 'payment', $vars);
});

// Second Overdue Notice
add_hook('InvoiceSecondOverdueNotice', 1, function($vars) {
    $daysPastDue = (time() - strtotime($vars['duedate'])) / 86400;
    
    $title = "🚨 Invoice Severely Overdue";
    $message = "Invoice requires immediate attention:\n" .
               "Invoice ID: #{$vars['invoiceid']}\n" .
               "Amount: {$vars['total']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Due Date: {$vars['duedate']}\n" .
               "Days Overdue: " . round($daysPastDue) . "\n" .
               "⚠️ Consider service suspension";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,receipt', 'payment', $vars);
});

// Payment Reminder Sent
add_hook('InvoicePaymentReminder', 1, function($vars) {
    $title = "📧 Payment Reminder Sent";
    $message = "Payment reminder sent to client:\n" .
               "Invoice ID: #{$vars['invoiceid']}\n" .
               "Amount: {$vars['total']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Due Date: {$vars['duedate']}\n" .
               "Reminder Type: {$vars['type']}";
    
    sendDualNotificationWithAlerts($title, $message, 2, 'bell,receipt', 'payment', $vars);
});

// Quote Created
add_hook('QuoteCreated', 1, function($vars) {
    $title = "📋 New Quote Created";
    $message = "Quote has been generated:\n" .
               "Quote ID: #{$vars['quoteid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Total: {$vars['total']}\n" .
               "Valid Until: {$vars['validuntil']}";
    
    sendDualNotificationWithAlerts($title, $message, 2, 'memo,moneybag', 'quote', $vars);
});

// Quote Accepted
add_hook('QuoteAccepted', 1, function($vars) {
    $title = "✅ Quote Accepted";
    $message = "Client has accepted quote:\n" .
               "Quote ID: #{$vars['quoteid']}\n" .
               "Subject: {$vars['subject']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Total: {$vars['total']}\n" .
               "💰 Revenue opportunity!";
    
    sendDualNotificationWithAlerts($title, $message, 3, 'check_mark,moneybag', 'quote', $vars);
});

// Recurring Payment Success
add_hook('ServiceRecurringCompleted', 1, function($vars) {
    $title = "🔄 Recurring Payment Success";
    $message = "Recurring payment processed:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Product: {$vars['productname']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Amount: {$vars['amount']}\n" .
               "Next Due: {$vars['nextduedate']}";
    
    sendDualNotificationWithAlerts($title, $message, 2, 'arrows_clockwise,credit_card', 'payment', $vars);
});

// Recurring Payment Failed - CRITICAL
add_hook('ServiceRecurringFailed', 1, function($vars) {
    $title = "🚨 Recurring Payment Failed";
    $message = "CRITICAL: Recurring payment failed:\n" .
               "Service ID: {$vars['serviceid']}\n" .
               "Product: {$vars['productname']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Amount: {$vars['amount']}\n" .
               "Error: {$vars['error']}\n" .
               "⚠️ Service may be suspended";
    
    sendDualNotificationWithAlerts($title, $message, 5, 'rotating_light,credit_card', 'payment', $vars);
});

// Credit Added to Account
add_hook('AccountCreditAdded', 1, function($vars) {
    $title = "💰 Account Credit Added";
    $message = "Credit added to client account:\n" .
               "Client ID: {$vars['userid']}\n" .
               "Client: {$vars['firstname']} {$vars['lastname']}\n" .
               "Credit Amount: {$vars['amount']}\n" .
               "Description: {$vars['description']}\n" .
               "New Balance: {$vars['credit']}";
    
    sendDualNotificationWithAlerts($title, $message, 2, 'money_with_wings,plus', 'payment', $vars);
});

// Mass Payment Reminder
add_hook('MassPaymentReminderComplete', 1, function($vars) {
    $title = "📨 Mass Payment Reminders Sent";
    $message = "Mass payment reminder campaign completed:\n" .
               "Total Reminders: {$vars['total']}\n" .
               "Successful: {$vars['sent']}\n" .
               "Failed: {$vars['failed']}\n" .
               "Template: {$vars['template']}";
    
    $priority = $vars['failed'] > 0 ? 3 : 2;
    sendDualNotificationWithAlerts($title, $message, $priority, 'envelope_with_arrow,receipt', 'payment', $vars);
});
?>
