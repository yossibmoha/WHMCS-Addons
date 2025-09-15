#!/bin/bash
# ntfy Security Setup Script

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_status() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

echo -e "${BLUE}🔒 Setting up ntfy Security${NC}"
echo -e "${BLUE}=========================${NC}"
echo ""

# Check if ntfy is installed
if ! command -v ntfy &> /dev/null; then
    print_error "ntfy is not installed. Please install it first."
    exit 1
fi

# Stop ntfy service if running
if systemctl is-active --quiet ntfy; then
    echo "Stopping ntfy service..."
    sudo systemctl stop ntfy
fi

# Backup existing configuration
if [ -f /etc/ntfy/server.yml ]; then
    echo "Creating backup of existing configuration..."
    sudo cp /etc/ntfy/server.yml /etc/ntfy/server.yml.backup.$(date +%Y%m%d-%H%M%S)
fi

# Copy secure configuration
echo "Installing secure configuration..."
sudo cp config/ntfy-server-secure.yml /etc/ntfy/server.yml

# Create necessary directories
sudo mkdir -p /var/lib/ntfy
sudo mkdir -p /var/cache/ntfy/attachments
sudo chown -R ntfy:ntfy /var/lib/ntfy /var/cache/ntfy

# Initialize authentication database
echo "Setting up authentication..."
sudo -u ntfy ntfy user add --role=admin admin

print_warning "Please set a strong password for the admin user when prompted."

# Create WHMCS monitoring user
sudo -u ntfy ntfy user add whmcs-monitor

print_warning "Please set a password for the whmcs-monitor user when prompted."

# Set up topic permissions
echo "Configuring topic permissions..."

# Admin gets full access
sudo -u ntfy ntfy access admin "*" rw

# WHMCS monitor gets specific topic access
sudo -u ntfy ntfy access whmcs-monitor whmcs-alerts rw
sudo -u ntfy ntfy access whmcs-monitor server-monitor rw
sudo -u ntfy ntfy access whmcs-monitor whmcs-dev-alerts rw
sudo -u ntfy ntfy access whmcs-monitor whmcs-staging-alerts rw

# Create systemd override for security
echo "Creating systemd security overrides..."
sudo mkdir -p /etc/systemd/system/ntfy.service.d
cat << 'EOF' | sudo tee /etc/systemd/system/ntfy.service.d/security.conf > /dev/null
[Service]
# Security settings
NoNewPrivileges=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectHome=yes
ProtectSystem=strict
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictRealtime=yes
RestrictNamespaces=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
SystemCallFilter=@system-service
SystemCallFilter=~@debug @mount @cpu-emulation @obsolete @privileged @reboot @swap

# Directory permissions
ReadWritePaths=/var/lib/ntfy /var/cache/ntfy /var/log/ntfy
ReadOnlyPaths=/etc/ntfy

# User and group
User=ntfy
Group=ntfy
EOF

# Reload systemd
sudo systemctl daemon-reload

# Create log rotation
echo "Setting up log rotation..."
cat << 'EOF' | sudo tee /etc/logrotate.d/ntfy > /dev/null
/var/log/ntfy/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 640 ntfy ntfy
    postrotate
        /bin/systemctl reload ntfy.service > /dev/null 2>&1 || true
    endrotate
}
EOF

# Create monitoring script for ntfy health
echo "Creating ntfy health monitoring script..."
cat << 'EOF' | sudo tee /usr/local/bin/ntfy-health-check.sh > /dev/null
#!/bin/bash
# ntfy Health Check Script

NTFY_URL="http://localhost:80/v1/health"
LOG_FILE="/var/log/ntfy/health-check.log"

# Create log directory if it doesn't exist
sudo mkdir -p "$(dirname "$LOG_FILE")"

# Check ntfy health
if curl -f -s "$NTFY_URL" > /dev/null 2>&1; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - ntfy health check: OK" >> "$LOG_FILE"
    exit 0
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') - ntfy health check: FAILED" >> "$LOG_FILE"
    
    # Try to restart ntfy service
    if systemctl is-active --quiet ntfy; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') - Restarting ntfy service" >> "$LOG_FILE"
        systemctl restart ntfy
        sleep 5
        
        # Check again after restart
        if curl -f -s "$NTFY_URL" > /dev/null 2>&1; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') - ntfy health check after restart: OK" >> "$LOG_FILE"
        else
            echo "$(date '+%Y-%m-%d %H:%M:%S') - ntfy health check after restart: STILL FAILED" >> "$LOG_FILE"
            exit 1
        fi
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') - ntfy service is not running" >> "$LOG_FILE"
        exit 1
    fi
fi
EOF

sudo chmod +x /usr/local/bin/ntfy-health-check.sh

# Create fail2ban configuration for ntfy
echo "Setting up fail2ban protection..."
if command -v fail2ban-client &> /dev/null; then
    cat << 'EOF' | sudo tee /etc/fail2ban/jail.d/ntfy.conf > /dev/null
[ntfy]
enabled = true
port = 80,443
filter = ntfy
logpath = /var/log/nginx/access.log
maxretry = 5
bantime = 3600
findtime = 600
action = iptables[name=ntfy, port="http,https", protocol=tcp]
EOF

    cat << 'EOF' | sudo tee /etc/fail2ban/filter.d/ntfy.conf > /dev/null
[Definition]
failregex = ^<HOST> -.*"(GET|POST|PUT) /.*" (401|403|429) .*$
ignoreregex =
EOF

    sudo systemctl reload fail2ban
    print_status "fail2ban protection configured"
else
    print_warning "fail2ban not installed - consider installing for additional security"
fi

