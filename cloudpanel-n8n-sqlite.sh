#!/bin/bash

# CloudPanel V2 + n8n with SQLite - Complete Installation Script
# Simplified approach using SQLite database for n8n

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_message() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] ✅${NC} $1"
}

log_error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ❌${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] ⚠️${NC} $1"
}

log_message "🚀 Starting CloudPanel V2 + n8n (SQLite) installation..."

# Create log directory
mkdir -p /var/log/cloudpanel-n8n-install
exec > >(tee -a /var/log/cloudpanel-n8n-install/install.log)
exec 2>&1

# Step 1: System preparation
log_message "📦 Step 1: System preparation..."
apt update -y
apt install -y curl wget git ufw software-properties-common ca-certificates gnupg lsb-release net-tools

# Configure firewall
ufw --force enable
ufw allow 22
ufw allow 80
ufw allow 443
ufw allow 8443
ufw allow 5678

# Step 2: Clean up any broken CloudPanel installation
log_message "🧹 Step 2: Cleaning up any existing CloudPanel installation..."
systemctl stop cloudpanel 2>/dev/null || true
systemctl stop nginx 2>/dev/null || true
systemctl stop mysql 2>/dev/null || true

# Force remove broken CloudPanel package
apt-mark unhold cloudpanel 2>/dev/null || true
dpkg --remove --force-remove-reinstreq cloudpanel 2>/dev/null || true
apt purge -y cloudpanel 2>/dev/null || true

# Complete cleanup
rm -rf /home/clp/.cloudpanel /home/clp/.clp-installation /home/clp/.cloud
rm -rf /etc/cloudpanel /var/lib/cloudpanel /var/log/cloudpanel
rm -rf /opt/cloudpanel /usr/local/clp
rm -f /etc/apt/sources.list.d/cloudpanel.list

# Fix any dpkg issues
dpkg --configure -a
apt --fix-broken install -y
apt autoremove -y

log_success "System cleaned and prepared"

# Step 3: Fresh CloudPanel installation
log_message "📦 Step 3: Installing CloudPanel V2..."
cd /tmp

curl -sS https://installer.cloudpanel.io/ce/v2/install.sh -o install.sh
echo "985bed747446eabad433c2e8115e21d6898628fa982c9e55ff6cd0d7c35b501d install.sh" | sha256sum -c

if [ $? -eq 0 ]; then
    log_success "CloudPanel installer verified"
    DB_ENGINE=MYSQL_8.4 bash install.sh
    
    if [ $? -eq 0 ]; then
        log_success "CloudPanel installed successfully"
    else
        log_error "CloudPanel installation failed"
        exit 1
    fi
else
    log_error "CloudPanel installer verification failed"
    exit 1
fi

# Step 4: Verify CloudPanel installation
log_message "✅ Step 4: Verifying CloudPanel installation..."
sleep 60

if systemctl is-active --quiet cloudpanel; then
    log_success "CloudPanel service is running"
else
    log_error "CloudPanel service is not running, attempting to start..."
    systemctl start cloudpanel
    sleep 30
    if systemctl is-active --quiet cloudpanel; then
        log_success "CloudPanel service started successfully"
    else
        log_error "Failed to start CloudPanel service"
        systemctl status cloudpanel
        exit 1
    fi
fi

if command -v clp &> /dev/null; then
    log_success "CloudPanel CLI is available"
else
    log_error "CloudPanel CLI is not available"
    exit 1
fi

# Step 5: Install Docker
log_message "🐳 Step 5: Installing Docker..."
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    systemctl start docker
    systemctl enable docker
    usermod -aG docker clp
    log_success "Docker installed"
else
    log_success "Docker already installed"
    systemctl start docker
    systemctl enable docker
fi

if ! command -v docker-compose &> /dev/null; then
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
    log_success "Docker Compose installed"
else
    log_success "Docker Compose already installed"
fi

# Step 6: Create CloudPanel site for n8n
log_message "🌐 Step 6: Creating CloudPanel site for n8n..."
SERVER_IP=$(curl -s ifconfig.me)
N8N_DOMAIN="n8n.${SERVER_IP}.nip.io"

if [ -d "/home/clp/sites/$N8N_DOMAIN" ]; then
    log_warning "Site already exists, removing it first..."
    su - clp -c "clp site:delete --domain=$N8N_DOMAIN --force" || true
    rm -rf /home/clp/sites/$N8N_DOMAIN
fi

