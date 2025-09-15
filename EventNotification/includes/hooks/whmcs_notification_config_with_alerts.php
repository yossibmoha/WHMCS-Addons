<?php
// Enhanced WHMCS Notification Configuration with Alert Management
require_once __DIR__ . '/../../classes/AlertManager.php';

// Environment-based configuration
$environment = $_ENV['WHMCS_ENV'] ?? 'production';

switch ($environment) {
    case 'development':
        define('NTFY_SERVER_URL', 'http://localhost:80');
        define('NTFY_TOPIC', 'whmcs-dev-alerts');
        define('NOTIFICATION_EMAIL', 'dev@yourdomain.com');
        break;
    
    case 'staging':
        define('NTFY_SERVER_URL', 'https://staging-ntfy.yourdomain.com');
        define('NTFY_TOPIC', 'whmcs-staging-alerts');
        define('NOTIFICATION_EMAIL', 'staging@yourdomain.com');
        break;
    
    default: // production
        define('NTFY_SERVER_URL', 'https://your-ntfy-server.com');
        define('NTFY_TOPIC', 'whmcs-alerts');
        define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');
        break;
}

// Alert management configuration
define('ALERT_MANAGEMENT_ENABLED', true);
define('DEDUPLICATION_WINDOW', 3600); // 1 hour

// Initialize AlertManager
$alertManager = new AlertManager(__DIR__ . '/../../');

/**
 * Enhanced notification function with alert management
 */
function sendDualNotificationWithAlerts($title, $message, $priority = 3, $tags = '', $source = 'whmcs', $metadata = []) {
    global $alertManager;
    
    // Create alert if management is enabled and priority is high enough
    if (ALERT_MANAGEMENT_ENABLED && $priority >= 3) {
        $alertId = $alertManager->createAlert($title, $message, $priority, $source, $metadata);
        
        // Add alert ID to metadata for tracking
        $metadata['alert_id'] = $alertId;
    }
    
    // Send notifications as usual
    return sendDualNotification($title, $message, $priority, $tags);
}

/**
 * Map severity levels to alert priorities
 */
function mapSeverityToAlertPriority($severity) {
    $mapping = [
        1 => 1, // Low -> Low
        2 => 2, // Medium-Low -> Medium-Low  
        3 => 3, // Medium -> Medium
        4 => 4, // High -> High
        5 => 5  // Critical -> Critical
    ];
    
    return $mapping[$severity] ?? 3;
}

/**
 * Create critical system alert
 */
function createCriticalAlert($title, $message, $metadata = []) {
    return sendDualNotificationWithAlerts(
        "🚨 CRITICAL: $title", 
        $message, 
        5, 
        'rotating_light,x', 
        'system', 
        $metadata
    );
}

/**
 * Create warning alert
 */
function createWarningAlert($title, $message, $metadata = []) {
    return sendDualNotificationWithAlerts(
        "⚠️ WARNING: $title", 
        $message, 
        4, 
        'warning,exclamation', 
        'system', 
        $metadata
    );
}

/**
 * Create info notification (no alert management)
 */
function createInfoNotification($title, $message, $metadata = []) {
    return sendDualNotification("ℹ️ INFO: $title", $message, 2, 'information_source');
}

// Existing notification functions for backward compatibility
function sendDualNotification($title, $message, $priority = 3, $tags = '') {
    // Send ntfy notification
    sendNtfyNotification($title, $message, $priority, $tags);
    
    // Send email notification
    sendEmailNotification($title, $message);
    
    return true;
}

function sendNtfyNotification($title, $message, $priority = 3, $tags = '') {
    $url = NTFY_SERVER_URL . '/' . NTFY_TOPIC;
    
    $data = json_encode([
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'tags' => array_filter(explode(',', $tags)),
        'actions' => [
            [
                'action' => 'view',
                'label' => 'Open Admin',
                'url' => 'https://yourdomain.com/admin/'
            ]
        ]
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: WHMCS-Notifier-Enhanced/2.0'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $result !== false && $httpCode === 200;
}

function sendEmailNotification($subject, $body) {
    $to = NOTIFICATION_EMAIL;
    $headers = "From: WHMCS Notifications <noreply@yourdomain.com>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $emailBody = "
    <html>
    <body>
        <h3>{$subject}</h3>
        <p>" . nl2br(htmlspecialchars($body)) . "</p>
        <hr>
        <p><small>Sent from WHMCS at " . date('Y-m-d H:i:s') . "</small></p>
    </body>
    </html>";
    
    return mail($to, "[WHMCS] " . $subject, $emailBody, $headers);
}

/**
 * Alert acknowledgment via URL (for web interface)
 */
function acknowledgeAlert($alertId, $user = 'web-user') {
    global $alertManager;
    
    if (ALERT_MANAGEMENT_ENABLED) {
        return $alertManager->acknowledgeAlert($alertId, $user);
    }
    
    return false;
}

/**
 * Alert resolution via URL (for web interface)  
 */
function resolveAlert($alertId, $user = 'web-user', $notes = '') {
    global $alertManager;
    
    if (ALERT_MANAGEMENT_ENABLED) {
        return $alertManager->resolveAlert($alertId, $user, $notes);
    }
    
    return false;
}

/**
 * Get open alerts for dashboard
 */
function getOpenAlerts($limit = 20) {
    global $alertManager;
    
    if (ALERT_MANAGEMENT_ENABLED) {
        return $alertManager->getOpenAlerts($limit);
    }
    
    return [];
}

/**
 * Get alert statistics for dashboard
 */
function getAlertStatistics($days = 7) {
    global $alertManager;
    
    if (ALERT_MANAGEMENT_ENABLED) {
        return $alertManager->getAlertStats($days);
    }
    
    return [];
}
?>
