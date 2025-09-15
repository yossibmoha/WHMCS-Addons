# 👤 **VPS Server Management - Complete User Guide**

## **🎯 Quick Start**

Welcome to your new VPS Server Management interface! This guide will help you master all the powerful features available to manage your servers.

---

## **🖥️ Dashboard Overview**

When you view your VPS service, you'll see a modern dashboard with:

```
┌─────────────────────────────────────────────────────────────────┐
│ 🖥️  CloudCore Starter CloudPanel +N8N         ✅ Running       │
├─────────────────────────────────────────────────────────────────┤
│ 📊 [4 CPU] [8GB RAM] [75GB SSD] [EU-West]                     │
│ 🌐 IPv4: 154.68.98.151  IPv6: 2a01:db8::1                    │
│                                                                 │
│ 🎛️  Server Controls:                                           │
│ [🔄 Restart] [⏹️ Stop] [🔑 Reset Pass] [🖥️ Console] [📸 Snapshot]│
│ [🔨 Rebuild Server]                                            │
└─────────────────────────────────────────────────────────────────┘
```

### **Status Indicators**
- **✅ Running** - Server is online and accessible
- **⏹️ Stopped** - Server is offline
- **🔄 Provisioning** - Server is being set up or rebuilt
- **⚠️ Error** - Issue requires attention

---

## **⚡ Basic Server Controls**

### **🔄 Restart Server**
**When to use**: Server is running slowly or has issues
1. Click **"Restart"** button
2. Confirm the action
3. Wait 2-3 minutes for restart to complete
4. Your server will be accessible again

### **⏹️ Stop/Start Server**
**When to use**: Maintenance, save costs, or troubleshooting
1. **To Stop**: Click **"Stop"** → Confirm
2. **To Start**: Click **"Start"** → Wait for startup
3. **Note**: Stopped servers don't incur compute charges

### **🔑 Reset Password**
**When to use**: Lost root password or security concerns
1. Click **"Reset Password"**
2. New password will be emailed to you
3. Use new password for SSH/console access
4. **Important**: Change default password after first login

### **🖥️ Remote Console**
**When to use**: SSH not working or network issues
1. Click **"Remote Console"**
2. VNC window opens in new tab
3. Login with your root credentials
4. Full desktop access available

### **📸 Create Snapshot**
**When to use**: Before major changes or as backup
1. Click **"Create Snapshot"**
2. Enter descriptive name
3. Snapshot created in 5-10 minutes
4. Restore anytime from snapshots section

---

## **🔨 Server Rebuild**

**⚠️ WARNING**: This completely wipes your server and reinstalls the operating system. **ALL DATA WILL BE LOST**.

### **When to Rebuild**
- Starting fresh with new project
- Server is corrupted or compromised
- Need different operating system
- Want to try different application stack

### **How to Rebuild**
1. **Backup Important Data First!**
2. Click **"Rebuild Server"**
3. Read warning carefully
4. Choose Operating System:

```
📋 Ubuntu
  ● Ubuntu 22.04 LTS (Recommended)
  ○ Ubuntu 20.04 LTS
  
📋 Debian
  ○ Debian 12
  
📋 CentOS
  ○ CentOS 9
  
📋 Windows
  ○ Windows Server 2022
```

5. Select options:
   - ✅ **Keep existing SSH keys** (recommended)
   - ✅ **Apply automatic server setup** (installs common software)

6. Click **"Rebuild Server"**
7. Wait 10-15 minutes for completion

### **After Rebuild**
- Server gets new IP address (sometimes)
- Root password reset (check email)
- All previous data and settings are gone
- SSH keys preserved if option was selected

---

## **💾 Backup Management**

### **Enable Automated Backups**
1. Click **"Manage Backups"**
2. Choose retention period:
   - **7 days** - €4.99/month
   - **14 days** - €8.99/month
   - **30 days** - €14.99/month
   - **60 days** - €19.99/month

3. Click **"Enable Backups"**
4. First backup starts within 24 hours

### **Manual Backup**
1. In backup section, click **"Create Backup Now"**
2. Enter backup name
3. Backup completes in 30-60 minutes
4. Available for restore immediately

### **Restore from Backup**
1. Go to **"View Backups"**
2. Find desired backup
3. Click **"Restore"** 
4. **Warning**: Current data will be overwritten
5. Restoration takes 30-45 minutes

### **Backup Schedule**
- **Daily backups** at 3 AM server time
- **Automatic cleanup** after retention period
- **Email notifications** for backup success/failure
- **Incremental backups** for faster processing

---

## **🧩 Server Add-ons**

Enhance your server with additional features:

### **🌐 Additional IP Addresses**
**Cost**: €2.99/month per IP
**Benefits**:
- Host multiple websites
- SSL certificates for different domains
- Load balancing
- Separate services

**How to Add**:
1. Click **"Manage Add-ons"**
2. Select **"Additional IPv4"**
3. Choose quantity needed
4. IPs assigned within 1 hour

### **🛡️ Advanced Firewall**
**Cost**: €3.99-€15.99/month
**Features**:
- Custom firewall rules
- DDoS protection
- Port blocking/allowing
- Security monitoring

### **💽 Extra Storage**
**Cost**: €4.99-€34.99/month
**Options**:
- 100GB to 1TB additional space
- High-performance SSD
- Automatic mounting
- Instant provisioning

### **🔐 Private Networking**
**Cost**: €1.99/month
**Benefits**:
- Secure server-to-server communication
- Private IP addresses
- Network isolation
- VPN-style connectivity

---

## **🚀 One-Click Applications**

Deploy popular applications instantly:

### **Available Applications**
- **WordPress** - Blog and CMS platform
- **Docker** - Container platform
- **LEMP Stack** - Linux + Nginx + MySQL + PHP
- **Node.js** - JavaScript runtime
- **CloudPanel + n8n** - Control panel with automation