su - clp -c "clp site:add --domain=$N8N_DOMAIN --php=8.2 --vhost=nginx"

if [ $? -eq 0 ]; then
    log_success "CloudPanel site created: $N8N_DOMAIN"
else
    log_error "Failed to create CloudPanel site"
    exit 1
fi

# Step 7: Setup n8n with SQLite
log_message "🔧 Step 7: Setting up n8n Docker with SQLite..."

mkdir -p /home/clp/sites/$N8N_DOMAIN/docker
mkdir -p /home/clp/sites/$N8N_DOMAIN/n8n-data

# Create Docker Compose with SQLite
cat > /home/clp/sites/$N8N_DOMAIN/docker/docker-compose.yml << EOF
version: '3.8'

services:
  n8n:
    image: n8nio/n8n:latest
    container_name: n8n
    restart: unless-stopped
    ports:
      - "5678:5678"
    environment:
      - NODE_ENV=production
      - N8N_BASIC_AUTH_ACTIVE=true
      - N8N_BASIC_AUTH_USER=admin
      - N8N_BASIC_AUTH_PASSWORD=n8n_admin_2024
      - N8N_ENCRYPTION_KEY=n8n_encryption_key_2024_change_me
      - DB_TYPE=sqlite
      - DB_SQLITE_DATABASE=/home/node/.n8n/database.sqlite
      - N8N_HOST=0.0.0.0
      - N8N_PORT=5678
      - N8N_PROTOCOL=http
      - WEBHOOK_URL=http://$N8N_DOMAIN
    volumes:
      - /home/clp/sites/$N8N_DOMAIN/n8n-data:/home/node/.n8n
    networks:
      - n8n_network

networks:
  n8n_network:
    driver: bridge
EOF

log_success "Docker Compose configuration created with SQLite"

# Step 8: Configure Nginx proxy
log_message "🌐 Step 8: Configuring Nginx proxy..."
cat > /home/clp/sites/$N8N_DOMAIN/conf/nginx.conf << EOF
server {
    listen 80;
    server_name $N8N_DOMAIN;
    
    location / {
        proxy_pass http://127.0.0.1:5678;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
        proxy_read_timeout 86400;
        proxy_send_timeout 86400;
    }
}
EOF

if nginx -t; then
    systemctl reload nginx
    log_success "Nginx configuration updated"
else
    log_error "Nginx configuration test failed"
    exit 1
fi

# Step 9: Start n8n container
log_message "🚀 Step 9: Starting n8n Docker container..."

chown -R clp:clp /home/clp/sites/$N8N_DOMAIN/

# Stop any existing container
docker stop n8n 2>/dev/null || true
docker rm n8n 2>/dev/null || true

cd /home/clp/sites/$N8N_DOMAIN/docker
su - clp -c "cd /home/clp/sites/$N8N_DOMAIN/docker && docker-compose up -d"

if [ $? -eq 0 ]; then
    log_success "n8n Docker container started"
    sleep 30
    
    if docker ps | grep -q n8n; then
        log_success "n8n Docker container is running!"
        
        # Test n8n web interface
        for i in {1..10}; do
            if curl -s http://localhost:5678 | grep -q "n8n" 2>/dev/null; then
                log_success "n8n web interface is accessible"
                break
            fi
            sleep 5
        done
    else
        log_error "n8n Docker container failed to start"
        docker logs n8n || true
    fi
else
    log_error "Failed to start n8n Docker container"
fi

# Step 10: Create verification script
log_message "🔍 Step 10: Creating verification script..."
cat > /usr/local/bin/check-installation << 'EOF'
#!/bin/bash

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}🔍 CloudPanel V2 + n8n (SQLite) Installation Verification${NC}"
echo "========================================================"
echo ""

echo -e "${BLUE}📊 SYSTEM INFORMATION${NC}"
echo "===================="
echo -e "🖥️  Server IP: $(curl -s ifconfig.me)"
echo -e "📅 Current Time: $(date)"
echo ""

echo -e "${BLUE}🔧 SERVICE STATUS CHECK${NC}"
echo "======================"

# Check CloudPanel
if systemctl is-active --quiet cloudpanel; then
    echo -e "🔍 CloudPanel service... ${GREEN}✅ RUNNING${NC}"
else
    echo -e "🔍 CloudPanel service... ${RED}❌ NOT RUNNING${NC}"
fi

# Check n8n Docker container
if docker ps | grep -q n8n; then
    echo -e "🔍 n8n Docker container... ${GREEN}✅ RUNNING${NC}"
