<?php
/**
 * WHMCS Monitoring Addon Hooks
 * Integrates the monitoring system directly with WHMCS configuration
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Hook to update monitoring configuration when addon settings are saved
 */
add_hook('AddonConfigSave', 1, function($vars) {
    if ($vars['module'] !== 'monitoring') {
        return;
    }
    
    // Update the monitoring system configuration files
    updateMonitoringConfig($vars);
});

/**
 * Hook to add monitoring alerts to WHMCS admin notifications
 */
add_hook('AdminAreaPage', 1, function($vars) {
    if ($vars['templatefile'] === 'homepage' || $vars['templatefile'] === 'index') {
        // Add monitoring alerts to admin dashboard
        return addMonitoringAlertsToAdmin($vars);
    }
});

/**
 * Hook to add monitoring menu item to admin navigation
 */
add_hook('AdminAreaHeaderOutput', 1, function($vars) {
    return '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Add monitoring menu item
        var addonsMenu = document.querySelector(".nav-sidebar .nav-item:has(.nav-link[href*=\"addonmodules\"])");
        if (addonsMenu) {
            var monitoringItem = document.createElement("li");
            monitoringItem.className = "nav-item";
            monitoringItem.innerHTML = "<a href=\"addonmodules.php?module=monitoring\" class=\"nav-link\"><i class=\"fa fa-tachometer\"></i> <span>Monitoring</span></a>";
            addonsMenu.parentNode.insertBefore(monitoringItem, addonsMenu.nextSibling);
        }
    });
    </script>';
});

/**
 * Update monitoring system configuration files with WHMCS addon settings
 */
function updateMonitoringConfig($vars) {
    $configPath = dirname(__DIR__) . '/includes/hooks/whmcs_notification_config.php';
    
    if (!file_exists($configPath)) {
        return;
    }
    
    // Read current config
    $config = file_get_contents($configPath);
    
    // Update configuration values
    $updates = [
        'NTFY_SERVER_URL' => $vars['ntfy_server_url'] ?? 'https://your-ntfy-server.com',
        'NTFY_TOPIC' => $vars['ntfy_topic'] ?? 'whmcs-alerts',
        'NOTIFICATION_EMAIL' => $vars['notification_email'] ?? 'admin@yourdomain.com'
    ];
    
    foreach ($updates as $key => $value) {
        $pattern = '/define\(\'' . $key . '\',\s*[\'"][^\'"]*[\'\"]\);/';
        $replacement = "define('$key', '$value');";
        $config = preg_replace($pattern, $replacement, $config);
    }
    
    // Write updated config
    file_put_contents($configPath, $config);
    
    // Update environment variables if needed
    $envUpdates = [
        'WHMCS_ENV' => $vars['monitoring_environment'] ?? 'production',
        'ENABLE_ALERTS' => $vars['enable_alerts'] === 'on' ? 'true' : 'false',
        'ENABLE_HISTORICAL_DATA' => $vars['enable_historical_data'] === 'on' ? 'true' : 'false',
        'DATA_RETENTION_DAYS' => $vars['data_retention_days'] ?? '90'
    ];
    
    // Create/update .env file for the monitoring system
    $envPath = dirname(__DIR__) . '/.env';
    $envContent = '';
    
    foreach ($envUpdates as $key => $value) {
        $envContent .= "$key=$value\n";
    }
    
    file_put_contents($envPath, $envContent);
}

/**
 * Add monitoring alerts to WHMCS admin dashboard
 */
function addMonitoringAlertsToAdmin($vars) {
    try {
        require_once dirname(__DIR__) . '/classes/AlertManager.php';
        $alertManager = new AlertManager(dirname(__DIR__) . '/');
        
        $openAlerts = $alertManager->getOpenAlerts(5);
        
        if (empty($openAlerts)) {
            return;
        }
        
        $alertsHtml = '<div class="alert alert-info" style="margin: 20px;">
            <h4><i class="fa fa-exclamation-triangle"></i> Active Monitoring Alerts (' . count($openAlerts) . ')</h4>';
        
        foreach ($openAlerts as $alert) {
            $severityColor = $alert['severity'] >= 4 ? 'danger' : ($alert['severity'] >= 3 ? 'warning' : 'info');
            $alertsHtml .= '<div class="alert alert-' . $severityColor . '" style="margin: 5px 0; padding: 10px;">
                <strong>' . htmlspecialchars($alert['title']) . '</strong><br>
                <small>' . date('M j, H:i', strtotime($alert['created_at'])) . ' - Severity: ' . $alert['severity'] . '/5</small>
            </div>';
        }
        
        $alertsHtml .= '<p><a href="addonmodules.php?module=monitoring&action=alerts" class="btn btn-sm btn-primary">Manage All Alerts</a></p>
        </div>';
        
        return [
            'breadcrumb' => [],
            'templateVariables' => [
                'monitoring_alerts' => $alertsHtml
            ]
        ];
        
    } catch (Exception $e) {
        // Silently handle errors
        return;
    }
}

/**
 * Hook to automatically create monitoring alerts for critical WHMCS events
 */
add_hook('InvoicePaymentFailed', 1, function($vars) {
    createMonitoringAlert('Payment Failed', 
        "Payment failed for invoice #{$vars['invoiceid']} - Amount: {$vars['amount']}", 
        4, 'whmcs_payment');
});

add_hook('ServerCreate', 1, function($vars) {
    createMonitoringAlert('Server Created', 
        "New server created for client {$vars['userid']} - Product: {$vars['productname']}", 
        3, 'whmcs_server');
});

add_hook('AfterModuleCreate', 1, function($vars) {
    if ($vars['result'] !== 'success') {
        createMonitoringAlert('Module Creation Failed', 
            "Failed to create service for client {$vars['userid']} - Product: {$vars['productname']} - Error: {$vars['result']}", 
            4, 'whmcs_module');
    }
});

add_hook('TicketOpen', 1, function($vars) {
    $priority = 3;
    if (strpos(strtolower($vars['subject']), 'urgent') !== false || 
        strpos(strtolower($vars['subject']), 'critical') !== false) {
        $priority = 4;
    }
    
    createMonitoringAlert('New Support Ticket', 
        "New ticket #{$vars['ticketid']} from client {$vars['userid']} - Subject: {$vars['subject']}", 
        $priority, 'whmcs_support');
});

/**
 * Create monitoring alert
 */
function createMonitoringAlert($title, $message, $severity, $source) {
    try {
        require_once dirname(__DIR__) . '/classes/AlertManager.php';
        $alertManager = new AlertManager(dirname(__DIR__) . '/');
        
        return $alertManager->createAlert($title, $message, $severity, $source);
    } catch (Exception $e) {
        // Log error but don't interrupt WHMCS operations
        logActivity("Monitoring Alert Error: " . $e->getMessage());
        return false;
    }
}
?>
