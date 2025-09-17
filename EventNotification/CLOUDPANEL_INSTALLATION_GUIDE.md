# 🚀 **CloudPanel + WHMCS Monitoring Installation Guide**

## 📋 **Your Perfect Setup**
- **✅ CloudPanel** - Web hosting control panel
- **✅ WHMCS 8.13.1** - Latest version
- **✅ PHP 8.2** - Optimal performance
- **✅ Complete monitoring system** - 88+ events tracked

---

## 🛠️ **What You Need to Install**

### **1. Server-Level Components (via CloudPanel/SSH)**
- **ntfy server** - For iPhone push notifications
- **Additional PHP packages** - For enhanced monitoring
- **Cron jobs** - For automated monitoring
- **System utilities** - For server health checks

### **2. WHMCS Components**
- **Hook files** - For event monitoring (88+ events)  
- **Monitoring addon** - Admin panel integration
- **Dashboard files** - Real-time monitoring interface
- **API endpoints** - For data and alerts

---

## 🎯 **Step-by-Step Installation**

### **STEP 1: Server Preparation (SSH/CloudPanel)**

#### **1.1 Access Your Server**
```bash
# SSH into your CloudPanel server
ssh root@your-server-ip

# Or use CloudPanel's built-in terminal
```

#### **1.2 Install Required Packages**
```bash
# Update system
apt update && apt upgrade -y

# Install monitoring dependencies  
apt install -y curl wget jq net-tools htop iotop mysql-client

# Install additional PHP packages for monitoring
apt install -y php8.2-curl php8.2-json php8.2-mbstring php8.2-xml

# Verify PHP version
php -v
# Should show: PHP 8.2.x

# Check PHP modules
php -m | grep -E "(curl|json|openssl|pdo|mysqli)"
```

#### **1.3 Create Monitoring Directories**
```bash
# Create system monitoring directory
mkdir -p /opt/whmcs-monitoring
chmod 755 /opt/whmcs-monitoring

# Create logs directory
mkdir -p /var/log/whmcs-monitoring
chown www-data:www-data /var/log/whmcs-monitoring
```

---

### **STEP 2: Install ntfy Server (Push Notifications)**

#### **2.1 Install ntfy via Docker (Recommended for CloudPanel)**
```bash
# Install Docker if not already installed
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Create ntfy configuration directory
mkdir -p /opt/ntfy/config
mkdir -p /opt/ntfy/data

# Create ntfy configuration
cat > /opt/ntfy/config/server.yml << 'EOF'
base-url: "https://ntfy.yourdomain.com"
listen-http: ":8080"
cache-file: "/var/lib/ntfy/cache.db"
cache-duration: "12h"
keepalive-interval: "45s"
manager-interval: "1m"
web-root: "disable"
enable-signup: false
enable-login: true
default-user-role: "deny-all"
auth-file: "/var/lib/ntfy/user.db"
auth-default-access: "deny-all"

# Rate limiting for security
visitor-request-limit-burst: 60
visitor-request-limit-replenish: "10s"
visitor-message-daily-limit: 15000
EOF

# Run ntfy server
docker run -d \
  --name ntfy-server \
  --restart unless-stopped \
  -p 8080:8080 \
  -v /opt/ntfy/config:/etc/ntfy \
  -v /opt/ntfy/data:/var/lib/ntfy \
  binwiederhier/ntfy:latest \
  serve --config /etc/ntfy/server.yml

# Create ntfy user for WHMCS
docker exec ntfy-server ntfy user add whmcs
docker exec ntfy-server ntfy access whmcs whmcs-alerts rw
```

#### **2.2 Configure CloudPanel Reverse Proxy**
In **CloudPanel Admin** → **Websites** → **Add Site**:

1. **Create subdomain**: `ntfy.yourdomain.com`
2. **Point to**: `localhost:8080`
3. **Enable SSL** via CloudPanel
4. **Add custom Nginx config**:

```nginx
# In CloudPanel: Sites → ntfy.yourdomain.com → Nginx Settings
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # WebSocket support
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

---

### **STEP 3: Download & Prepare WHMCS Monitoring System**

#### **3.1 Download the System**
```bash
# Navigate to your WHMCS directory (adjust path as needed)
cd /home/cloudpanel/htdocs/yourdomain.com/

# Or for CloudPanel default structure:
cd /home/your-username/htdocs/whmcs/

# Download monitoring system
git clone https://your-repo/whmcs-monitoring EventNotification

# Or upload and extract if using FTP/SFTP
```

#### **3.2 Set Permissions**
```bash
# Set proper ownership (adjust user as needed)
chown -R clp1:clp1 EventNotification/

