<?php
// File: /includes/hooks/notification_config_enhanced.php

// Environment detection
$environment = $_ENV['WHMCS_ENV'] ?? 'production';

// Configuration based on environment
switch ($environment) {
    case 'development':
        define('NTFY_SERVER_URL', 'http://localhost:80');
        define('NTFY_TOPIC', 'whmcs-dev-alerts');
        define('NOTIFICATION_EMAIL', 'dev@yourdomain.com');
        define('NOTIFICATION_ENABLED', true);
        define('LOG_LEVEL', 'debug');
        break;
    
    case 'staging':
        define('NTFY_SERVER_URL', 'https://staging-ntfy.yourdomain.com');
        define('NTFY_TOPIC', 'whmcs-staging-alerts');
        define('NOTIFICATION_EMAIL', 'staging@yourdomain.com');
        define('NOTIFICATION_ENABLED', true);
        define('LOG_LEVEL', 'info');
        break;
    
    default: // production
        define('NTFY_SERVER_URL', 'https://your-ntfy-server.com');
        define('NTFY_TOPIC', 'whmcs-alerts');
        define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');
        define('NOTIFICATION_ENABLED', true);
        define('LOG_LEVEL', 'error');
        break;
}

// Rate limiting settings
define('RATE_LIMIT_WINDOW', 300); // 5 minutes
define('RATE_LIMIT_MAX', 10); // Max 10 notifications per window

// Alert thresholds
define('RESPONSE_TIME_THRESHOLD', 3000); // 3 seconds
define('DB_QUERY_THRESHOLD', 2000); // 2 seconds
define('SSL_EXPIRY_WARNING_DAYS', 30);
define('BACKUP_MAX_AGE_DAYS', 2);

class NotificationManager {
    private static $rateLimitLog = [];
    
    public static function sendDualNotification($title, $message, $priority = 3, $tags = '') {
        if (!NOTIFICATION_ENABLED) {
            return false;
        }
        
        // Rate limiting
        if (!self::checkRateLimit($title)) {
            self::logMessage("Rate limit exceeded for: $title", 'warning');
            return false;
        }
        
        // Send notifications
        $ntfyResult = self::sendNtfyNotification($title, $message, $priority, $tags);
        $emailResult = self::sendEmailNotification($title, $message, $priority);
        
        // Log the notification
        self::logMessage("Notification sent: $title", 'info');
        
        return ['ntfy' => $ntfyResult, 'email' => $emailResult];
    }
    
    private static function checkRateLimit($title) {
        $now = time();
        $key = md5($title);
        
        // Clean old entries
        self::$rateLimitLog = array_filter(self::$rateLimitLog, function($timestamp) use ($now) {
            return ($now - $timestamp) < RATE_LIMIT_WINDOW;
        });
        
        // Check current count
        $count = count(array_filter(self::$rateLimitLog, function($entry) use ($key) {
            return isset($entry[$key]);
        }));
        
        if ($count >= RATE_LIMIT_MAX) {
            return false;
        }
        
        // Add to log
        self::$rateLimitLog[] = [$key => $now];
        return true;
    }
    
    private static function sendNtfyNotification($title, $message, $priority = 3, $tags = '') {
        $url = NTFY_SERVER_URL . '/' . NTFY_TOPIC;
        
        $data = json_encode([
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'tags' => array_filter(explode(',', $tags)),
            'click' => 'https://yourdomain.com/admin', // Link back to admin panel
            'icon' => 'https://yourdomain.com/favicon.ico'
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: WHMCS-Notifier/1.0',
                // Add authentication if configured
                // 'Authorization: Bearer ' . NTFY_TOKEN
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($result === false || $httpCode !== 200) {
            self::logMessage("ntfy notification failed: $error (HTTP $httpCode)", 'error');
            return false;
        }
        
        return true;
    }
    
    private static function sendEmailNotification($subject, $body, $priority = 3) {
        // Skip low priority emails in production
        if ($priority < 3 && WHMCS_ENV === 'production') {
            return true;
        }
        
        $to = NOTIFICATION_EMAIL;
        $headers = [
            "From: WHMCS Notifications <noreply@yourdomain.com>",
            "Content-Type: text/html; charset=UTF-8",
            "X-Priority: " . (6 - $priority), // Convert to email priority (1=highest, 5=lowest)
            "X-MSMail-Priority: " . ($priority >= 4 ? 'High' : ($priority >= 3 ? 'Normal' : 'Low'))
        ];
        
        $priorityEmoji = $priority >= 4 ? '🚨' : ($priority >= 3 ? '⚠️' : 'ℹ️');
        
        $emailBody = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .header { background: #007cba; color: white; padding: 15px; border-radius: 5px; }
                .content { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; }
                .footer { color: #666; font-size: 12px; margin-top: 20px; }
                .priority-high { border-left: 5px solid #dc3545; }
                .priority-medium { border-left: 5px solid #ffc107; }
                .priority-low { border-left: 5px solid #28a745; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>{$priorityEmoji} WHMCS Alert: {$subject}</h2>
            </div>
            <div class='content priority-" . ($priority >= 4 ? 'high' : ($priority >= 3 ? 'medium' : 'low')) . "'>
                <p>" . nl2br(htmlspecialchars($body)) . "</p>
            </div>
            <div class='footer'>
                <p>Sent from WHMCS Monitoring System at " . date('Y-m-d H:i:s T') . "</p>
                <p>Priority Level: $priority/5 | Environment: " . strtoupper($environment) . "</p>
            </div>
        </body>
        </html>";
        
        return mail($to, "[WHMCS] $priorityEmoji $subject", $emailBody, implode("\r\n", $headers));
    }
    
    private static function logMessage($message, $level = 'info') {
        $logLevels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        $currentLevel = $logLevels[LOG_LEVEL] ?? 1;
        
        if (($logLevels[$level] ?? 1) >= $currentLevel) {
            $logEntry = date('Y-m-d H:i:s') . " [$level] $message" . PHP_EOL;
            file_put_contents(__DIR__ . '/../../../storage/logs/notifications.log', $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
}

// Compatibility functions for existing hooks
function sendDualNotification($title, $message, $priority = 3, $tags = '') {
    return NotificationManager::sendDualNotification($title, $message, $priority, $tags);
}

function sendNtfyNotification($title, $message, $priority = 3, $tags = '') {
    return NotificationManager::sendDualNotification($title, $message, $priority, $tags);
}

function sendEmailNotification($subject, $body) {
    return NotificationManager::sendDualNotification($subject, $body, 3, '');
}
?>
