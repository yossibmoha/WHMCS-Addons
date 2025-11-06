#!/bin/bash
# WHMCS Monitoring System Deployment Script
# Usage: ./deploy.sh [environment] [whmcs_path]

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
ENVIRONMENT=${1:-production}
WHMCS_PATH=${2:-/var/www/whmcs}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="/backup/whmcs-monitoring-$(date +%Y%m%d-%H%M%S)"

echo -e "${BLUE}🚀 WHMCS Monitoring System Deployment${NC}"
echo -e "${BLUE}======================================${NC}"
echo "Environment: $ENVIRONMENT"
echo "WHMCS Path: $WHMCS_PATH"
echo "Script Dir: $SCRIPT_DIR"
echo ""

# Function to print status
print_status() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# Validation
validate_environment() {
    echo "🔍 Validating environment..."
    
    # Check if WHMCS directory exists
    if [ ! -d "$WHMCS_PATH" ]; then
        print_error "WHMCS directory not found: $WHMCS_PATH"
        exit 1
    fi
    
    # Check if WHMCS configuration exists
    if [ ! -f "$WHMCS_PATH/configuration.php" ]; then
        print_error "WHMCS configuration.php not found"
        exit 1
    fi
    
    # Check PHP version
    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    if ! php -v | grep -q "PHP [7-8]"; then
        print_warning "PHP version $PHP_VERSION might not be fully supported"
    fi
    
    # Check required PHP extensions
    REQUIRED_EXTENSIONS=("curl" "json" "openssl")
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if ! php -m | grep -q "$ext"; then
            print_error "Required PHP extension missing: $ext"
            exit 1
        fi
    done
    
    print_status "Environment validation completed"
}

# Create backup
create_backup() {
    echo "💾 Creating backup..."
    
    mkdir -p "$BACKUP_DIR"
    
    # Backup existing hooks if any
    if [ -d "$WHMCS_PATH/includes/hooks" ]; then
        cp -r "$WHMCS_PATH/includes/hooks" "$BACKUP_DIR/hooks_backup" 2>/dev/null || true
    fi
    
    # Backup configuration
    if [ -f "$WHMCS_PATH/configuration.php" ]; then
        cp "$WHMCS_PATH/configuration.php" "$BACKUP_DIR/configuration_backup.php"
    fi
    
    print_status "Backup created: $BACKUP_DIR"
}

# Deploy hook files
deploy_hooks() {
    echo "📁 Deploying hook files..."
    
    # Create hooks directory if it doesn't exist
    mkdir -p "$WHMCS_PATH/includes/hooks"
    
    # Copy hook files
    cp "$SCRIPT_DIR/includes/hooks/"*.php "$WHMCS_PATH/includes/hooks/"
    
    # Set proper permissions
    chown -R www-data:www-data "$WHMCS_PATH/includes/hooks" 2>/dev/null || true
    chmod -R 644 "$WHMCS_PATH/includes/hooks"/*.php
    
    print_status "Hook files deployed"
}

# Deploy monitoring scripts
deploy_monitoring_scripts() {
    echo "📊 Deploying monitoring scripts..."
    
    # Deploy API monitor
    cp "$SCRIPT_DIR/whmcs_api_monitor.php" "$WHMCS_PATH/"
    chmod 644 "$WHMCS_PATH/whmcs_api_monitor.php"
    
    # Deploy server monitor script to /usr/local/bin
    sudo cp "$SCRIPT_DIR/server_monitor_script.sh" /usr/local/bin/whmcs_server_monitor.sh
    sudo chmod +x /usr/local/bin/whmcs_server_monitor.sh
    
    # Create logs directory
    mkdir -p "$WHMCS_PATH/storage/logs"
    chown -R www-data:www-data "$WHMCS_PATH/storage/logs" 2>/dev/null || true
    
    print_status "Monitoring scripts deployed"
}

# Configure environment-specific settings
configure_environment() {
    echo "⚙️ Configuring for $ENVIRONMENT environment..."
    
    # Update configuration based on environment
    if [ -f "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php" ]; then
        case $ENVIRONMENT in
            "development")
                sed -i.bak "s/https:\/\/your-ntfy-server\.com/http:\/\/localhost:8080/g" "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php"
                sed -i.bak "s/whmcs-alerts/whmcs-dev-alerts/g" "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php"
                ;;
            "staging")
                sed -i.bak "s/https:\/\/your-ntfy-server\.com/https:\/\/staging-ntfy.yourdomain.com/g" "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php"
                sed -i.bak "s/whmcs-alerts/whmcs-staging-alerts/g" "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php"
                ;;
        esac
        rm -f "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php.bak"
    fi
    
    print_status "Environment configuration completed"
}

# Setup cron jobs
setup_cron_jobs() {
    echo "⏰ Setting up cron jobs..."
    
    # Create cron entries
    CRON_TEMP="/tmp/whmcs_monitoring_cron"
    cat > "$CRON_TEMP" << EOF
# WHMCS Monitoring System Cron Jobs

# External API monitoring (every 15 minutes)
*/15 * * * * /usr/bin/php $WHMCS_PATH/whmcs_api_monitor.php > /dev/null 2>&1

# Server monitoring (every 5 minutes)
*/5 * * * * /usr/local/bin/whmcs_server_monitor.sh > /dev/null 2>&1

# Daily summary (9 AM)
0 9 * * * /usr/local/bin/whmcs_server_monitor.sh summary

# Weekly log rotation (Sunday 2 AM)
0 2 * * 0 find $WHMCS_PATH/storage/logs -name "*.log" -type f -mtime +7 -delete

EOF
    
    # Install cron jobs
    if command -v crontab > /dev/null; then
        crontab -l > /tmp/current_cron 2>/dev/null || true
        cat /tmp/current_cron "$CRON_TEMP" | crontab -
        rm -f "$CRON_TEMP" /tmp/current_cron
        print_status "Cron jobs installed"
    else
        print_warning "Crontab not available. Please manually add cron jobs from: $CRON_TEMP"
    fi
}

