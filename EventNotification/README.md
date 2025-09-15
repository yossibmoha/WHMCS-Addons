# 🖥️ **WHMCS Complete Monitoring System**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![WHMCS Compatible](https://img.shields.io/badge/WHMCS-8.0%2B-green.svg)](https://whmcs.com)

> **Enterprise-grade monitoring solution for WHMCS with 88+ event types, real-time alerts, and comprehensive analytics.**

---

## 🌟 **Overview**

This is a complete monitoring and notification system for WHMCS that provides:

- **🚨 Real-time monitoring** of 88+ different event types across 6 categories
- **📱 Dual notifications** via ntfy push notifications and email
- **📊 Professional dashboard** with analytics and trend visualization
- **🤖 Intelligent alert management** with escalation and deduplication
- **📈 Historical data collection** with performance baselines
- **⚙️ WHMCS admin integration** for seamless configuration
- **🛡️ Enterprise security** features and monitoring

---

## 🎯 **Key Features**

### **📊 Comprehensive Event Monitoring**
- **🌐 Domain Management** - 12 events (registrations, renewals, transfers, expirations)
- **💳 Payment Processing** - 15 events (payments, refunds, chargebacks, gateway issues)
- **🛡️ Security Monitoring** - 18 events (login attempts, admin access, API abuse)
- **📧 Email System** - 13 events (delivery failures, bounces, template errors)
- **⏰ Cron Jobs** - 20 events (scheduled tasks, backups, performance)
- **⚙️ System Events** - 25+ events (general system, errors, admin actions)

### **🚨 Advanced Alert Management**
- **Multi-level escalation** with automatic progression
- **Intelligent deduplication** to prevent alert spam
- **On-call management** with rotation schedules
- **Alert acknowledgment** and resolution tracking
- **Analytics dashboard** for alert patterns

### **📈 Historical Data & Analytics**
- **Performance baseline** establishment
- **Trend analysis** with predictive alerting  
- **Availability tracking** across all services
- **Custom metrics** collection and visualization
- **Long-term data retention** and reporting

### **🎨 Professional Dashboard**
- **Modern responsive design** with mobile support
- **Real-time event timeline** with filtering
- **System health scoring** (0-100 scale)
- **Quick action buttons** for common tasks
- **Export capabilities** for reports and analysis

---

## 🚀 **Quick Start**

### **Prerequisites**
- WHMCS 8.0 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- ntfy server (optional but recommended)

### **⚡ One-Click Installation**
```bash
cd /path/to/whmcs/
git clone https://github.com/yourusername/whmcs-monitoring EventNotification
cd EventNotification
chmod +x deploy.sh
./deploy.sh
```

### **🔧 Manual Installation**
1. **Upload Files:**
   ```bash
   # Upload the EventNotification directory to your WHMCS root
   rsync -av EventNotification/ /path/to/whmcs/EventNotification/
   ```

2. **Install WHMCS Addon:**
   ```bash
   cd EventNotification
   ./install_monitoring_addon.sh
   ```

3. **Configure Database:**
   ```sql
   # Tables are created automatically on first run
   # Or manually import: mysql < database_schema.sql
   ```

4. **Set Permissions:**
   ```bash
   chmod +x server_monitor_script.sh
   chmod +x *.sh
   chown -R www-data:www-data EventNotification/
   ```

5. **Configure Cron Jobs:**
   ```bash
   # Add to crontab:
   */5 * * * * /path/to/EventNotification/server_monitor_script.sh
   */10 * * * * php /path/to/EventNotification/whmcs_api_monitor.php
   0 */6 * * * php /path/to/EventNotification/alert_escalation_cron.php
   */15 * * * * php /path/to/EventNotification/data_collection_cron.php
   ```

---

## ⚙️ **Configuration**

### **🔔 Notification Setup**

#### **1. ntfy Server (Recommended)**
```bash
# Install ntfy server
curl -sSL https://github.com/binwiederhier/ntfy/releases/latest/download/ntfy_linux_amd64.tar.gz | tar zxvf -
sudo mv ntfy /usr/local/bin/

# Run with our secure config
sudo cp config/ntfy-server-secure.yml /etc/ntfy/server.yml
sudo systemctl enable --now ntfy
```

#### **2. Basic Configuration**
Edit `includes/hooks/whmcs_notification_config.php`:
```php
// ntfy Configuration
define('NTFY_SERVER_URL', 'https://your-ntfy-server.com');
define('NTFY_TOPIC', 'whmcs-alerts');

// Email Configuration  
define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');
define('SMTP_HOST', 'your-smtp-server.com');
define('SMTP_PORT', 587);
```