# Set executable permissions for scripts
chmod +x EventNotification/*.sh
chmod +x EventNotification/monitoring/*.sh

# Set secure permissions for config files
chmod 600 EventNotification/includes/hooks/*config*.php
```

---

### **STEP 4: Configure WHMCS Integration**

#### **4.1 Configure Notification Settings**
```bash
# Edit the main config file
nano EventNotification/includes/hooks/whmcs_notification_config_with_alerts.php
```

**Update these values**:
```php
// Your ntfy server configuration
define('NTFY_SERVER_URL', 'https://ntfy.yourdomain.com');
define('NTFY_TOPIC', 'whmcs-alerts');
define('NTFY_USERNAME', 'whmcs');
define('NTFY_PASSWORD', 'your-ntfy-password');

// Your notification email
define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');

// WHMCS API credentials (get from WHMCS Admin → Configuration → API Credentials)
define('WHMCS_API_IDENTIFIER', 'your-api-id');
define('WHMCS_API_SECRET', 'your-api-secret');
define('WHMCS_URL', 'https://yourdomain.com');
```

#### **4.2 Install Hook Files**
```bash
# Copy all hook files to WHMCS
cp EventNotification/includes/hooks/*.php includes/hooks/

# Verify hooks are installed
ls -la includes/hooks/whmcs_*.php
```

#### **4.3 Install WHMCS Admin Addon**
```bash
# Run the automated addon installer
./EventNotification/install_monitoring_addon.sh

# Or manual installation:
mkdir -p modules/addons/monitoring
cp EventNotification/monitoring/* modules/addons/monitoring/
chown -R clp1:clp1 modules/addons/monitoring
```

---

### **STEP 5: Deploy Monitoring Scripts**

#### **5.1 Install API Monitor**
```bash
# Copy API monitoring script
cp EventNotification/whmcs_api_monitor.php ./

# Copy server monitoring script to system
sudo cp EventNotification/server_monitor_script.sh /usr/local/bin/whmcs_server_monitor.sh
sudo chmod +x /usr/local/bin/whmcs_server_monitor.sh
```

#### **5.2 Install Dashboard & APIs**
```bash
# Create dashboard directory
mkdir -p monitoring-dashboard
cp EventNotification/monitoring_dashboard_complete.html monitoring-dashboard/index.html
cp EventNotification/dashboard_api.php monitoring-dashboard/
cp EventNotification/api/*.php monitoring-dashboard/api/
cp EventNotification/classes/*.php monitoring-dashboard/classes/

# Set permissions
chown -R clp1:clp1 monitoring-dashboard/
```

#### **5.3 Setup Cron Jobs**
```bash
# Edit crontab
crontab -e

# Add these lines:
# WHMCS API monitoring (every 5 minutes)
*/5 * * * * /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/whmcs_api_monitor.php >/dev/null 2>&1

# Server monitoring (every 5 minutes)  
*/5 * * * * /usr/local/bin/whmcs_server_monitor.sh >/dev/null 2>&1

# Data collection for analytics (every 15 minutes)
*/15 * * * * /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/EventNotification/data_collection_cron.php >/dev/null 2>&1

# Alert escalation (every 6 hours)
0 */6 * * * /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/EventNotification/alert_escalation_cron.php >/dev/null 2>&1

# Daily cleanup (2 AM)
0 2 * * * /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/EventNotification/cleanup_historical_data.php >/dev/null 2>&1
```

---

### **STEP 6: Activate WHMCS Addon**

#### **6.1 Enable in WHMCS Admin**
1. **Login to WHMCS Admin**
2. **Go to**: System Settings → Addon Modules
3. **Find**: "WHMCS Monitoring System"
4. **Click**: "Activate"
5. **Configure**: Set your preferences
6. **Test**: Access via Utilities → Monitoring

#### **6.2 Configure Settings**
In **WHMCS Admin** → **Addon Modules** → **Monitoring**:

- **ntfy Server URL**: `https://ntfy.yourdomain.com`
- **ntfy Topic**: `whmcs-alerts`
- **Notification Email**: `admin@yourdomain.com`
- **Enable All Monitoring**: ✅ Yes
- **Alert Threshold**: Medium (3)
- **Historical Data**: ✅ Enable

---

### **STEP 7: Setup Dashboard Access (Optional)**

#### **7.1 Create Dashboard Subdomain in CloudPanel**
1. **CloudPanel Admin** → **Websites** → **Add Site**
2. **Domain**: `monitor.yourdomain.com`
3. **Document Root**: `/home/clp1/htdocs/yourdomain.com/monitoring-dashboard`
4. **PHP Version**: 8.2
5. **SSL**: Enable

