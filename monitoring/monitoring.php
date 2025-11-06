<?php
/**
 * WHMCS Monitoring System Addon
 * Allows complete configuration and management from within WHMCS admin panel
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Addon configuration
 */
function monitoring_config() {
    return [
        'name' => 'WHMCS Monitoring System',
        'description' => 'Complete monitoring and alert management system with iPhone notifications, historical analytics, and enterprise-grade alerting.',
        'version' => '2.0',
        'author' => 'WHMCS Monitoring Team',
        'language' => 'english',
        'fields' => [
            'ntfy_server_url' => [
                'FriendlyName' => 'ntfy Server URL',
                'Type' => 'text',
                'Size' => '50',
                'Default' => 'https://your-ntfy-server.com',
                'Description' => 'URL of your ntfy notification server'
            ],
            'ntfy_topic' => [
                'FriendlyName' => 'Default ntfy Topic',
                'Type' => 'text',
                'Size' => '30',
                'Default' => 'whmcs-alerts',
                'Description' => 'Default topic for notifications'
            ],
            'notification_email' => [
                'FriendlyName' => 'Notification Email',
                'Type' => 'text',
                'Size' => '50',
                'Default' => 'admin@yourdomain.com',
                'Description' => 'Primary email for notifications'
            ],
            'enable_alerts' => [
                'FriendlyName' => 'Enable Alert Management',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Enable intelligent alert management with escalation'
            ],
            'enable_historical_data' => [
                'FriendlyName' => 'Enable Historical Data Collection',
                'Type' => 'yesno', 
                'Default' => 'on',
                'Description' => 'Collect and store historical performance data'
            ],
            'data_retention_days' => [
                'FriendlyName' => 'Data Retention (Days)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '90',
                'Description' => 'How many days to keep historical data'
            ],
            'monitoring_environment' => [
                'FriendlyName' => 'Environment',
                'Type' => 'dropdown',
                'Options' => 'development,staging,production',
                'Default' => 'production',
                'Description' => 'Current environment for appropriate notifications'
            ]
        ]
    ];
}

/**
 * Activate addon - create necessary database tables
 */
