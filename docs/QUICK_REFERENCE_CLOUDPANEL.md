# 🚀 **CloudPanel WHMCS Monitoring - Quick Reference**

## 📋 **Your Setup Summary**
- **✅ CloudPanel Server** - Web hosting control panel
- **✅ WHMCS 8.13.1** - Perfect compatibility
- **✅ PHP 8.2** - Optimal performance (+25% speed)
- **✅ Complete Monitoring** - 88+ events tracked

---

## ⚡ **Quick Installation Commands**

### **1. Server Prep (SSH to your CloudPanel server)**
```bash
# Update & install requirements
apt update && apt upgrade -y
apt install -y curl wget jq net-tools php8.2-curl php8.2-json

# Install Docker for ntfy
curl -fsSL https://get.docker.com -o get-docker.sh && sh get-docker.sh
```

### **2. Install ntfy (Push Notifications)**
```bash
# Create directories
mkdir -p /opt/ntfy/{config,data}

# Run ntfy server
docker run -d --name ntfy-server --restart unless-stopped \
  -p 8081:8080 -v /opt/ntfy/config:/etc/ntfy -v /opt/ntfy/data:/var/lib/ntfy \
  binwiederhier/ntfy:latest serve

# Create user
docker exec ntfy-server ntfy user add whmcs
docker exec ntfy-server ntfy access whmcs whmcs-alerts rw
```

### **3. Install Monitoring System**
```bash
# Find your WHMCS directory first (if unsure):
find /home -name "configuration.php" -path "*/htdocs/*" 2>/dev/null

# Go to your WHMCS ROOT directory (where configuration.php is located)
cd /home/clp1/htdocs/yourdomain.com/

# Clone INTO your WHMCS directory (creates EventNotification/ subdirectory)
git clone https://github.com/yourusername/whmcs-monitoring EventNotification

# Verify structure:
ls -la EventNotification/

# Set permissions
chown -R clp1:clp1 EventNotification/
chmod +x EventNotification/*.sh

# Install WHMCS addon
./EventNotification/install_monitoring_addon.sh
```

### **4. Configure & Deploy**
```bash
# Copy hook files
cp EventNotification/includes/hooks/*.php includes/hooks/

# Copy monitoring scripts
cp EventNotification/whmcs_api_monitor.php ./
sudo cp EventNotification/server_monitor_script.sh /usr/local/bin/whmcs_server_monitor.sh
sudo chmod +x /usr/local/bin/whmcs_server_monitor.sh
```

### **5. Setup Cron Jobs**
```bash
crontab -e
# Add these lines:
*/5 * * * * /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/whmcs_api_monitor.php >/dev/null 2>&1
*/5 * * * * /usr/local/bin/whmcs_server_monitor.sh >/dev/null 2>&1
*/15 * * * * /usr/bin/php8.2 /home/clp1/htdocs/yourdomain.com/EventNotification/data_collection_cron.php >/dev/null 2>&1
```

---

## 🔧 **Configuration Files to Edit**

### **1. Main Config** - `EventNotification/includes/hooks/whmcs_notification_config_with_alerts.php`
```php
define('NTFY_SERVER_URL', 'https://ntfy.yourdomain.com');
define('NTFY_TOPIC', 'whmcs-alerts');
define('NTFY_USERNAME', 'whmcs');
define('NTFY_PASSWORD', 'your-ntfy-password');
define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');
define('WHMCS_API_IDENTIFIER', 'your-api-id');
define('WHMCS_API_SECRET', 'your-api-secret');
define('WHMCS_URL', 'https://yourdomain.com');
```

### **2. CloudPanel Reverse Proxy** - Add in CloudPanel for `ntfy.yourdomain.com`
```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

---

## 📱 **iPhone Setup**

### **ntfy App Configuration**
1. **App Store** → Install "ntfy"
2. **Add Server**: `https://ntfy.yourdomain.com`
3. **Login**: Username `whmcs`, Password `your-ntfy-password`
4. **Subscribe**: Topic `whmcs-alerts`
5. **Settings** → **Notifications** → **ntfy** → Enable all

---