#### **7.2 Secure Dashboard Access**
```bash
# Create HTTP auth
cd monitoring-dashboard
htpasswd -c .htpasswd admin

# Add to .htaccess
cat > .htaccess << 'EOF'
AuthType Basic
AuthName "WHMCS Monitoring Dashboard"
AuthUserFile /home/clp1/htdocs/yourdomain.com/monitoring-dashboard/.htpasswd
Require valid-user
EOF
```

---

## 📱 **iPhone App Setup**

### **Download & Configure ntfy App**
1. **App Store** → Search "ntfy" → Install
2. **Add Server**: `https://ntfy.yourdomain.com`
3. **Username**: `whmcs`
4. **Password**: `your-ntfy-password`
5. **Subscribe to**: `whmcs-alerts`
6. **Enable notifications** in iPhone Settings

---

## 🧪 **Test Your Installation**

### **Test 1: ntfy Notifications**
```bash
# Send test notification
curl -u whmcs:your-password \
  -d "🧪 Test notification from CloudPanel server" \
  https://ntfy.yourdomain.com/whmcs-alerts
```

### **Test 2: WHMCS Hook Integration**
```bash
# Test hook system
php -r "
require_once 'includes/hooks/whmcs_notification_config_with_alerts.php';
sendDualNotification('🧪 WHMCS Test', 'Hook system is working!', 3, 'test');
echo 'Test sent successfully!\n';
"
```

### **Test 3: Server Monitoring**
```bash
# Test server monitoring
/usr/local/bin/whmcs_server_monitor.sh disk
/usr/local/bin/whmcs_server_monitor.sh memory
```

### **Test 4: API Monitoring**
```bash
# Test API monitoring
php whmcs_api_monitor.php
```

---

## 📊 **What You'll Get**

### **📱 iPhone Notifications For:**
- **Customer Events**: New registrations, login issues, support tickets
- **Financial Events**: Payment failures, invoice issues, refunds
- **System Events**: Server problems, API failures, security alerts
- **Domain Events**: Expirations, transfers, DNS issues
- **Technical Events**: SSL problems, backup failures, cron issues

### **🖥️ WHMCS Admin Integration:**
- **Dashboard Widget** - Recent alerts on WHMCS homepage
- **Full Admin Module** - Complete monitoring control panel
- **Configuration Panel** - Adjust all settings from WHMCS
- **Alert Management** - Acknowledge and manage alerts
- **Historical Analytics** - Performance trends and reports

### **🌐 Web Dashboard:**
- **Real-time Status** - Live system health
- **Interactive Alerts** - Click to acknowledge/resolve
- **Historical Charts** - Performance trends
- **System Health Score** - Overall status
- **Quick Actions** - Restart services, clear caches

---

## 🔧 **CloudPanel-Specific Tips**

### **Backup Your Configuration**
```bash
# Create backup before installation
tar -czf whmcs-backup-$(date +%Y%m%d).tar.gz \
  includes/hooks/ modules/addons/ configuration.php
```

### **Monitor CloudPanel Logs**
```bash
# Monitor for any issues
tail -f /var/log/nginx/access.log
tail -f /var/log/php8.2-fpm.log
```

### **Performance Optimization**
```bash
# Adjust PHP-FPM for monitoring
# In CloudPanel: Sites → your-site → PHP Settings
# Increase: max_execution_time = 300
# Increase: memory_limit = 256M
```

---

## 🚨 **Troubleshooting**

### **Common CloudPanel Issues:**

#### **1. Permission Problems**
```bash
# Fix ownership
chown -R clp1:clp1 /home/clp1/htdocs/yourdomain.com/
chmod -R 755 /home/clp1/htdocs/yourdomain.com/
chmod 600 includes/hooks/*config*.php
```

#### **2. PHP Module Missing**
```bash
# Install missing PHP modules
apt install php8.2-curl php8.2-json php8.2-mbstring
systemctl restart php8.2-fpm
```

#### **3. Cron Jobs Not Running**
```bash
# Check cron service
systemctl status cron
systemctl restart cron

# Test cron manually
sudo -u clp1 /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/whmcs_api_monitor.php
```

#### **4. ntfy Server Issues**
```bash
# Check Docker container
docker logs ntfy-server

# Restart ntfy
docker restart ntfy-server
```

---

## ✅ **Installation Complete!**

**🎉 Your WHMCS monitoring system is now active!**

- **📱 iPhone notifications** - Working
- **🖥️ Admin integration** - Available in WHMCS
- **🌐 Web dashboard** - Live monitoring
- **📊 88+ events tracked** - Complete coverage
- **⚡ PHP 8.2 optimized** - Maximum performance

**Next Steps:**
1. **Test all notifications** work properly
2. **Configure alert thresholds** in WHMCS admin
3. **Add team members** to ntfy topic  
4. **Monitor the dashboard** for a few days
5. **Adjust settings** based on your needs

**🎯 You now have enterprise-grade monitoring for your WHMCS installation!**