function monitoring_activate() {
    try {
        // Create configuration table
        full_query("CREATE TABLE IF NOT EXISTS mod_monitoring_config (
            id int(10) unsigned NOT NULL auto_increment,
            config_key varchar(255) NOT NULL,
            config_value text,
            config_group varchar(100) DEFAULT 'general',
            description text,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_key (config_key)
        )");
        
        // Create alert rules table
        full_query("CREATE TABLE IF NOT EXISTS mod_monitoring_alert_rules (
            id int(10) unsigned NOT NULL auto_increment,
            rule_name varchar(255) NOT NULL,
            event_type varchar(100) NOT NULL,
            severity int(1) DEFAULT 3,
            enabled tinyint(1) DEFAULT 1,
            notification_methods text,
            escalation_minutes int(10) DEFAULT 0,
            conditions text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )");
        
        // Create escalation contacts table
        full_query("CREATE TABLE IF NOT EXISTS mod_monitoring_contacts (
            id int(10) unsigned NOT NULL auto_increment,
            contact_name varchar(255) NOT NULL,
            contact_email varchar(255),
            contact_phone varchar(50),
            ntfy_topic varchar(100),
            priority_level int(1) DEFAULT 1,
            schedule_start time DEFAULT '00:00:00',
            schedule_end time DEFAULT '23:59:59',
            schedule_days varchar(20) DEFAULT '1,2,3,4,5,6,7',
            enabled tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )");
        
        // Create monitoring thresholds table
        full_query("CREATE TABLE IF NOT EXISTS mod_monitoring_thresholds (
            id int(10) unsigned NOT NULL auto_increment,
            metric_name varchar(100) NOT NULL,
            warning_threshold decimal(10,2),
            critical_threshold decimal(10,2),
            unit varchar(20),
            enabled tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY unique_metric (metric_name)
        )");
        
        // Insert default configurations
        $defaultConfigs = [
            ['response_time_warning', '3000', 'thresholds', 'Website response time warning threshold (ms)'],
            ['response_time_critical', '5000', 'thresholds', 'Website response time critical threshold (ms)'],
            ['cpu_warning_threshold', '80', 'thresholds', 'CPU usage warning threshold (%)'],
            ['cpu_critical_threshold', '95', 'thresholds', 'CPU usage critical threshold (%)'],
            ['memory_warning_threshold', '85', 'thresholds', 'Memory usage warning threshold (%)'],
            ['memory_critical_threshold', '95', 'thresholds', 'Memory usage critical threshold (%)'],
            ['disk_warning_threshold', '80', 'thresholds', 'Disk usage warning threshold (%)'],
            ['disk_critical_threshold', '90', 'thresholds', 'Disk usage critical threshold (%)'],
            ['ssl_expiry_warning_days', '30', 'thresholds', 'SSL certificate expiry warning (days)'],
            ['enable_user_events', '1', 'monitoring', 'Monitor user registration and login events'],
            ['enable_order_events', '1', 'monitoring', 'Monitor order and payment events'],
            ['enable_support_events', '1', 'monitoring', 'Monitor support ticket events'],
            ['enable_server_events', '1', 'monitoring', 'Monitor server provisioning events'],
            ['enable_system_events', '1', 'monitoring', 'Monitor system errors and admin access'],
            ['dashboard_refresh_interval', '30', 'dashboard', 'Dashboard auto-refresh interval (seconds)'],
            ['max_alerts_display', '50', 'dashboard', 'Maximum alerts to display in dashboard']
        ];
        
        foreach ($defaultConfigs as $config) {
            full_query("INSERT IGNORE INTO mod_monitoring_config 
                (config_key, config_value, config_group, description) 
                VALUES ('{$config[0]}', '{$config[1]}', '{$config[2]}', '{$config[3]}')");
        }
        
        // Insert default thresholds
        $defaultThresholds = [
            ['cpu_usage', 80.0, 95.0, '%'],
            ['memory_usage', 85.0, 95.0, '%'],
            ['disk_usage', 80.0, 90.0, '%'],
            ['response_time', 3000.0, 5000.0, 'ms'],
            ['db_query_time', 500.0, 2000.0, 'ms']
        ];
        
        foreach ($defaultThresholds as $threshold) {
            full_query("INSERT IGNORE INTO mod_monitoring_thresholds 
                (metric_name, warning_threshold, critical_threshold, unit) 
                VALUES ('{$threshold[0]}', {$threshold[1]}, {$threshold[2]}, '{$threshold[3]}')");
        }
        
        // Insert default contact (admin)
        full_query("INSERT IGNORE INTO mod_monitoring_contacts 
            (contact_name, contact_email, priority_level) 
            VALUES ('Primary Admin', 'admin@yourdomain.com', 1)");
        
        return [
            'status' => 'success',
            'description' => 'WHMCS Monitoring System addon activated successfully! Database tables created and default configurations installed.'
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Failed to activate addon: ' . $e->getMessage()
        ];
    }
}

/**
 * Deactivate addon
 */
function monitoring_deactivate() {
    // Note: We don't drop tables on deactivate to preserve data
    return [
        'status' => 'success',
        'description' => 'WHMCS Monitoring System addon deactivated. Data preserved.'
    ];
}

/**
 * Upgrade addon
 */
function monitoring_upgrade($vars) {
    // Handle version upgrades here if needed
    return [
        'status' => 'success',
        'description' => 'Addon upgraded successfully'
    ];
}

/**
 * Admin area output - main dashboard
 */
function monitoring_output($vars) {
    $action = $_GET['action'] ?? 'dashboard';
    
    echo '<div class="addon-content">';
    echo '<style>
        .monitoring-nav { margin-bottom: 20px; }
        .monitoring-nav a { 
            display: inline-block; 
            padding: 8px 16px; 
            margin-right: 10px; 
            background: #f8f9fa; 
            border: 1px solid #dee2e6; 
            text-decoration: none; 
            border-radius: 4px;
        }
        .monitoring-nav a.active { background: #007bff; color: white; }
        .monitoring-card { 
            background: white; 
            border: 1px solid #dee2e6; 
            border-radius: 8px; 
            padding: 20px; 
            margin-bottom: 20px; 
        }
        .monitoring-stats { display: flex; gap: 20px; margin-bottom: 20px; }
        .stat-card { 
            flex: 1; 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 6px; 
            text-align: center; 
        }
        .stat-number { font-size: 2em; font-weight: bold; color: #007bff; }
        .stat-label { color: #666; }
        .alert-high { color: #dc3545; }
        .alert-medium { color: #ffc107; }
        .alert-low { color: #28a745; }
        .status-healthy { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-critical { color: #dc3545; }
        .config-section { margin-bottom: 30px; }
        .form-table { width: 100%; }
        .form-table td { padding: 10px; border-bottom: 1px solid #eee; }
    </style>';
    
    // Navigation
    echo '<div class="monitoring-nav">';
    $navItems = [
        'dashboard' => 'Dashboard',
        'alerts' => 'Active Alerts',
        'configuration' => 'Configuration', 
        'thresholds' => 'Thresholds',
        'contacts' => 'Contacts & Escalation',
        'analytics' => 'Analytics',
        'system_status' => 'System Status'
    ];
    
    foreach ($navItems as $key => $label) {
        $activeClass = ($action === $key) ? 'active' : '';
        echo "<a href=\"addonmodules.php?module=monitoring&action=$key\" class=\"$activeClass\">$label</a>";
    }
    echo '</div>';
    
    switch ($action) {
        case 'dashboard':
            showDashboard($vars);
            break;
        case 'alerts':
            showAlerts($vars);
            break;
        case 'configuration':
            showConfiguration($vars);
            break;
        case 'thresholds':
            showThresholds($vars);
            break;
        case 'contacts':
            showContacts($vars);
            break;
        case 'analytics':
            showAnalytics($vars);
            break;
        case 'system_status':
            showSystemStatus($vars);
            break;
        default:
            showDashboard($vars);
    }
    
    echo '</div>';
}

/**
 * Show main dashboard
 */
function showDashboard($vars) {
    echo '<h2>🚀 WHMCS Monitoring System Dashboard</h2>';
    
    // Quick stats
    echo '<div class="monitoring-stats">';
    
    // Get open alerts count
    try {
        require_once dirname(__DIR__) . '/classes/AlertManager.php';
        $alertManager = new AlertManager(dirname(__DIR__) . '/');
        $openAlerts = $alertManager->getOpenAlerts(100);
        $alertStats = $alertManager->getAlertStats(7);
        
        echo '<div class="stat-card">';
        echo '<div class="stat-number alert-high">' . count($openAlerts) . '</div>';
        echo '<div class="stat-label">Open Alerts</div>';
        echo '</div>';
        
        echo '<div class="stat-card">';
        $resolvedToday = $alertStats['by_status']['resolved'] ?? 0;
        echo '<div class="stat-number alert-low">' . $resolvedToday . '</div>';
        echo '<div class="stat-label">Resolved (7 days)</div>';
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="stat-card">';
        echo '<div class="stat-number">-</div>';
        echo '<div class="stat-label">Alerts (Unavailable)</div>';
        echo '</div>';
    }
    
    // System status
    echo '<div class="stat-card">';
    $systemStatus = checkMonitoringSystemHealth();
    echo '<div class="stat-number status-' . $systemStatus['status'] . '">●</div>';
    echo '<div class="stat-label">System Status</div>';
    echo '</div>';
    
    echo '<div class="stat-card">';
    echo '<div class="stat-number">' . $vars['monitoring_environment'] . '</div>';
    echo '<div class="stat-label">Environment</div>';
    echo '</div>';
    
    echo '</div>';
    
    // Recent alerts
    echo '<div class="monitoring-card">';
    echo '<h3>Recent Alerts</h3>';
    
    if (isset($openAlerts) && !empty($openAlerts)) {
        echo '<table class="table">';
        echo '<tr><th>Time</th><th>Title</th><th>Severity</th><th>Status</th><th>Actions</th></tr>';
        
        foreach (array_slice($openAlerts, 0, 10) as $alert) {
            $severityClass = $alert['severity'] >= 4 ? 'alert-high' : ($alert['severity'] >= 3 ? 'alert-medium' : 'alert-low');
            echo '<tr>';
            echo '<td>' . date('m/d H:i', strtotime($alert['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($alert['title']) . '</td>';
            echo '<td><span class="' . $severityClass . '">' . $alert['severity'] . '/5</span></td>';
            echo '<td>' . ucfirst($alert['status']) . '</td>';
            echo '<td>';
            if ($alert['status'] === 'open') {
                echo '<a href="#" onclick="acknowledgeAlert(\'' . $alert['alert_id'] . '\')">Acknowledge</a> | ';
                echo '<a href="#" onclick="resolveAlert(\'' . $alert['alert_id'] . '\')">Resolve</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>No active alerts - all systems operational! ✅</p>';
    }
    echo '</div>';
    
    // Quick configuration check
    echo '<div class="monitoring-card">';
    echo '<h3>Configuration Status</h3>';
    
    $configChecks = [
        'ntfy_server_url' => 'ntfy Server URL configured',
        'notification_email' => 'Notification email set',
        'enable_alerts' => 'Alert management enabled',
        'enable_historical_data' => 'Historical data collection enabled'
    ];
    
    foreach ($configChecks as $key => $description) {
        $value = $vars[$key] ?? 'Not set';
        $status = !empty($value) && $value !== 'Not set' ? '✅' : '⚠️';
        echo "<p>$status $description: <strong>$value</strong></p>";
    }
    echo '</div>';
    
    // JavaScript for alert actions
    echo '<script>
    function acknowledgeAlert(alertId) {
        if (confirm("Acknowledge this alert?")) {
            fetch("/modules/addons/monitoring/api.php", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({action: "acknowledge", alert_id: alertId, user: "admin"})
            }).then(response => response.json())
              .then(data => {
                  alert(data.message || "Alert acknowledged");
                  location.reload();
              });
        }
    }
    
    function resolveAlert(alertId) {
        var notes = prompt("Resolution notes (optional):");
        if (notes !== null) {
            fetch("/modules/addons/monitoring/api.php", {
                method: "POST", 
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({action: "resolve", alert_id: alertId, user: "admin", notes: notes})
            }).then(response => response.json())
              .then(data => {
                  alert(data.message || "Alert resolved");
                  location.reload();
              });
        }
    }
    </script>';
}

/**
 * Show active alerts
 */
function showAlerts($vars) {
    echo '<h2>🚨 Active Alerts Management</h2>';
    
    try {
        require_once dirname(__DIR__) . '/classes/AlertManager.php';
        $alertManager = new AlertManager(dirname(__DIR__) . '/');
        $alerts = $alertManager->getOpenAlerts(100);
        
        if (empty($alerts)) {
            echo '<div class="monitoring-card">';
            echo '<p>🎉 No active alerts! All systems are running smoothly.</p>';
            echo '</div>';
            return;
        }
        
        echo '<div class="monitoring-card">';
        echo '<table class="table table-striped">';
        echo '<thead><tr>';
        echo '<th>Created</th><th>Title</th><th>Severity</th><th>Status</th><th>Escalation Level</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($alerts as $alert) {
            $severityClass = $alert['severity'] >= 4 ? 'alert-high' : ($alert['severity'] >= 3 ? 'alert-medium' : 'alert-low');
            $statusClass = $alert['status'] === 'open' ? 'status-critical' : 'status-warning';
            
            echo '<tr>';
            echo '<td>' . date('Y-m-d H:i', strtotime($alert['created_at'])) . '</td>';
            echo '<td title="' . htmlspecialchars($alert['message']) . '">' . htmlspecialchars(substr($alert['title'], 0, 60)) . '</td>';
            echo '<td><span class="' . $severityClass . '">' . $alert['severity'] . '/5</span></td>';
            echo '<td><span class="' . $statusClass . '">' . ucfirst($alert['status']) . '</span></td>';
            echo '<td>' . ($alert['escalation_level'] ?? 0) . '</td>';
            echo '<td>';
            
            if ($alert['status'] === 'open') {
                echo '<button onclick="acknowledgeAlert(\'' . $alert['alert_id'] . '\')" class="btn btn-sm btn-warning">Acknowledge</button> ';
            }
            echo '<button onclick="resolveAlert(\'' . $alert['alert_id'] . '\')" class="btn btn-sm btn-success">Resolve</button>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error loading alerts: ' . $e->getMessage() . '</div>';
    }
}

/**
 * Show configuration page
 */
function showConfiguration($vars) {
    echo '<h2>⚙️ System Configuration</h2>';
    
    if ($_POST['save_config']) {
        saveConfiguration($_POST);
        echo '<div class="alert alert-success">Configuration saved successfully!</div>';
    }
    
    echo '<form method="post">';
    echo '<input type="hidden" name="save_config" value="1">';
    
    // Notification Settings
    echo '<div class="monitoring-card">';
    echo '<h3>📱 Notification Settings</h3>';
    echo '<table class="form-table">';
    
    $notificationFields = [
        'ntfy_server_url' => 'ntfy Server URL',
        'ntfy_topic' => 'Default ntfy Topic', 
        'notification_email' => 'Primary Email',
        'monitoring_environment' => 'Environment'
    ];
    
    foreach ($notificationFields as $key => $label) {
        echo '<tr>';
        echo '<td><label for="' . $key . '">' . $label . ':</label></td>';
        echo '<td>';
        
        if ($key === 'monitoring_environment') {
            echo '<select name="' . $key . '" class="form-control">';
            $environments = ['development', 'staging', 'production'];
            foreach ($environments as $env) {
                $selected = ($vars[$key] === $env) ? 'selected' : '';
                echo "<option value=\"$env\" $selected>" . ucfirst($env) . "</option>";
            }
            echo '</select>';
        } else {
            echo '<input type="text" name="' . $key . '" value="' . htmlspecialchars($vars[$key]) . '" class="form-control" style="width: 400px;">';
        }
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</div>';
    
    // Monitoring Features
    echo '<div class="monitoring-card">';
    echo '<h3>📊 Monitoring Features</h3>';
    echo '<table class="form-table">';
    
    $featureFields = [
        'enable_alerts' => 'Enable Alert Management',
        'enable_historical_data' => 'Enable Historical Data Collection',
        'data_retention_days' => 'Data Retention (Days)'
    ];
    
    foreach ($featureFields as $key => $label) {
        echo '<tr>';
        echo '<td><label for="' . $key . '">' . $label . ':</label></td>';
        echo '<td>';
        
        if (strpos($key, 'enable_') === 0) {
            $checked = ($vars[$key] === 'on') ? 'checked' : '';
            echo '<input type="checkbox" name="' . $key . '" value="on" ' . $checked . '> Enable';
        } else {
            echo '<input type="text" name="' . $key . '" value="' . htmlspecialchars($vars[$key]) . '" class="form-control" style="width: 100px;">';
        }
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</div>';
    
    echo '<button type="submit" class="btn btn-primary">Save Configuration</button>';
    echo '</form>';
}

/**
 * Show thresholds configuration
 */
function showThresholds($vars) {
    echo '<h2>📈 Performance Thresholds</h2>';
    
    if ($_POST['save_thresholds']) {
        saveThresholds($_POST);
        echo '<div class="alert alert-success">Thresholds updated successfully!</div>';
    }
    
    // Load current thresholds
    $result = full_query("SELECT * FROM mod_monitoring_thresholds ORDER BY metric_name");
    
    echo '<form method="post">';
    echo '<input type="hidden" name="save_thresholds" value="1">';
    echo '<div class="monitoring-card">';
    echo '<table class="table">';
    echo '<tr><th>Metric</th><th>Warning Threshold</th><th>Critical Threshold</th><th>Unit</th><th>Enabled</th></tr>';
    
    while ($threshold = mysql_fetch_array($result)) {
        echo '<tr>';
        echo '<td><strong>' . ucfirst(str_replace('_', ' ', $threshold['metric_name'])) . '</strong></td>';
        echo '<td><input type="number" name="warning[' . $threshold['metric_name'] . ']" value="' . $threshold['warning_threshold'] . '" step="0.01" style="width: 100px;"></td>';
        echo '<td><input type="number" name="critical[' . $threshold['metric_name'] . ']" value="' . $threshold['critical_threshold'] . '" step="0.01" style="width: 100px;"></td>';
        echo '<td>' . $threshold['unit'] . '</td>';
        echo '<td><input type="checkbox" name="enabled[' . $threshold['metric_name'] . ']" value="1" ' . ($threshold['enabled'] ? 'checked' : '') . '></td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<button type="submit" class="btn btn-primary">Update Thresholds</button>';
    echo '</div>';
    echo '</form>';
}

/**
 * Show contacts and escalation
 */
function showContacts($vars) {
    echo '<h2>👥 Contacts & Escalation</h2>';
    
    if ($_POST['save_contact']) {
        saveContact($_POST);
        echo '<div class="alert alert-success">Contact saved successfully!</div>';
    }
    
    if ($_POST['delete_contact']) {
        deleteContact($_POST['contact_id']);
        echo '<div class="alert alert-success">Contact deleted successfully!</div>';
    }
    
    // Add/Edit contact form
    $editContact = null;
    if ($_GET['edit']) {
        $result = full_query("SELECT * FROM mod_monitoring_contacts WHERE id = " . (int)$_GET['edit']);
        $editContact = mysql_fetch_array($result);
    }
    
    echo '<div class="monitoring-card">';
    echo '<h3>' . ($editContact ? 'Edit' : 'Add') . ' Contact</h3>';
    echo '<form method="post">';
    echo '<input type="hidden" name="save_contact" value="1">';
    if ($editContact) {
        echo '<input type="hidden" name="contact_id" value="' . $editContact['id'] . '">';
    }
    
    echo '<table class="form-table">';
    echo '<tr><td>Name:</td><td><input type="text" name="contact_name" value="' . ($editContact['contact_name'] ?? '') . '" required style="width: 300px;"></td></tr>';
    echo '<tr><td>Email:</td><td><input type="email" name="contact_email" value="' . ($editContact['contact_email'] ?? '') . '" style="width: 300px;"></td></tr>';
    echo '<tr><td>Phone:</td><td><input type="text" name="contact_phone" value="' . ($editContact['contact_phone'] ?? '') . '" style="width: 200px;"></td></tr>';
    echo '<tr><td>ntfy Topic:</td><td><input type="text" name="ntfy_topic" value="' . ($editContact['ntfy_topic'] ?? '') . '" style="width: 200px;"></td></tr>';
    echo '<tr><td>Priority Level:</td><td><select name="priority_level"><option value="1" ' . (($editContact['priority_level'] ?? 1) == 1 ? 'selected' : '') . '>1 (Highest)</option><option value="2" ' . (($editContact['priority_level'] ?? 1) == 2 ? 'selected' : '') . '>2</option><option value="3" ' . (($editContact['priority_level'] ?? 1) == 3 ? 'selected' : '') . '>3 (Lowest)</option></select></td></tr>';
    echo '<tr><td>Enabled:</td><td><input type="checkbox" name="enabled" value="1" ' . (($editContact['enabled'] ?? 1) ? 'checked' : '') . '></td></tr>';
    echo '</table>';
    
    echo '<button type="submit" class="btn btn-primary">' . ($editContact ? 'Update' : 'Add') . ' Contact</button>';
    if ($editContact) {
        echo ' <a href="addonmodules.php?module=monitoring&action=contacts" class="btn btn-secondary">Cancel</a>';
    }
    echo '</form>';
    echo '</div>';
    
    // List existing contacts
    echo '<div class="monitoring-card">';
    echo '<h3>Current Contacts</h3>';
    
    $result = full_query("SELECT * FROM mod_monitoring_contacts ORDER BY priority_level, contact_name");
    
    if (mysql_num_rows($result) > 0) {
        echo '<table class="table">';
        echo '<tr><th>Name</th><th>Email</th><th>Phone</th><th>ntfy Topic</th><th>Priority</th><th>Enabled</th><th>Actions</th></tr>';
        
        while ($contact = mysql_fetch_array($result)) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($contact['contact_name']) . '</td>';
            echo '<td>' . htmlspecialchars($contact['contact_email']) . '</td>';
            echo '<td>' . htmlspecialchars($contact['contact_phone']) . '</td>';
            echo '<td>' . htmlspecialchars($contact['ntfy_topic']) . '</td>';
            echo '<td>' . $contact['priority_level'] . '</td>';
            echo '<td>' . ($contact['enabled'] ? 'Yes' : 'No') . '</td>';
            echo '<td>';
            echo '<a href="addonmodules.php?module=monitoring&action=contacts&edit=' . $contact['id'] . '">Edit</a> | ';
            echo '<a href="#" onclick="if(confirm(\'Delete this contact?\')) { deleteContact(' . $contact['id'] . '); }">Delete</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>No contacts configured yet.</p>';
    }
    
    echo '</div>';
    
    echo '<script>
    function deleteContact(id) {
        var form = document.createElement("form");
        form.method = "POST";
        form.innerHTML = "<input type=\'hidden\' name=\'delete_contact\' value=\'1\'><input type=\'hidden\' name=\'contact_id\' value=\'" + id + "\'>";
        document.body.appendChild(form);
        form.submit();
    }
    </script>';
}

/**
 * Show analytics page
 */
function showAnalytics($vars) {
    echo '<h2>📊 Analytics & Reports</h2>';
    
    try {
        require_once dirname(__DIR__) . '/classes/HistoricalDataManager.php';
        $historyManager = new HistoricalDataManager(dirname(__DIR__) . '/');
        
        $days = (int)($_GET['days'] ?? 7);
        $summary = $historyManager->getPerformanceSummary($days * 24);
        
        echo '<div class="monitoring-card">';
        echo '<h3>Performance Summary (Last ' . $days . ' days)</h3>';
        
        echo '<div style="margin-bottom: 20px;">';
        echo '<a href="?module=whmcs_monitoring&action=analytics&days=1" class="btn ' . ($days == 1 ? 'btn-primary' : 'btn-secondary') . '">1 Day</a> ';
        echo '<a href="?module=whmcs_monitoring&action=analytics&days=7" class="btn ' . ($days == 7 ? 'btn-primary' : 'btn-secondary') . '">7 Days</a> ';
        echo '<a href="?module=whmcs_monitoring&action=analytics&days=30" class="btn ' . ($days == 30 ? 'btn-primary' : 'btn-secondary') . '">30 Days</a>';
        echo '</div>';
        
        if ($summary && isset($summary['whmcs_metrics'])) {
            echo '<table class="table">';
            echo '<tr><th>Metric</th><th>Average</th><th>Minimum</th><th>Maximum</th></tr>';
            
            $metrics = [
                'avg_response_time' => 'Response Time (ms)',
                'avg_db_time' => 'Database Query Time (ms)', 
                'avg_open_tickets' => 'Open Tickets',
            ];
            
            foreach ($metrics as $key => $label) {
                if (isset($summary['whmcs_metrics'][$key])) {
                    echo '<tr>';
                    echo '<td>' . $label . '</td>';
                    echo '<td>' . round($summary['whmcs_metrics'][$key], 2) . '</td>';
                    echo '<td>' . round($summary['whmcs_metrics']['min_' . str_replace('avg_', '', $key)] ?? 0, 2) . '</td>';
                    echo '<td>' . round($summary['whmcs_metrics']['max_' . str_replace('avg_', '', $key)] ?? 0, 2) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</table>';
        } else {
            echo '<p>No historical data available yet. Data collection may need to be enabled or running for some time.</p>';
        }
        
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error loading analytics: ' . $e->getMessage() . '</div>';
    }
}

/**
 * Show system status
 */
function showSystemStatus($vars) {
    echo '<h2>🔍 System Status</h2>';
    
    $status = checkMonitoringSystemHealth();
    
    echo '<div class="monitoring-card">';
    echo '<h3>Overall System Health</h3>';
    echo '<p class="status-' . $status['status'] . '">Status: <strong>' . strtoupper($status['status']) . '</strong></p>';
    
    if (!empty($status['issues'])) {
        echo '<h4>Issues Detected:</h4>';
        echo '<ul>';
        foreach ($status['issues'] as $issue) {
            echo '<li>' . $issue . '</li>';
        }
        echo '</ul>';
    }
    
    echo '<h4>Component Status:</h4>';
    foreach ($status['components'] as $component => $componentStatus) {
        $icon = $componentStatus ? '✅' : '❌';
        echo "<p>$icon $component</p>";
    }
    echo '</div>';
    
    // Test connectivity
    echo '<div class="monitoring-card">';
    echo '<h3>Connectivity Tests</h3>';
    
    if ($vars['ntfy_server_url']) {
        echo '<p>Testing ntfy server: ' . $vars['ntfy_server_url'] . '</p>';
        $ntfyStatus = testConnectivity($vars['ntfy_server_url']);
        echo '<p>' . ($ntfyStatus ? '✅' : '❌') . ' ntfy Server Connectivity</p>';
    }
    
    echo '</div>';
}

/**
 * Helper function to check monitoring system health
 */
function checkMonitoringSystemHealth() {
    $status = [
        'status' => 'healthy',
        'components' => [],
        'issues' => []
    ];
    
    // Check if core classes exist
    $coreFiles = [
        'AlertManager' => dirname(__DIR__) . '/classes/AlertManager.php',
        'HistoricalDataManager' => dirname(__DIR__) . '/classes/HistoricalDataManager.php'
    ];
    
    foreach ($coreFiles as $name => $file) {
        $exists = file_exists($file);
        $status['components'][$name] = $exists;
        if (!$exists) {
            $status['issues'][] = "$name class file missing";
            $status['status'] = 'critical';
        }
    }
    
    // Check database tables
    $tables = [
        'mod_monitoring_config',
        'mod_monitoring_alert_rules', 
        'mod_monitoring_contacts',
        'mod_monitoring_thresholds'
    ];
    
    foreach ($tables as $table) {
        $result = full_query("SHOW TABLES LIKE '$table'");
        $exists = mysql_num_rows($result) > 0;
        $status['components']["Database: $table"] = $exists;
        if (!$exists) {
            $status['issues'][] = "Database table $table missing";
            if ($status['status'] === 'healthy') {
                $status['status'] = 'warning';
            }
        }
    }
    
    return $status;
}

/**
 * Test connectivity to a URL
 */
function testConnectivity($url, $timeout = 5) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $result !== false && $httpCode >= 200 && $httpCode < 400;
}

/**
 * Save configuration
 */
function saveConfiguration($data) {
    $configFields = [
        'ntfy_server_url', 'ntfy_topic', 'notification_email', 'monitoring_environment',
        'enable_alerts', 'enable_historical_data', 'data_retention_days'
    ];
    
    foreach ($configFields as $field) {
        if (isset($data[$field])) {
            $value = mysql_real_escape_string($data[$field]);
            full_query("INSERT INTO mod_monitoring_config (config_key, config_value, config_group) 
                VALUES ('$field', '$value', 'general') 
                ON DUPLICATE KEY UPDATE config_value = '$value'");
        }
    }
}

/**
 * Save thresholds
 */
function saveThresholds($data) {
    if (isset($data['warning']) && isset($data['critical'])) {
        foreach ($data['warning'] as $metric => $warningValue) {
            $metric = mysql_real_escape_string($metric);
            $warning = (float)$warningValue;
            $critical = (float)$data['critical'][$metric];
            $enabled = isset($data['enabled'][$metric]) ? 1 : 0;
            
            full_query("UPDATE mod_monitoring_thresholds 
                SET warning_threshold = $warning, 
                    critical_threshold = $critical, 
                    enabled = $enabled 
                WHERE metric_name = '$metric'");
        }
    }
}

/**
 * Save contact
 */
function saveContact($data) {
    $contactId = isset($data['contact_id']) ? (int)$data['contact_id'] : 0;
    $name = mysql_real_escape_string($data['contact_name']);
    $email = mysql_real_escape_string($data['contact_email']);
    $phone = mysql_real_escape_string($data['contact_phone']);
    $topic = mysql_real_escape_string($data['ntfy_topic']);
    $priority = (int)$data['priority_level'];
    $enabled = isset($data['enabled']) ? 1 : 0;
    
    if ($contactId > 0) {
        // Update existing
        full_query("UPDATE mod_monitoring_contacts 
            SET contact_name = '$name', contact_email = '$email', contact_phone = '$phone', 
                ntfy_topic = '$topic', priority_level = $priority, enabled = $enabled 
            WHERE id = $contactId");
    } else {
        // Insert new
        full_query("INSERT INTO mod_monitoring_contacts 
            (contact_name, contact_email, contact_phone, ntfy_topic, priority_level, enabled) 
            VALUES ('$name', '$email', '$phone', '$topic', $priority, $enabled)");
    }
}

/**
 * Delete contact
 */
function deleteContact($contactId) {
    $contactId = (int)$contactId;
    full_query("DELETE FROM mod_monitoring_contacts WHERE id = $contactId");
}
?>