else
    echo -e "🔍 n8n Docker container... ${RED}❌ NOT RUNNING${NC}"
fi

# Check Nginx
if systemctl is-active --quiet nginx; then
    echo -e "🔍 Nginx service... ${GREEN}✅ RUNNING${NC}"
else
    echo -e "🔍 Nginx service... ${RED}❌ NOT RUNNING${NC}"
fi

echo ""
echo -e "${BLUE}🌐 WEB INTERFACE ACCESS${NC}"
echo "======================"
SERVER_IP=$(curl -s ifconfig.me)
echo -e "🌐 CloudPanel: https://$SERVER_IP:8443"
echo -e "🔧 n8n: http://n8n.$SERVER_IP.nip.io"
echo -e "🔧 n8n Direct: http://$SERVER_IP:5678"
echo ""

echo -e "${BLUE}🔐 CREDENTIALS${NC}"
echo "=============="
echo -e "🔑 n8n Login: admin / n8n_admin_2024"
if [ -f /home/clp/.clp-installation ]; then
    echo -e "📋 CloudPanel credentials: /home/clp/.clp-installation"
fi

echo ""
RUNNING_SERVICES=0
TOTAL_SERVICES=3

if systemctl is-active --quiet cloudpanel; then ((RUNNING_SERVICES++)); fi
if docker ps | grep -q n8n; then ((RUNNING_SERVICES++)); fi
if systemctl is-active --quiet nginx; then ((RUNNING_SERVICES++)); fi

echo -e "${BLUE}📊 INSTALLATION SUMMARY${NC}"
echo "======================"
echo -e "📈 Services Running: $RUNNING_SERVICES/$TOTAL_SERVICES"

if [ $RUNNING_SERVICES -eq $TOTAL_SERVICES ]; then
    echo -e "${GREEN}✅ INSTALLATION SUCCESSFUL${NC}"
else
    echo -e "${RED}❌ INSTALLATION ISSUES${NC}"
fi

echo ""
echo -e "📞 Support: support@vps-server.host"
EOF

chmod +x /usr/local/bin/check-installation
log_success "Verification script created"

# Step 11: Update MOTD
log_message "🎨 Step 11: Updating MOTD..."
cat > /etc/motd << 'EOF'
            __   __   ______   ______           ______     ______     ______     __   __   ______     ______           __  __     ______     ______     ______
           /\ \ / /  /\  == \ /\  ___\         /\  ___\   /\  ___\   /\  == \   /\ \ / /  /\  ___\   /\  == \         /\ \_\ \   /\  __ \   /\  ___\   /\__  _\
           \ \ \'/   \ \  _-/ \ \___  \        \ \___  \  \ \  __\   \ \  __<   \ \ \'/   \ \  __\   \ \  __<         \ \  __ \  \ \ \/\ \  \ \___  \  \/_/\ \/
            \ \__|    \ \_\    \/\_____\        \/\_____\  \ \_____\  \ \_\ \_\  \ \__|    \ \_____\  \ \_\ \_\        \ \_\ \_\  \ \_____\  \/\_____\    \ \_\
             \/_/      \/_/     \/_____/         \/_____/   \/_____/   \/_/ /_/   \/_/      \/_____/   \/_/ /_/         \/_/\/_/   \/_____/   \/_____/     \/_/

🚀 VPS SERVER OPTIMIZED - DEPLOYMENT SUCCESSFUL! 🚀

🌐 CloudPanel V2: https://SERVER_IP:8443
🔧 n8n Automation: http://n8n.SERVER_IP.nip.io (SQLite)
📊 Database: SQLite (No MySQL required!)

📞 Support: support@vps-server.host
🔧 Run 'sudo check-installation' to verify services

EOF

sed -i "s/SERVER_IP/$(curl -s ifconfig.me)/g" /etc/motd
log_success "MOTD updated"

# Final verification
log_message "🔍 Final verification..."
/usr/local/bin/check-installation

log_success "🎉 CloudPanel V2 + n8n (SQLite) installation completed!"
log_message "📋 Log: /var/log/cloudpanel-n8n-install/install.log"
log_message ""
log_message "🌐 Access your services:"
log_message "   CloudPanel: https://$(curl -s ifconfig.me):8443"
log_message "   n8n: http://n8n.$(curl -s ifconfig.me).nip.io"
log_message "   n8n Direct: http://$(curl -s ifconfig.me):5678"
log_message "   n8n Login: admin / n8n_admin_2024"