# Install ntfy server (optional)
install_ntfy_server() {
    if [ "$ENVIRONMENT" = "production" ]; then
        read -p "Do you want to install ntfy server on this machine? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            echo "📱 Installing ntfy server..."
            
            # Install ntfy
            if command -v apt-get > /dev/null; then
                sudo apt-get update
                sudo apt-get install -y curl
                curl -sSL https://archive.heckel.io/apt/pubkey.txt | sudo apt-key add -
                echo "deb [arch=amd64] https://archive.heckel.io/apt debian main" | sudo tee /etc/apt/sources.list.d/archive.heckel.io.list
                sudo apt-get update
                sudo apt-get install -y ntfy
                
                # Configure ntfy
                sudo mkdir -p /etc/ntfy
                cat > /tmp/ntfy_config.yml << EOF
base-url: "https://$(hostname -f)"
listen-http: ":8080"
cache-file: "/var/cache/ntfy/cache.db"
cache-duration: "12h"
keepalive-interval: "45s"
manager-interval: "1m"
web-root: "/"
EOF
                sudo mv /tmp/ntfy_config.yml /etc/ntfy/server.yml
                
                # Start and enable ntfy service
                sudo systemctl enable ntfy
                sudo systemctl start ntfy
                
                print_status "ntfy server installed and started"
            else
                print_warning "Auto-installation only supported on Debian/Ubuntu. Please install manually."
            fi
        fi
    fi
}

# Test deployment
test_deployment() {
    echo "🧪 Testing deployment..."
    
    # Test PHP syntax
    if php -l "$WHMCS_PATH/includes/hooks/whmcs_notification_config.php" > /dev/null 2>&1; then
        print_status "PHP syntax check passed"
    else
        print_error "PHP syntax error in configuration file"
        exit 1
    fi
    
    # Test notification function
    php -r "
        require_once '$WHMCS_PATH/includes/hooks/whmcs_notification_config.php';
        if (function_exists('sendDualNotification')) {
            echo 'Functions loaded successfully\n';
        } else {
            echo 'Error: Functions not loaded\n';
            exit(1);
        }
    " || exit 1
    
    # Test server monitoring script
    if /usr/local/bin/whmcs_server_monitor.sh disk > /dev/null 2>&1; then
        print_status "Server monitoring script working"
    else
        print_warning "Server monitoring script may have issues"
    fi
    
    print_status "Deployment tests completed"
}

# Generate summary
generate_summary() {
    echo ""
    echo -e "${BLUE}📋 Deployment Summary${NC}"
    echo -e "${BLUE}===================${NC}"
    echo "Environment: $ENVIRONMENT"
    echo "WHMCS Path: $WHMCS_PATH"
    echo "Backup Location: $BACKUP_DIR"
    echo ""
    echo -e "${GREEN}✅ Deployed Components:${NC}"
    echo "  - Hook files for all WHMCS events"
    echo "  - External API monitoring script"
    echo "  - Server monitoring script"
    echo "  - Cron jobs for automated monitoring"
    echo "  - Log rotation setup"
    echo ""
    echo -e "${YELLOW}⚙️ Next Steps:${NC}"
    echo "1. Update notification URLs in configuration files:"
    echo "   - Edit: $WHMCS_PATH/includes/hooks/whmcs_notification_config.php"
    echo "   - Set your ntfy server URL and email address"
    echo ""
    echo "2. Configure ntfy server (if not installed automatically):"
    echo "   - Install ntfy server: https://docs.ntfy.sh/"
    echo "   - Configure SSL/TLS"
    echo "   - Set up authentication (recommended)"
    echo ""
    echo "3. Install ntfy app on your iPhone:"
    echo "   - Download from App Store"
    echo "   - Subscribe to topics: whmcs-alerts, server-monitor"
    echo ""
    echo "4. Test the system:"
    echo "   - curl -d 'Test message' https://your-ntfy-server.com/whmcs-alerts"
    echo "   - php -r \"require_once '$WHMCS_PATH/includes/hooks/whmcs_notification_config.php'; sendDualNotification('Test', 'Working!', 3, 'test');\""
    echo ""
    echo -e "${GREEN}🎉 Deployment completed successfully!${NC}"
}

# Main deployment flow
main() {
    validate_environment
    create_backup
    deploy_hooks
    deploy_monitoring_scripts
    configure_environment
    setup_cron_jobs
    install_ntfy_server
    test_deployment
    generate_summary
}

# Handle interruption
trap 'print_error "Deployment interrupted"; exit 1' INT TERM

# Run main function
main

exit 0