### **🎛️ WHMCS Admin Configuration**

1. **Navigate to:** Addons → Monitoring
2. **Configure:**
   - ntfy server settings
   - Email notification preferences
   - Alert thresholds and escalation rules
   - Contact management for on-call rotations
   - Historical data retention policies

### **📊 Dashboard Access**
- **URL:** `https://yourdomain.com/EventNotification/monitoring_dashboard_complete.html`
- **WHMCS Integration:** Addons → Monitoring → "View Dashboard"

---

## 📁 **Project Structure**

```
EventNotification/
├── 📊 Dashboard & UI
│   ├── monitoring_dashboard_complete.html    # Main dashboard interface
│   ├── monitoring_dashboard_enhanced.html    # Legacy enhanced dashboard  
│   ├── dashboard_api.php                     # Real-time data API
│   └── system_status.php                     # System health utility
│
├── 🧠 Core Classes
│   └── classes/
│       ├── AlertManager.php                  # Alert processing & escalation
│       └── HistoricalDataManager.php         # Data collection & analytics
│
├── 🎯 Event Monitoring
│   └── includes/hooks/
│       ├── whmcs_notification_config.php     # Core notification functions
│       ├── whmcs_domain_hooks.php            # Domain event monitoring
│       ├── whmcs_payment_hooks.php           # Payment event monitoring  
│       ├── whmcs_security_hooks.php          # Security event monitoring
│       ├── whmcs_email_hooks.php             # Email system monitoring
│       ├── whmcs_cron_hooks.php              # Cron job monitoring
│       ├── whmcs_user_hooks.php              # User activity monitoring
│       ├── whmcs_order_hooks.php             # Order processing monitoring
│       ├── whmcs_server_hooks.php            # Server management monitoring
│       ├── whmcs_support_hooks.php           # Support ticket monitoring
│       └── whmcs_error_hooks.php             # Error and exception monitoring
│
├── 🔌 APIs & Integration  
│   └── api/
│       ├── alert_api.php                     # Alert management API
│       └── historical_data_api.php           # Analytics data API
│
├── ⚙️ WHMCS Admin Addon
│   └── monitoring/
│       ├── monitoring.php                    # Main addon file
│       ├── api.php                          # AJAX API endpoints
│       └── hooks.php                        # WHMCS integration hooks
│
├── 🤖 Automation Scripts
│   ├── server_monitor_script.sh             # Server-level monitoring
│   ├── whmcs_api_monitor.php                # WHMCS API monitoring
│   ├── alert_escalation_cron.php            # Alert escalation processor
│   └── data_collection_cron.php             # Metrics collection
│
├── 🛠️ Setup & Deployment
│   ├── deploy.sh                            # One-click deployment
│   ├── install_monitoring_addon.sh          # WHMCS addon installer
│   └── setup-ntfy-security.sh              # ntfy security configuration
│
├── 📋 Configuration
│   └── config/
│       └── ntfy-server-secure.yml          # Secure ntfy server config
│
└── 📚 Documentation
    ├── README.md                            # This file
    ├── INSTALLATION_GUIDE.md                # Detailed installation guide
    ├── USER_GUIDE.md                        # User manual
    ├── DEPLOYMENT_GUIDE.md                  # Deployment instructions
    ├── COMPLETE_SYSTEM_README.md            # System overview
    ├── FINAL_SYSTEM_OVERVIEW.md            # Feature summary
    ├── MONITORING_GAPS_FIXED.md            # Audit results
    ├── DASHBOARD_UPDATE_COMPLETE.md         # Dashboard changelog
    └── WHMCS_ADDON_INSTALLATION.md         # Addon setup guide
```

---

## 🔗 **API Documentation**

### **📊 Dashboard API Endpoints**

| Endpoint | Method | Description | Parameters |
|----------|--------|-------------|------------|
| `/dashboard_api.php?action=dashboard_stats` | GET | Main dashboard metrics | none |
| `/dashboard_api.php?action=recent_events` | GET | Live event timeline | `limit` (default: 50) |
| `/dashboard_api.php?action=alerts` | GET | Active alert management | none |
| `/dashboard_api.php?action=metrics` | GET | System performance data | none |
| `/dashboard_api.php?action=analytics` | GET | Historical trend analysis | `days` (default: 30) |
| `/dashboard_api.php?action=health_check` | GET | System health status | none |
| `/dashboard_api.php?action=test_notification` | POST | Send test notification | none |