## 🖥️ **WHMCS Admin Integration**

### **Activate Addon**
1. **WHMCS Admin** → **System Settings** → **Addon Modules**
2. **Find** "WHMCS Monitoring System" → **Activate**
3. **Access via** Utilities → Monitoring
4. **Configure** all settings through the interface

### **What You'll See**
- **Dashboard Widget** - Recent alerts on homepage
- **Full Admin Panel** - Complete monitoring control
- **Alert Management** - Acknowledge/resolve alerts
- **Historical Analytics** - Performance trends
- **Configuration Panel** - All settings in one place

---

## 🧪 **Quick Tests**

### **Test ntfy**
```bash
curl -u whmcs:your-password -d "Test notification" https://ntfy.yourdomain.com/whmcs-alerts
```

### **Test WHMCS Integration**
```bash
php -r "require_once 'includes/hooks/whmcs_notification_config_with_alerts.php'; sendDualNotification('Test', 'Working!', 3, 'test'); echo 'Sent!\n';"
```

### **Test Monitoring Scripts**
```bash
/usr/local/bin/whmcs_server_monitor.sh disk
php whmcs_api_monitor.php
```

---

## 📊 **What You're Monitoring (88+ Events)**

### **Customer Events**
- New registrations, logins, support tickets, profile changes

### **Financial Events**
- Payments, invoices, refunds, chargebacks, recurring billing

### **System Events**
- Server health, API failures, SSL certificates, backups

### **Domain Events**
- Registrations, transfers, renewals, expirations, DNS changes

### **Security Events**
- Failed logins, fraud detection, admin access, suspicious activity

### **Technical Events**
- Cron jobs, email delivery, database performance, file integrity

---

## 🔍 **Key URLs After Setup**

| **Service** | **URL** | **Purpose** |
|-------------|---------|-------------|
| **ntfy Server** | `https://ntfy.yourdomain.com` | Push notification server |
| **WHMCS Monitoring** | `yourdomain.com/admin/addonmodules.php?module=monitoring` | Admin panel |
| **Dashboard** | `https://monitor.yourdomain.com` | Web dashboard (optional) |
| **iPhone App** | App Store "ntfy" | Mobile notifications |

---

## 🚨 **Quick Troubleshooting**

### **No Notifications?**
```bash
# Check ntfy server
docker logs ntfy-server

# Test PHP curl
php -r "echo extension_loaded('curl') ? 'OK' : 'Missing';"

# Check WHMCS logs
tail -f storage/logs/laravel.log
```

### **Permissions Issues?**
```bash
chown -R clp1:clp1 /home/clp1/htdocs/yourdomain.com/
chmod 600 includes/hooks/*config*.php
chmod +x /usr/local/bin/whmcs_server_monitor.sh
```

### **Cron Jobs Not Running?**
```bash
systemctl status cron
crontab -l  # Verify jobs are added
sudo -u clp1 /usr/bin/php8.2 /path/to/script.php  # Test manually
```

---

## 📞 **Support & Documentation**

- **📖 Full Guide**: `CLOUDPANEL_INSTALLATION_GUIDE.md`
- **📋 Complete README**: `README.md`  
- **🔧 Deployment Guide**: `DEPLOYMENT_GUIDE.md`
- **⚡ PHP 8.2 Compatibility**: `PHP_8_2_COMPATIBILITY.md`

---

## ✅ **Final Checklist**

- [ ] **ntfy server running** (`docker ps`)
- [ ] **CloudPanel proxy configured** (ntfy.yourdomain.com working)
- [ ] **Hook files copied** (`ls includes/hooks/whmcs_*.php`)
- [ ] **WHMCS addon activated** (visible in admin)
- [ ] **Cron jobs added** (`crontab -l`)
- [ ] **iPhone app configured** (notifications enabled)
- [ ] **Test notification sent** (received on iPhone)
- [ ] **Dashboard accessible** (if configured)

**🎉 You're monitoring 88+ WHMCS events with iPhone notifications!**

---

**💡 Pro Tip**: Start with a test WHMCS action (like creating a client) to see the full notification flow in action!