### **How to Deploy**
1. Click **"Browse Apps"**
2. Select desired application
3. Configure basic settings:
   - Admin username/password
   - Database settings
   - SSL certificate (optional)
4. Click **"Deploy"**
5. Access URLs provided after installation

### **Post-Deployment**
- **WordPress**: Visit `http://your-ip/` to complete setup
- **CloudPanel**: Login at `https://your-ip:8443/`
- **Docker**: SSH access to manage containers
- **LEMP**: Upload files to `/var/www/html/`

---

## **📸 Snapshot Management**

### **Create Snapshots**
1. Click **"View Snapshots"**
2. Click **"Create New Snapshot"**
3. Enter descriptive name (e.g., "Before WordPress Update")
4. Snapshot creates in 10-15 minutes

### **Restore from Snapshot**
1. Select snapshot to restore
2. Click **"Restore"**
3. **Warning**: Current state will be lost
4. Restoration takes 15-20 minutes

### **Snapshot Best Practices**
- Create snapshot before major changes
- Use descriptive names with dates
- Keep important snapshots long-term
- Delete old snapshots to save space

---

## **🌐 Network Settings**

### **IP Address Management**
- **Primary IP**: Assigned automatically
- **Additional IPs**: Purchase as add-ons
- **IPv6**: Available on all servers
- **Reverse DNS**: Contact support to configure

### **Private Networking**
1. Purchase private networking add-on
2. Create private network in control panel
3. Connect multiple servers securely
4. Use private IPs for database connections

### **Firewall Configuration**
- **Basic firewall**: Included with server
- **Advanced rules**: Available as add-on
- **Common ports open**: 22 (SSH), 80 (HTTP), 443 (HTTPS)
- **Custom rules**: Configure as needed

---

## **🔍 Monitoring & Alerts**

### **Performance Metrics**
View real-time data:
- **CPU Usage**: Current and historical
- **Memory Usage**: RAM utilization
- **Disk Space**: Available storage
- **Network Traffic**: Bandwidth usage

### **Usage Alerts**
Set up notifications for:
- High CPU usage (>80%)
- Low disk space (<10%)
- Excessive bandwidth usage
- Server downtime

### **Resource Limits**
- **Storage Overage**: €0.10/GB/month over allocated
- **Bandwidth Overage**: €0.05/GB over 1TB/month
- **Automatic scaling**: Available for enterprise plans

---

## **🆘 Troubleshooting**

### **Common Issues**

#### **"Can't Access My Server"**
1. Check server status (should be "Running")
2. Verify IP address hasn't changed
3. Try remote console access
4. Check firewall settings
5. Reset root password if needed

#### **"Website Not Loading"**
1. Verify web server is running: `systemctl status nginx`
2. Check DNS settings
3. Verify firewall allows port 80/443
4. Check error logs: `/var/log/nginx/error.log`

#### **"Server Running Slowly"**
1. Check CPU/memory usage in dashboard
2. Restart server if usage is high
3. Consider upgrading server size
4. Review running processes: `top`

#### **"Backup Failed"**
1. Check available disk space
2. Verify backup service is active
3. Review backup logs
4. Contact support if persistent

### **Getting Help**
1. **Check logs**: Available in admin dashboard
2. **Remote console**: Access server directly
3. **Support ticket**: Contact hosting provider
4. **Community forum**: Connect with other users

---

## **💡 Best Practices**

### **Security**
- Change default passwords immediately
- Keep software updated
- Use SSH keys instead of passwords
- Enable automatic security updates
- Configure firewall rules
- Regular backup verification

### **Performance**
- Monitor resource usage regularly
- Optimize applications for your server size
- Use CDN for static content
- Enable caching where possible
- Regular database maintenance

### **Cost Management**
- Stop servers when not needed
- Monitor overage usage
- Review add-ons monthly
- Use snapshots instead of keeping multiple servers
- Right-size your server for actual needs

### **Disaster Recovery**
- **Daily backups** enabled
- **Test restores** periodically
- **Document configurations**
- **Keep local copies** of important data
- **Monitor backup success**

---

## **📱 Mobile Access**

The VPS management interface is fully responsive:
- **All controls available** on mobile devices
- **Remote console** works on tablets
- **Touch-friendly interface** for easy management
- **Quick actions** accessible from phone

---

## **🎓 Advanced Tips**

### **SSH Key Management**
```bash
# Generate SSH key pair
ssh-keygen -t rsa -b 4096 -C "your-email@domain.com"

# Copy public key to server
ssh-copy-id root@your-server-ip
```

### **Custom Cloud-Init**
Request custom initialization scripts:
- Install specific software
- Configure users and permissions
- Set up automatic deployments
- Configure monitoring agents

### **API Integration**
For developers, full API access available:
- Server management automation
- Custom integrations
- Monitoring dashboard creation
- Bulk operations

---

## **📞 Support**

### **Self-Service Resources**
- **Knowledge Base**: Comprehensive articles
- **Video Tutorials**: Step-by-step guides
- **Community Forum**: User discussions
- **Status Page**: Service status updates

### **Contact Support**
- **Support Ticket**: Detailed technical issues
- **Live Chat**: Quick questions (business hours)
- **Phone Support**: Urgent issues (enterprise plans)
- **Email**: Non-urgent inquiries

### **Response Times**
- **Standard**: 4-8 hours
- **Priority**: 1-2 hours
- **Emergency**: 30 minutes (enterprise)

---

**🎉 You're now ready to make the most of your VPS Server! Start with basic controls, then explore advanced features as you become more comfortable with the interface.**

**Need help? Our support team is here to assist you every step of the way!**
