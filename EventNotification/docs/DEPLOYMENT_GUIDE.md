# WHMCS Monitoring System - Deployment Guide

## Quick Start Deployment

### 1. Automated Deployment (Recommended)

```bash
# Clone or download the monitoring system
cd /path/to/whmcs-monitoring-system

# Make deployment script executable
chmod +x deploy.sh

# Deploy to production
sudo ./deploy.sh production /var/www/whmcs

# Deploy to development
sudo ./deploy.sh development /var/www/whmcs

# Deploy to staging
sudo ./deploy.sh staging /var/www/whmcs
```

## Manual Deployment

### Prerequisites

- PHP 7.4+ with extensions: curl, json, openssl
- WHMCS installation (any recent version)
- Root/sudo access for system-level monitoring
- Email server configuration (for email notifications)

### Step 1: Install Hook Files

```bash
# Copy hook files to WHMCS
cp includes/hooks/*.php /var/www/whmcs/includes/hooks/

# Set proper permissions
chown -R www-data:www-data /var/www/whmcs/includes/hooks
chmod 644 /var/www/whmcs/includes/hooks/*.php
```

### Step 2: Deploy Monitoring Scripts

```bash
# Copy API monitor to WHMCS directory
cp whmcs_api_monitor.php /var/www/whmcs/

# Copy server monitor to system directory
sudo cp server_monitor_script.sh /usr/local/bin/whmcs_server_monitor.sh
sudo chmod +x /usr/local/bin/whmcs_server_monitor.sh

# Create logs directory
mkdir -p /var/www/whmcs/storage/logs
chown www-data:www-data /var/www/whmcs/storage/logs
```

### Step 3: Configure Notifications

Edit `/var/www/whmcs/includes/hooks/whmcs_notification_config.php`:

```php
// Update these values for your setup
define('NTFY_SERVER_URL', 'https://your-ntfy-server.com');
define('NTFY_TOPIC', 'whmcs-alerts');
define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');
```

### Step 4: Install ntfy Server

#### Option A: Docker (Recommended)
```bash
# Create ntfy directory
mkdir -p /opt/ntfy

# Create configuration
cat > /opt/ntfy/server.yml << EOF
base-url: "https://your-domain.com"
listen-http: ":8080"
cache-file: "/var/cache/ntfy/cache.db"
cache-duration: "12h"
auth-default-access: "deny-all"
EOF

# Run ntfy with Docker
docker run -d \
  --name ntfy \
  --restart unless-stopped \
  -p 8080:80 \
  -v /opt/ntfy:/etc/ntfy \
  binwiederhier/ntfy serve
```

#### Option B: Native Installation (Ubuntu/Debian)
```bash
# Add repository
curl -sSL https://archive.heckel.io/apt/pubkey.txt | sudo apt-key add -
echo "deb [arch=amd64] https://archive.heckel.io/apt debian main" | sudo tee /etc/apt/sources.list.d/archive.heckel.io.list

# Install
sudo apt update
sudo apt install ntfy

# Configure
sudo mkdir -p /etc/ntfy
sudo cp config/ntfy-server.yml /etc/ntfy/server.yml

# Start service
sudo systemctl enable ntfy
sudo systemctl start ntfy
```

### Step 5: Set Up Cron Jobs

Add to crontab (`sudo crontab -e`):

```bash
# WHMCS External monitoring (every 15 minutes)
*/15 * * * * /usr/bin/php /var/www/whmcs/whmcs_api_monitor.php >/dev/null 2>&1

# Server monitoring (every 5 minutes)
*/5 * * * * /usr/local/bin/whmcs_server_monitor.sh >/dev/null 2>&1

# Daily summary (9 AM)
0 9 * * * /usr/local/bin/whmcs_server_monitor.sh summary

# Log cleanup (weekly)
0 2 * * 0 find /var/www/whmcs/storage/logs -name "*.log" -mtime +7 -delete
```

### Step 6: Configure Reverse Proxy (Optional)

#### Nginx Configuration for ntfy
```nginx
server {
    listen 443 ssl http2;
    server_name ntfy.yourdomain.com;
    
    ssl_certificate /path/to/your/cert.pem;
    ssl_certificate_key /path/to/your/key.pem;
    
    location / {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # CORS headers for web interface
        add_header Access-Control-Allow-Origin *;
        add_header Access-Control-Allow-Methods "GET, POST, OPTIONS";
        add_header Access-Control-Allow-Headers "Authorization, Content-Type";
    }
}
```

## Configuration Options

### Environment Variables

Set in your environment or `.env` file:

```bash
WHMCS_ENV=production          # development, staging, production
NTFY_SERVER_URL=https://your-ntfy-server.com
NTFY_TOPIC=whmcs-alerts
NOTIFICATION_EMAIL=admin@yourdomain.com
WHMCS_API_IDENTIFIER=your_api_id
WHMCS_API_SECRET=your_api_secret
```

### Monitoring Thresholds

Edit in configuration files:

```php
// Response time thresholds
define('RESPONSE_TIME_WARNING', 3000);    // 3 seconds
define('RESPONSE_TIME_CRITICAL', 5000);   // 5 seconds

// Server resource thresholds
define('CPU_WARNING_THRESHOLD', 80);      // 80%
define('MEMORY_WARNING_THRESHOLD', 90);   // 90%
define('DISK_WARNING_THRESHOLD', 85);     // 85%

// SSL certificate warning
define('SSL_EXPIRY_WARNING_DAYS', 30);    // 30 days

// Backup age warning
define('BACKUP_MAX_AGE_DAYS', 2);         // 2 days
```

## Security Configuration

### 1. ntfy Server Authentication

```yaml
# /etc/ntfy/server.yml
auth-file: "/var/lib/ntfy/user.db"
auth-default-access: "deny-all"
```

Create users:
```bash
ntfy user add --role=admin admin
ntfy access admin whmcs-alerts rw
```

### 2. API Security

- Use strong, unique API credentials
- Limit API access to monitoring functions only
- Enable IP restrictions in WHMCS admin panel
- Use HTTPS for all connections

### 3. File Permissions

```bash
# Set restrictive permissions
chmod 600 /var/www/whmcs/includes/hooks/whmcs_notification_config.php
chown www-data:www-data /var/www/whmcs/includes/hooks/*.php
```

## Testing Your Deployment

### 1. Test ntfy Connection
```bash
curl -d "Test notification" https://your-ntfy-server.com/whmcs-alerts
```

### 2. Test WHMCS Notifications
```bash
php -r "
require_once '/var/www/whmcs/includes/hooks/whmcs_notification_config.php';
sendDualNotification('Test Alert', 'System is working correctly', 3, 'test');
echo 'Test notification sent\n';
"
```

### 3. Test Server Monitoring
```bash
# Test individual checks
/usr/local/bin/whmcs_server_monitor.sh disk
/usr/local/bin/whmcs_server_monitor.sh memory
/usr/local/bin/whmcs_server_monitor.sh services

# Test full monitoring
/usr/local/bin/whmcs_server_monitor.sh
```

### 4. Test API Monitoring
```bash
php /var/www/whmcs/whmcs_api_monitor.php
```

### 5. Verify Logs
```bash
# Check notification logs
tail -f /var/www/whmcs/storage/logs/notifications.log

# Check system logs
journalctl -u ntfy -f
```

## iPhone App Setup

1. **Install ntfy App**: Download from App Store
2. **Add Server**: 
   - Tap "+" to add server
   - Enter: `https://your-ntfy-server.com`
   - Add authentication if configured
3. **Subscribe to Topics**:
   - `whmcs-alerts` - Main WHMCS notifications
   - `server-monitor` - Server health alerts
4. **Configure Notifications**:
   - Go to iPhone Settings > Notifications > ntfy
   - Enable all notification types
   - Set sound and badges as desired

## Monitoring Dashboard Setup

1. **Deploy Dashboard**:
   ```bash
   cp monitoring_dashboard_enhanced.html /var/www/html/whmcs-dashboard/
   ```

2. **Configure Web Server**:
   ```nginx
   server {
       listen 80;
       server_name dashboard.yourdomain.com;
       root /var/www/html/whmcs-dashboard;
       index monitoring_dashboard_enhanced.html;
       
       location / {
           try_files $uri $uri/ =404;
       }
       
       # API endpoints
       location /api/ {
           proxy_pass http://localhost:8080;
       }
   }
   ```

3. **Secure Access**:
   ```bash
   # Add HTTP authentication
   htpasswd -c /etc/nginx/.htpasswd admin
   ```

## Troubleshooting

### Common Issues

1. **Notifications not working**:
   - Check PHP curl extension: `php -m | grep curl`
   - Verify ntfy server is accessible
   - Check WHMCS error logs

2. **Server monitoring fails**:
   - Verify script permissions: `ls -la /usr/local/bin/whmcs_server_monitor.sh`
   - Check cron job syntax: `sudo crontab -l`
   - Review system logs: `journalctl -f`

3. **High CPU usage**:
   - Reduce monitoring frequency in cron jobs
   - Add rate limiting to notifications
   - Optimize database queries

4. **Permission errors**:
   ```bash
   # Fix common permission issues
   sudo chown -R www-data:www-data /var/www/whmcs/includes/hooks
   sudo chown -R www-data:www-data /var/www/whmcs/storage/logs
   sudo chmod +x /usr/local/bin/whmcs_server_monitor.sh
   ```

### Log Locations

- WHMCS notifications: `/var/www/whmcs/storage/logs/notifications.log`
- WHMCS application: `/var/www/whmcs/storage/logs/laravel.log`
- ntfy server: `/var/log/ntfy/ntfy.log`
- System monitoring: `/var/log/syslog`

## Maintenance

### Regular Tasks

1. **Update configurations** when changing servers/domains
2. **Review and rotate logs** monthly
3. **Test notification delivery** weekly
4. **Update monitoring thresholds** based on usage patterns
5. **Review and clean old backups** quarterly

### Performance Optimization

1. **Database indexing** for faster queries
2. **Log rotation** to prevent disk space issues
3. **Rate limiting** to prevent notification spam
4. **Caching** for dashboard data

## Support

For issues with this monitoring system:

1. Check the troubleshooting section above
2. Review log files for error messages
3. Test individual components in isolation
4. Verify network connectivity and permissions

The system is designed to be resilient and continue working even if individual components fail. Each monitoring layer operates independently to ensure comprehensive coverage.
