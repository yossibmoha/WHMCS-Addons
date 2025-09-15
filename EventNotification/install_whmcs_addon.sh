#!/bin/bash
# WHMCS Monitoring Addon Installation Script

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

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

echo -e "${BLUE}🚀 WHMCS Monitoring System - Addon Installation${NC}"
echo -e "${BLUE}================================================${NC}"

# Get WHMCS path
if [ -z "$1" ]; then
    echo "Usage: $0 /path/to/whmcs"
    echo "Example: $0 /var/www/whmcs"
    exit 1
fi

WHMCS_PATH="$1"
ADDON_SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/monitoring"
ADDON_DEST="$WHMCS_PATH/modules/addons/monitoring"

# Validate WHMCS installation
echo "🔍 Validating WHMCS installation..."

if [ ! -d "$WHMCS_PATH" ]; then
    print_error "WHMCS directory not found: $WHMCS_PATH"
    exit 1
fi

if [ ! -f "$WHMCS_PATH/configuration.php" ]; then
    print_error "WHMCS configuration.php not found in $WHMCS_PATH"
    exit 1
fi

if [ ! -d "$WHMCS_PATH/modules/addons" ]; then
    print_error "WHMCS addons directory not found: $WHMCS_PATH/modules/addons"
    exit 1
fi

print_status "WHMCS installation validated"

# Check if addon source exists
if [ ! -d "$ADDON_SOURCE" ]; then
    print_error "Addon source directory not found: $ADDON_SOURCE"
    print_info "Please run this script from the EventNotification directory"
    exit 1
fi

print_status "Addon source files found"

# Create addon directory
echo "📁 Installing addon files..."

if [ -d "$ADDON_DEST" ]; then
    print_warning "Addon directory already exists. Creating backup..."
    mv "$ADDON_DEST" "${ADDON_DEST}_backup_$(date +%Y%m%d_%H%M%S)"
fi

# Copy addon files
mkdir -p "$ADDON_DEST"
cp -r "$ADDON_SOURCE/"* "$ADDON_DEST/"

print_status "Addon files copied to $ADDON_DEST"

# Set proper permissions
echo "🔒 Setting file permissions..."

# Find web server user
WEB_USER="www-data"
if id "apache" &>/dev/null; then
    WEB_USER="apache"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
fi

# Set ownership if running as root
if [ "$EUID" -eq 0 ]; then
    chown -R $WEB_USER:$WEB_USER "$ADDON_DEST"
    print_status "Ownership set to $WEB_USER"
else
    print_warning "Not running as root - please set ownership manually: chown -R $WEB_USER:$WEB_USER $ADDON_DEST"
fi

# Set file permissions
chmod -R 644 "$ADDON_DEST"/*.php
chmod 755 "$ADDON_DEST"

print_status "File permissions configured"

# Create symbolic link to monitoring system (optional)
echo "🔗 Creating monitoring system link..."

MONITORING_SOURCE="$(dirname "$ADDON_SOURCE")"
MONITORING_LINK="$ADDON_DEST/monitoring_system"

if [ ! -L "$MONITORING_LINK" ]; then
    ln -s "$MONITORING_SOURCE" "$MONITORING_LINK"
    print_status "Symbolic link created: $MONITORING_LINK"
else
    print_info "Symbolic link already exists"
fi

# Verify installation
echo "🧪 Verifying installation..."

REQUIRED_FILES=(
    "monitoring.php"
    "api.php" 
    "hooks.php"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ -f "$ADDON_DEST/$file" ]; then
        print_status "File exists: $file"
    else
        print_error "Missing file: $file"
        exit 1
    fi
done

# Check PHP syntax
echo "🔍 Checking PHP syntax..."

for file in "${REQUIRED_FILES[@]}"; do
    if php -l "$ADDON_DEST/$file" > /dev/null 2>&1; then
        print_status "PHP syntax OK: $file"
    else
        print_error "PHP syntax error in: $file"
        php -l "$ADDON_DEST/$file"
        exit 1
    fi
done

# Installation summary
echo ""
echo -e "${GREEN}🎉 WHMCS Monitoring Addon Installation Complete!${NC}"
echo -e "${BLUE}=================================================${NC}"
echo ""
echo "📍 Installation Location: $ADDON_DEST"
echo "🔗 Monitoring System: $MONITORING_LINK"
echo ""
echo -e "${YELLOW}📋 Next Steps:${NC}"
echo "1. Login to your WHMCS Admin Panel"
echo "2. Go to Setup → Addon Modules"
echo "3. Find 'WHMCS Monitoring System' and click 'Activate'"
echo "4. Configure the addon settings:"
echo "   - ntfy Server URL (e.g., https://ntfy.yourdomain.com)"
echo "   - ntfy Topic (e.g., whmcs-alerts)"
echo "   - Notification Email"
echo "   - Environment (development/staging/production)"
echo "5. Grant access to admin roles: Setup → Administrator Roles"
echo "6. Access the monitoring system: Addons → WHMCS Monitoring System"
echo ""
echo -e "${YELLOW}🔧 Configuration Files Updated:${NC}"
echo "The addon will automatically update these files when you save settings:"
echo "- $WHMCS_PATH/../EventNotification/includes/hooks/whmcs_notification_config.php"
echo "- $WHMCS_PATH/../EventNotification/.env"
echo ""
echo -e "${YELLOW}📱 iPhone Setup Reminder:${NC}"
echo "Don't forget to configure your iPhone with the ntfy app:"
echo "1. Install 'ntfy' from the App Store"
echo "2. Add server: your-ntfy-server.com"
echo "3. Subscribe to your topic (e.g., whmcs-alerts)"
echo ""
echo -e "${GREEN}✨ You can now manage your entire monitoring system from within WHMCS!${NC}"

# Optional: Test addon accessibility
if [ -f "$ADDON_DEST/monitoring.php" ]; then
    echo ""
    print_info "Testing addon accessibility..."
    if php -r "require_once '$ADDON_DEST/monitoring.php'; echo 'Addon loaded successfully\n';" 2>/dev/null; then
        print_status "Addon is accessible and ready to activate"
    else
        print_warning "Addon may have loading issues - check WHMCS error logs after activation"
    fi
fi

echo ""
echo -e "${BLUE}For detailed usage instructions, see: WHMCS_ADDON_INSTALLATION.md${NC}"

exit 0