# Update WHMCS configuration with authentication
echo "Updating WHMCS notification configuration..."
WHMCS_CONFIG_FILE="../includes/hooks/whmcs_notification_config.php"

if [ -f "$WHMCS_CONFIG_FILE" ]; then
    # Create an enhanced configuration with authentication
    cat << 'EOF' > "../includes/hooks/whmcs_notification_config_secure.php"
<?php
// Secure WHMCS Notification Configuration

// Environment-based configuration
$environment = $_ENV['WHMCS_ENV'] ?? 'production';

switch ($environment) {
    case 'development':
        define('NTFY_SERVER_URL', 'http://localhost:80');
        define('NTFY_TOPIC', 'whmcs-dev-alerts');
        define('NTFY_USERNAME', 'whmcs-monitor');
        define('NTFY_PASSWORD', 'your-dev-password');
        break;
    
    case 'staging':
        define('NTFY_SERVER_URL', 'https://staging-ntfy.yourdomain.com');
        define('NTFY_TOPIC', 'whmcs-staging-alerts');
        define('NTFY_USERNAME', 'whmcs-monitor');
        define('NTFY_PASSWORD', 'your-staging-password');
        break;
    
    default: // production
        define('NTFY_SERVER_URL', 'https://your-ntfy-server.com');
        define('NTFY_TOPIC', 'whmcs-alerts');
        define('NTFY_USERNAME', 'whmcs-monitor');
        define('NTFY_PASSWORD', 'your-production-password');
        break;
}

define('NOTIFICATION_EMAIL', 'admin@yourdomain.com');
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_WINDOW', 300); // 5 minutes
define('RATE_LIMIT_MAX', 10);

// Rate limiting storage
$rateLimitFile = __DIR__ . '/../../storage/logs/rate_limit.json';

function sendDualNotification($title, $message, $priority = 3, $tags = '') {
    global $rateLimitFile;
    
    // Check rate limit
    if (RATE_LIMIT_ENABLED && !checkRateLimit($title, $rateLimitFile)) {
        error_log("Rate limit exceeded for notification: $title");
        return false;
    }
    
    // Send ntfy notification with authentication
    sendNtfyNotification($title, $message, $priority, $tags);
    
    // Send email notification
    sendEmailNotification($title, $message, $priority);
    
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
            'Authorization: Bearer ' . base64_encode(NTFY_USERNAME . ':' . NTFY_PASSWORD)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'WHMCS-Monitor/2.0'
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($result === false || $httpCode !== 200) {
        error_log("ntfy notification failed: $error (HTTP $httpCode)");
        return false;
    }
    
    return true;
}

function sendEmailNotification($subject, $body, $priority = 3) {
    // Implementation remains the same as original
    $to = NOTIFICATION_EMAIL;
    $headers = [
        "From: WHMCS Notifications <noreply@yourdomain.com>",
        "Content-Type: text/html; charset=UTF-8",
        "X-Priority: " . (6 - $priority)
    ];
    
    $emailBody = "<h3>$subject</h3><p>" . nl2br(htmlspecialchars($body)) . "</p>";
    $emailBody .= "<hr><p><small>Sent from WHMCS at " . date('Y-m-d H:i:s') . "</small></p>";
    
    return mail($to, "[WHMCS] $subject", $emailBody, implode("\r\n", $headers));
}

function checkRateLimit($identifier, $rateLimitFile) {
    $now = time();
    $key = md5($identifier);
    
    // Load existing data
    $data = [];
    if (file_exists($rateLimitFile)) {
        $data = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }
    
    // Clean old entries
    $data = array_filter($data, function($timestamp) use ($now) {
        return ($now - $timestamp) < RATE_LIMIT_WINDOW;
    });
    
    // Count current entries for this identifier
    $count = 0;
    foreach ($data as $entryKey => $timestamp) {
        if (strpos($entryKey, $key) === 0) {
            $count++;
        }
    }
    
    if ($count >= RATE_LIMIT_MAX) {
        return false;
    }
    
    // Add new entry
    $data[$key . '_' . $now] = $now;
    
    // Save data
    file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
    
    return true;
}
?>
EOF
    
    print_status "Secure WHMCS configuration created"
    print_warning "Please update the passwords in whmcs_notification_config_secure.php"
else
    print_warning "WHMCS configuration file not found - manual setup required"
fi

# Start ntfy service
echo "Starting ntfy service..."
sudo systemctl start ntfy
sudo systemctl enable ntfy

# Wait a moment for service to start
sleep 3

# Test the service
if systemctl is-active --quiet ntfy; then
    print_status "ntfy service is running"
else
    print_error "Failed to start ntfy service"
    sudo systemctl status ntfy
    exit 1
fi

# Create cron job for health checks
echo "Setting up health monitoring cron job..."
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/ntfy-health-check.sh") | crontab -

print_status "ntfy security setup completed successfully!"

echo ""
echo -e "${BLUE}Security Summary:${NC}"
echo "✅ Authentication enabled (deny-all by default)"
echo "✅ Rate limiting configured"
echo "✅ Systemd security hardening applied"
echo "✅ Log rotation set up"
echo "✅ Health monitoring configured"
echo "✅ fail2ban protection (if available)"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "1. Update WHMCS configuration with authentication credentials"
echo "2. Configure reverse proxy with HTTPS"
echo "3. Test notifications with authentication"
echo "4. Monitor logs: /var/log/ntfy/"
echo ""
echo -e "${YELLOW}User Accounts Created:${NC}"
echo "- admin (full access)"
echo "- whmcs-monitor (topic-specific access)"
echo ""
echo "Test notification:"
echo "curl -u whmcs-monitor:password -d 'Test message' https://your-server.com/whmcs-alerts"
