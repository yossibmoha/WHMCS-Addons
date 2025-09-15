# 🚀 **WHMCS Contabo API Integration - Complete Installation Guide**

## **📋 Table of Contents**

1. [Requirements](#requirements)
2. [Pre-Installation](#pre-installation)
3. [Installation Steps](#installation-steps)
4. [API Configuration](#api-configuration)
5. [Initial Setup](#initial-setup)
6. [Feature Configuration](#feature-configuration)
7. [Testing](#testing)
8. [Troubleshooting](#troubleshooting)
9. [Advanced Configuration](#advanced-configuration)

---

## **🔧 Requirements**

### **Server Requirements**
- **PHP**: 8.0+ (PHP 8.2+ recommended)
- **WHMCS**: 8.0+ (8.13.1+ recommended)
- **MySQL**: 5.7+ or MariaDB 10.3+
- **cURL**: Enabled with SSL support
- **OpenSSL**: For secure API communications
- **GD Extension**: For image processing (custom images)

### **WHMCS Permissions**
- Admin access to install addon modules
- Database write permissions
- File system write access to `/modules/addons/`
- Cron job scheduling capability

### **Contabo Account Requirements**
- Active Contabo account
- API access enabled
- Billing method configured
- At least one active server (for testing)

---

## **📦 Pre-Installation**

### **1. Backup Your WHMCS**
```bash
# Create full WHMCS backup
tar -czf whmcs_backup_$(date +%Y%m%d).tar.gz /path/to/whmcs/
mysqldump -u username -p whmcs_database > whmcs_backup_$(date +%Y%m%d).sql
```

### **2. Check PHP Extensions**
```php
<?php
// Check required extensions
$extensions = ['curl', 'json', 'openssl', 'pdo_mysql', 'gd'];
foreach ($extensions as $ext) {
    echo $ext . ': ' . (extension_loaded($ext) ? 'OK' : 'MISSING') . "\n";
}
?>
```

### **3. Verify WHMCS Version**
- Login to WHMCS Admin Panel
- Go to **System Settings → System Info**
- Verify WHMCS version is 8.0 or higher

---

## **⚡ Installation Steps**

### **Step 1: Download & Extract**
1. Download the `contabo_addon` folder from your delivery
2. Extract to your WHMCS modules directory:
   ```
   /path/to/whmcs/modules/addons/contabo_addon/
   ```

### **Step 2: Set File Permissions**
```bash
# Set correct permissions
chmod 755 /path/to/whmcs/modules/addons/contabo_addon/
chmod -R 644 /path/to/whmcs/modules/addons/contabo_addon/*
chmod 755 /path/to/whmcs/modules/addons/contabo_addon/classes/
chmod 755 /path/to/whmcs/modules/addons/contabo_addon/templates/
chmod 755 /path/to/whmcs/modules/addons/contabo_addon/assets/
```

### **Step 3: Install Hooks**
```bash
# Copy hooks to WHMCS hooks directory
cp /path/to/whmcs/modules/addons/contabo_addon/hooks/* /path/to/whmcs/includes/hooks/
```

### **Step 4: Activate Addon Module**
1. Login to WHMCS Admin Panel
2. Go to **Setup → Addon Modules**
3. Find **"Contabo API Integration"**
4. Click **"Activate"**

### **Step 5: Database Setup**
The addon will automatically:
- ✅ Create required database tables
- ✅ Insert default cloud-init templates
- ✅ Set up billing integration tables
- ✅ Create logging infrastructure

---

## **🔐 API Configuration**

### **Step 1: Get Contabo API Credentials**

#### **1.1 Enable API Access**
1. Login to **https://my.contabo.com/**
2. Go to **Account → API**
3. Click **"Enable API Access"**
4. Accept terms and conditions

#### **1.2 Get Client Credentials**
1. In API settings, note down:
   - **Client ID** (e.g., `contabo-client-123456`)
   - **Client Secret** (e.g., `abc123def456...`)

#### **1.3 Create API User**
1. Go to **Account → API → Users**
2. Click **"Create API User"**
3. Set email: **your-email@domain.com**
4. Set password: **Strong-Password-123!**
5. Grant permissions:
   - ✅ **Compute instances** - Full access
   - ✅ **Object Storage** - Full access
   - ✅ **Private Networks** - Full access
   - ✅ **Images** - Full access
   - ✅ **VIPs** - Full access
   - ✅ **Secrets** - Full access

### **Step 2: Configure WHMCS Addon**
1. In WHMCS Admin, go to **Addon Modules → Contabo API Integration**
2. Click **"Configure"**
3. Enter the following:

```
┌─────────────────────────────────────────────────────────┐
│ 🔧 Contabo API Configuration                           │
├─────────────────────────────────────────────────────────┤
│ Client ID: contabo-client-123456                        │
│ Client Secret: abc123def456...                          │
│ API User Email: your-email@domain.com                  │
│ API User Password: Strong-Password-123!                │
│                                                         │
│ 📍 Default Settings:                                   │
│ Default Datacenter: EU-West                            │
│ Default Backup Retention: 7 days                       │
│ Auto-billing Enabled: Yes                              │
│ Debug Logging: Yes (disable in production)             │
└─────────────────────────────────────────────────────────┘
```

4. Click **"Save Changes"**

### **Step 3: Test API Connection**
1. Click **"Test API Connection"** button
2. Verify you see: ✅ **"API Connection Successful"**

---

## **🚀 Initial Setup**

### **Step 1: Import Existing Servers**
1. Go to **Contabo API → Server Management**
2. Click **"Bulk Import Untracked"**
3. Review detected servers
4. Attach servers to existing WHMCS services as needed

### **Step 2: Configure Cloud-Init Templates**
1. Go to **Contabo API → Settings → Cloud-Init Templates**
2. Review default templates
3. Add your custom templates:
   ```yaml
   #cloud-config
   package_update: true
   packages:
     - nginx
     - mysql-server
     - php8.2-fpm
   runcmd:
     - systemctl enable nginx
     - systemctl start nginx
   ```

### **Step 3: Setup Billing Integration**
1. Go to **Contabo API → Billing Integration**
2. Click **"Sync All Billing"**
3. Review pricing tiers:
   - **Backups**: €4.99-€19.99/month
   - **Additional IPs**: €2.99/month per IP
   - **Storage Overage**: €0.10/GB/month
   - **Bandwidth Overage**: €0.05/GB

### **Step 4: Configure Secret Management**
1. Go to **Contabo API → Secret Management**
2. Import existing SSH keys
3. Generate new SSH key pairs as needed

---

## **⚙️ Feature Configuration**

### **Client Area Integration**

#### **Automatic Integration** (Recommended)
The addon automatically detects Contabo services and applies modern interface.

#### **Manual Theme Integration** 
For custom themes, add to your client area template:
```php
{if $service.modern_contabo_interface}
    {$service.modern_contabo_interface}
{else}
    <!-- Default service display -->
{/if}
```

### **Backup Configuration**
```php
// Backup pricing tiers
€4.99/month  - 7 days retention
€8.99/month  - 14 days retention  
€14.99/month - 30 days retention
€19.99/month - 60 days retention
```

### **VNC Access Setup**
1. Go to **Contabo API → VNC Management**
2. Enable VNC for required servers
3. Configure connection instructions
4. Set up SSH tunneling (optional)

### **Add-on Management**
Configure automatic billing for:
- **Additional IPv4 addresses**
- **Advanced firewalling**
- **Extra storage volumes**
- **Private networking**

---

## **🧪 Testing**

### **Step 1: Basic Functionality**
1. **Create Test Server**:
   - Go to client area
   - Order new VPS service
   - Verify server creates successfully

2. **Test Server Controls**:
   - Start/stop server
   - Restart server
   - Reset password
   - Create snapshot

### **Step 2: Advanced Features**
1. **Rebuild Server**:
   - Click "Rebuild Server"
   - Select different OS
   - Verify rebuild completes

2. **Backup Management**:
   - Enable automated backups
   - Create manual backup
   - Test restore functionality

3. **VNC Access**:
   - Access remote console
   - Test VNC connection
   - Verify credentials work

### **Step 3: Billing Integration**
1. **Enable Add-ons**:
   - Add additional IP address
   - Enable automated backups
   - Verify billing entries created

2. **Check Invoicing**:
   - Run billing sync
   - Verify invoice items appear
   - Test payment processing

---

## **🔍 Troubleshooting**

### **Common Issues**

#### **1. "API Connection Failed"**
**Symptoms**: Red error message when testing API
**Solutions**:
```bash
# Check cURL
php -m | grep curl

# Test manual connection
curl -X POST "https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=YOUR_CLIENT_ID&client_secret=YOUR_CLIENT_SECRET&grant_type=client_credentials"

# Check firewall
telnet auth.contabo.com 443
```

#### **2. "Database Error"**
**Symptoms**: SQL errors in logs
**Solutions**:
```sql
-- Check database permissions
SHOW GRANTS FOR 'whmcs_user'@'localhost';

-- Verify tables exist
SHOW TABLES LIKE 'mod_contabo_%';

-- Check table structure
DESCRIBE mod_contabo_instances;
```

#### **3. "File Permission Errors"**
**Symptoms**: Cannot save files/logs
**Solutions**:
```bash
# Fix permissions
chown -R www-data:www-data /path/to/whmcs/modules/addons/contabo_addon/
chmod -R 755 /path/to/whmcs/modules/addons/contabo_addon/
```

#### **4. "Client Area Not Loading"**
**Symptoms**: Blank page or errors in client area
**Solutions**:
```php
// Check PHP logs
tail -f /var/log/php_errors.log

// Enable WHMCS debug
$whmcs_debug = true;

// Check template compatibility
{debug}
```

### **Debug Logging**
Enable detailed logging:
```php
// In configuration
'debug_logging' => true

// Log files location
/path/to/whmcs/modules/addons/contabo_addon/logs/
```

### **Performance Optimization**
```php
// Optimize API calls
- Cache API responses for 5 minutes
- Use batch operations where possible
- Implement connection pooling

// Database optimization
- Index frequently queried columns
- Regular database maintenance
- Monitor slow queries
```

---

## **⚡ Advanced Configuration**

### **Custom Pricing Rules**
Create custom pricing for add-ons:
```php
// In BillingIntegrationService.php
private function getCustomBackupPricing($config) {
    $pricing = [
        'enterprise' => [
            7 => 9.99,
            30 => 24.99,
            60 => 39.99
        ],
        'standard' => [
            7 => 4.99,
            30 => 14.99,
            60 => 19.99
        ]
    ];
    
    $tier = $config['pricing_tier'] ?? 'standard';
    return $pricing[$tier];
}
```

### **Custom Cloud-Init Templates**
Add organization-specific templates:
```yaml
#cloud-config
users:
  - name: deploy
    sudo: ALL=(ALL) NOPASSWD:ALL
    ssh-authorized-keys:
      - ssh-rsa AAAAB3NzaC1yc2E... your-key

package_update: true
packages:
  - docker.io
  - docker-compose
  - git
  - nginx

runcmd:
  - systemctl enable docker
  - systemctl start docker
  - usermod -aG docker deploy
  
write_files:
  - path: /etc/nginx/sites-available/default
    content: |
      server {
          listen 80 default_server;
          server_name _;
          location / {
              proxy_pass http://localhost:3000;
          }
      }
```

### **Multi-Datacenter Setup**
Configure multiple Contabo regions:
```php
$datacenters = [
    'EU-West' => 'European Union (Germany)',
    'EU-Central' => 'European Union (Germany)',
    'US-East' => 'United States (East)',
    'US-Central' => 'United States (Central)',
    'US-West' => 'United States (West)',
    'SIN' => 'Singapore',
    'AUS' => 'Australia'
];
```

### **Custom Billing Frequencies**
Set up alternative billing cycles:
```php
$billingFrequencies = [
    'monthly' => 1.0,    // Standard rate
    'quarterly' => 0.95, // 5% discount
    'annually' => 0.85,  // 15% discount
    'biennial' => 0.80   // 20% discount
];
```

---

## **🎯 Post-Installation Checklist**

### **✅ Security Checklist**
- [ ] API credentials secured and not in version control
- [ ] File permissions set correctly (no 777)
- [ ] Debug logging disabled in production
- [ ] HTTPS enabled for all connections
- [ ] Database user has minimal required permissions
- [ ] Regular security updates scheduled

### **✅ Performance Checklist**
- [ ] API response caching enabled
- [ ] Database queries optimized
- [ ] Log rotation configured
- [ ] Memory limits appropriate
- [ ] Connection timeouts configured

### **✅ Monitoring Checklist**
- [ ] API connection monitoring active
- [ ] Billing sync cron jobs scheduled
- [ ] Error notifications configured
- [ ] Usage tracking enabled
- [ ] Backup verification scheduled

### **✅ Documentation Checklist**
- [ ] Staff trained on new features
- [ ] Client documentation updated
- [ ] Pricing structure documented
- [ ] Support procedures updated
- [ ] Change log maintained

---

## **📞 Support & Maintenance**

### **Regular Maintenance Tasks**
- **Daily**: Check API connectivity, review error logs
- **Weekly**: Run billing sync, review usage statistics  
- **Monthly**: Update pricing, review client feedback
- **Quarterly**: Update templates, review security settings

### **Backup Recommendations**
```bash
# Daily automated backup
0 2 * * * /path/to/backup_script.sh

# Backup includes:
- WHMCS database
- Addon configuration files
- Cloud-init templates
- Custom modifications
```

### **Update Procedure**
1. **Backup current installation**
2. **Test updates in staging environment**
3. **Schedule maintenance window**
4. **Deploy updates**
5. **Verify functionality**
6. **Update documentation**

---

## **🏆 Success Indicators**

Your installation is successful when:

✅ **API Connection**: Green status in admin panel  
✅ **Server Management**: Can create, start, stop, rebuild servers  
✅ **Client Interface**: Modern VPS management interface appears  
✅ **Billing Integration**: Add-on charges appear on invoices  
✅ **Advanced Features**: VNC, backups, secrets all functional  
✅ **Performance**: Page loads < 2 seconds, API calls < 5 seconds  
✅ **Error Rate**: < 1% API failures, no critical PHP errors  

---

**🎉 Congratulations! Your WHMCS Contabo integration is now fully operational and ready to provide enterprise-grade VPS management to your clients!**

For additional support or custom modifications, refer to the included documentation or contact your implementation team.