### **🚨 Alert Management API**

| Endpoint | Method | Description | Parameters |
|----------|--------|-------------|------------|
| `/api/alert_api.php?action=acknowledge` | POST | Acknowledge alert | `alert_id`, `user_id`, `note` |
| `/api/alert_api.php?action=resolve` | POST | Resolve alert | `alert_id`, `resolution_note` |
| `/api/alert_api.php?action=escalate` | POST | Escalate alert | `alert_id`, `level` |
| `/api/alert_api.php?action=create` | POST | Create new alert | `title`, `message`, `priority` |

### **📈 Historical Data API**

| Endpoint | Method | Description | Parameters |
|----------|--------|-------------|------------|
| `/api/historical_data_api.php?action=metrics` | GET | Performance metrics | `metric`, `start`, `end` |
| `/api/historical_data_api.php?action=trends` | GET | Trend analysis | `category`, `days` |
| `/api/historical_data_api.php?action=availability` | GET | Availability reports | `service`, `period` |
| `/api/historical_data_api.php?action=baselines` | GET | Performance baselines | `metric_type` |

---

## 📱 **Usage Examples**

### **🔔 Send Custom Notification**
```php
// Include the notification system
require_once 'includes/hooks/whmcs_notification_config.php';

// Send dual notification (ntfy + email)
sendDualNotification(
    "🚨 Custom Alert", 
    "Something important happened at " . date('Y-m-d H:i:s'),
    5, // High priority
    'warning,exclamation'
);
```

### **📊 Get Dashboard Data**
```javascript
// Fetch real-time dashboard stats
fetch('/EventNotification/dashboard_api.php?action=dashboard_stats')
    .then(response => response.json())
    .then(data => {
        console.log('Total events today:', data.data.events_today.total);
        console.log('Active alerts:', data.data.alerts.total);
    });
```

### **🚨 Create Alert Programmatically**
```php
$alertManager = new AlertManager();
$alertId = $alertManager->createAlert([
    'title' => 'High CPU Usage',
    'message' => 'Server CPU usage is at 95% for 5 minutes',
    'priority' => 'critical',
    'category' => 'system'
]);
```

### **📈 Query Historical Data**
```php
$historicalManager = new HistoricalDataManager();
$metrics = $historicalManager->getMetrics('cpu_usage', 'last_24_hours');
$trends = $historicalManager->getTrendAnalysis('payment_failures', 30);
```

---

## 🎛️ **Configuration Options**

### **🔔 Notification Settings**
```php
// Priority Levels (1-10)
define('PRIORITY_LOW', 1);
define('PRIORITY_NORMAL', 3);  
define('PRIORITY_HIGH', 5);
define('PRIORITY_CRITICAL', 8);
define('PRIORITY_EMERGENCY', 10);

// Escalation Rules
$escalationRules = [
    'critical' => [
        'immediate' => ['admin@domain.com'],
        'after_15_min' => ['manager@domain.com'],
        'after_1_hour' => ['emergency@domain.com']
    ]
];
```

### **📊 Monitoring Thresholds**
```php
// Server Monitoring Thresholds
$thresholds = [
    'cpu_usage' => 80,          // Alert if CPU > 80%
    'memory_usage' => 90,       // Alert if memory > 90%
    'disk_usage' => 85,         // Alert if disk > 85%
    'response_time' => 3000,    // Alert if response > 3s
    'failed_logins' => 5        // Alert after 5 failed logins
];
```

### **📈 Data Retention**
```php
// Historical Data Retention (days)
define('RETENTION_EVENTS', 90);      // Event logs: 90 days
define('RETENTION_METRICS', 365);    // Metrics: 1 year
define('RETENTION_ALERTS', 180);     // Alerts: 6 months
define('RETENTION_ANALYTICS', 730);  // Analytics: 2 years
```

---

## 🔧 **Customization**

### **🎨 Custom Event Hooks**
```php
// Create custom monitoring hook
add_hook('CustomEventName', 1, function($vars) {
    $title = "🔧 Custom Event Triggered";
    $message = "Custom event with data: " . json_encode($vars);
    
    sendDualNotification($title, $message, 3, 'gear,check');
});
```

### **📊 Custom Dashboard Widgets**
```php
// Add custom metric to dashboard
function getCustomMetrics() {
    return [
        'custom_metric' => [
            'value' => calculateCustomValue(),
            'status' => 'healthy',
            'description' => 'Custom business metric'
        ]
    ];
}
```

