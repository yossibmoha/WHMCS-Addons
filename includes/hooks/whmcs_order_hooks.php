<?php
// File: /includes/hooks/order_notifications.php

require_once __DIR__ . '/notification_config.php';

// New Order Placed
add_hook('ShoppingCartCheckoutCompletePage', 1, function($vars) {
    $title = "💰 New Order Placed";
    $message = "Order details:\n" .
               "Order ID: {$vars['orderid']}\n" .
               "Client: {$vars['clientsdetails']['firstname']} {$vars['clientsdetails']['lastname']}\n" .
               "Email: {$vars['clientsdetails']['email']}\n" .
               "Total: {$vars['total']}";
    
    sendDualNotification($title, $message, 4, 'money_bag,shopping_cart');
});

// Order Status Changes
add_hook('OrderStatusChange', 1, function($vars) {
    $title = "Order Status Changed";
    $message = "Order #{$vars['orderid']} status changed to: {$vars['status']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Product: {$vars['productname']}";
    
    $priority = ($vars['status'] == 'Active') ? 3 : 2;
    sendDualNotification($title, $message, $priority, 'package,arrow_right');
});

// Payment Success - CORRECTED HOOK NAME
add_hook('InvoicePaid', 1, function($vars) {
    $title = "💳 Payment Received";
    $message = "Payment received:\n" .
               "Invoice ID: {$vars['invoiceid']}\n" .
               "Amount: {$vars['amount']}\n" .
               "Gateway: {$vars['paymentmethod']}\n" .
               "Transaction ID: {$vars['transid']}";
    
    sendDualNotification($title, $message, 3, 'credit_card,check_mark_button');
});

// Invoice Created
add_hook('InvoiceCreated', 1, function($vars) {
    $title = "📋 New Invoice Created";
    $message = "Invoice created:\n" .
               "Invoice ID: {$vars['invoiceid']}\n" .
               "Client ID: {$vars['userid']}\n" .
               "Amount: {$vars['total']}";
    
    sendDualNotification($title, $message, 2, 'receipt,memo');
});

// Failed Payment
add_hook('InvoicePaymentFailed', 1, function($vars) {
    $title = "❌ Payment Failed";
    $message = "Payment failed:\n" .
               "Invoice ID: {$vars['invoiceid']}\n" .
               "Amount: {$vars['amount']}\n" .
               "Gateway: {$vars['paymentmethod']}\n" .
               "Reason: {$vars['reason']}";
    
    sendDualNotification($title, $message, 5, 'x,credit_card');
});
?>