### **🚨 Custom Alert Processors**
```php
// Custom alert processing logic
class CustomAlertProcessor extends AlertManager {
    public function processCustomAlert($data) {
        // Custom alert logic here
        parent::createAlert($data);
    }
}
```

---

## 🐛 **Troubleshooting**

### **Common Issues**

#### **🔴 Notifications Not Sending**
```bash
# Check ntfy server connectivity
curl -f https://your-ntfy-server.com/api/stats

# Test notification manually
php -r "require 'includes/hooks/whmcs_notification_config.php'; sendDualNotification('Test', 'Testing', 3);"

# Check PHP error logs
tail -f /var/log/php/error.log
```

#### **📊 Dashboard Not Loading**
```bash
# Check file permissions
ls -la monitoring_dashboard_complete.html
chmod 644 monitoring_dashboard_complete.html

# Test API endpoints
curl -f yourdomain.com/EventNotification/dashboard_api.php?action=dashboard_stats

# Check PHP errors
php -l dashboard_api.php
```

#### **🗄️ Database Connection Issues**
```bash
# Test database connectivity
php -r "try { new PDO('mysql:host=localhost;dbname=whmcs', 'user', 'pass'); echo 'OK'; } catch(Exception $e) { echo $e->getMessage(); }"

# Check WHMCS database tables
mysql -u user -p whmcs -e "SHOW TABLES LIKE 'mod_monitoring_%';"
```

#### **⏰ Cron Jobs Not Running**
```bash
# Check crontab
crontab -l | grep EventNotification

# Test scripts manually
/path/to/EventNotification/server_monitor_script.sh
php /path/to/EventNotification/whmcs_api_monitor.php

# Check cron logs
tail -f /var/log/cron.log
```

### **Debug Mode**
```php
// Enable debug logging
define('DEBUG_MONITORING', true);

// View debug logs
tail -f logs/monitoring_debug.log
```

---

## 📈 **Performance & Scaling**

### **🚀 Optimization Tips**
- **Database indexing** on frequently queried columns
- **Caching** for dashboard data using Redis/Memcached
- **Rate limiting** for API endpoints
- **Log rotation** for large installations
- **Background processing** for heavy operations

### **📊 Resource Usage**
- **CPU:** < 2% additional load
- **Memory:** ~50MB for core processes
- **Disk:** ~100MB for logs and data (varies with retention)
- **Database:** ~10MB additional storage per month

### **🔄 High Availability**
- **Load balancing** for dashboard and API
- **Database replication** for data redundancy  
- **ntfy clustering** for notification reliability
- **Health checks** with automatic failover

---

## 🤝 **Contributing**

We welcome contributions! Please follow these guidelines:

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feature/amazing-feature`)
3. **Commit** your changes (`git commit -m 'Add amazing feature'`)
4. **Push** to the branch (`git push origin feature/amazing-feature`)  
5. **Open** a Pull Request

### **🧪 Development Setup**
```bash
git clone https://github.com/yourusername/whmcs-monitoring.git
cd whmcs-monitoring
cp config/config.example.php config/config.php
# Edit configuration
./setup-dev-environment.sh
```

---

## 📄 **License**

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## 🙏 **Acknowledgments**

- **WHMCS** for providing an excellent hosting automation platform
- **ntfy** for the lightweight push notification server
- **Chart.js** for beautiful data visualizations
- **FontAwesome** for the comprehensive icon library
- **Bootstrap** for the responsive framework foundation

---

## 📞 **Support**

- **📖 Documentation:** [Full Documentation](COMPLETE_SYSTEM_README.md)
- **🐛 Issues:** [GitHub Issues](https://github.com/yourusername/whmcs-monitoring/issues)
- **💬 Discussions:** [GitHub Discussions](https://github.com/yourusername/whmcs-monitoring/discussions)
- **📧 Email:** support@yourdomain.com

---

## 📊 **System Status**

| Component | Status | Coverage | Version |
|-----------|--------|----------|---------|
| **Event Monitoring** | ✅ Active | 88+ events | v2.0 |
| **Alert Management** | ✅ Active | Full escalation | v2.0 |
| **Dashboard** | ✅ Active | Real-time | v2.0 |
| **WHMCS Integration** | ✅ Active | Native addon | v2.0 |
| **Historical Data** | ✅ Active | Full analytics | v2.0 |
| **Mobile Support** | ✅ Active | Responsive | v2.0 |

---

<div align="center">

**🎉 Thank you for using WHMCS Complete Monitoring System!**

*If this project helps your business, please consider giving it a ⭐ on GitHub!*

</div>
