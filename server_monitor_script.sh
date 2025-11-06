#!/bin/bash
# File: /root/whmcs_server_monitor.sh

# Configuration
NTFY_URL="https://your-ntfy-server.com/server-monitor"
WHMCS_PATH="/path/to/whmcs"
EMAIL="admin@yourdomain.com"

# Function to send notification
send_notification() {
    local title="$1"
    local message="$2"
    local priority="$3"
    local tags="$4"
    
    curl -s -d "{\"title\":\"$title\",\"message\":\"$message\",\"priority\":$priority,\"tags\":[\"$tags\"]}" \
         -H "Content-Type: application/json" \
         "$NTFY_URL"
    
    # Also send email
    echo -e "Subject: [Server Alert] $title\n\n$message" | sendmail "$EMAIL"
}

# Function to check disk space
check_disk_space() {
    local usage=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
    if [ "$usage" -gt 85 ]; then
        send_notification "🗄️ High Disk Usage" "Disk usage: ${usage}%" "4" "warning,disk"
    fi
}

# Function to check memory usage
check_memory() {
    local mem_usage=$(free | awk 'NR==2{printf "%.0f", $3/$2*100}')
    if [ "$mem_usage" -gt 90 ]; then
        send_notification "🧠 High Memory Usage" "Memory usage: ${mem_usage}%" "4" "warning,memory"
    fi
}

# Function to check CPU usage
check_cpu() {
    local cpu_usage=$(top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print 100 - $1}')
    local cpu_int=$(printf "%.0f" "$cpu_usage")
    if [ "$cpu_int" -gt 80 ]; then
        send_notification "⚡ High CPU Usage" "CPU usage: ${cpu_int}%" "3" "warning,cpu"
    fi
}

# Function to check load average
check_load() {
    local load=$(uptime | awk -F'load average:' '{print $2}' | awk -F',' '{print $1}' | sed 's/ //g')
    local load_int=$(printf "%.0f" "${load}")
    local cpu_cores=$(nproc)
    
    if [ "$load_int" -gt $((cpu_cores * 2)) ]; then
        send_notification "📊 High Load Average" "Load: $load (CPUs: $cpu_cores)" "4" "warning,load"
    fi
}

# Function to check services
check_services() {
    local services=("nginx" "apache2" "mysql" "mariadb" "php-fpm" "php7.4-fpm" "php8.0-fpm" "php8.1-fpm")
    
    for service in "${services[@]}"; do
        if systemctl is-active --quiet "$service" 2>/dev/null; then
            continue
        elif systemctl list-units --full -all | grep -Fq "$service.service"; then
            send_notification "⚠️ Service Down" "Service $service is not running" "5" "x,service"
        fi
    done
}

# Function to check WHMCS specific files
check_whmcs_files() {
    local critical_files=("configuration.php" "includes/api.php" "admin/index.php" "index.php")
    
    for file in "${critical_files[@]}"; do
        if [ ! -f "$WHMCS_PATH/$file" ]; then
            send_notification "📁 Missing WHMCS File" "Critical file missing: $file" "5" "x,file"
        elif [ ! -r "$WHMCS_PATH/$file" ]; then
            send_notification "🔒 WHMCS File Permission" "Cannot read file: $file" "4" "warning,file"
        fi
    done
}

# Function to check log file sizes
check_log_sizes() {
    local log_files=("/var/log/nginx/error.log" "/var/log/apache2/error.log" "/var/log/mysql/error.log" "$WHMCS_PATH/storage/logs/laravel.log")
    
    for log_file in "${log_files[@]}"; do
        if [ -f "$log_file" ]; then
            local size=$(du -m "$log_file" | cut -f1)
            if [ "$size" -gt 100 ]; then # Larger than 100MB
                send_notification "📝 Large Log File" "Log file $log_file is ${size}MB" "2" "memo,warning"
            fi
        fi
    done
}

# Function to check for recent errors in logs
check_error_logs() {
    local error_count=0
    
    # Check PHP error log
    if [ -f "/var/log/php_errors.log" ]; then
        error_count=$(grep -c "$(date '+%d-%b-%Y')" /var/log/php_errors.log 2>/dev/null || echo 0)
        if [ "$error_count" -gt 10 ]; then
            send_notification "🐛 PHP Errors" "Found $error_count PHP errors today" "3" "warning,bug"
        fi
    fi
    
    # Check nginx/apache error logs
    for log in "/var/log/nginx/error.log" "/var/log/apache2/error.log"; do
        if [ -f "$log" ]; then
            error_count=$(grep -c "$(date '+%Y/%m/%d')" "$log" 2>/dev/null || echo 0)
            if [ "$error_count" -gt 20 ]; then
                local server_type=$(basename "$(dirname "$log")")
                send_notification "🌐 Web Server Errors" "Found $error_count $server_type errors today" "3" "warning,globe"
            fi
        fi
    done
}

# Function to check database connectivity
check_database() {
    local db_check=$(mysql -e "SELECT 1;" 2>&1)
    if [ $? -ne 0 ]; then
        send_notification "🗄️ Database Connection Failed" "Cannot connect to MySQL: $db_check" "5" "x,database"
    fi
}

# Function to check SSL certificate expiry
check_ssl_cert() {
    local domain="yourdomain.com" # Replace with your domain
    local expiry_date=$(echo | openssl s_client -servername "$domain" -connect "$domain:443" 2>/dev/null | openssl x509 -noout -dates | grep notAfter | cut -d= -f2)
    local expiry_epoch=$(date -d "$expiry_date" +%s 2>/dev/null)
    local current_epoch=$(date +%s)
    local days_until_expiry=$(( (expiry_epoch - current_epoch) / 86400 ))
    
    if [ "$days_until_expiry" -lt 30 ] && [ "$days_until_expiry" -gt 0 ]; then
        send_notification "🔒 SSL Certificate Warning" "SSL expires in $days_until_expiry days" "4" "warning,lock"
    elif [ "$days_until_expiry" -le 0 ]; then
        send_notification "🔒 SSL Certificate Expired" "SSL certificate has expired!" "5" "x,lock"
    fi
}

# Function to check backup status
check_backups() {
    local backup_dir="/backups" # Adjust to your backup directory
    if [ -d "$backup_dir" ]; then
        local latest_backup=$(find "$backup_dir" -name "*.sql*" -o -name "*.tar*" -o -name "*.zip*" | head -1)
        if [ -n "$latest_backup" ]; then
            local backup_age=$(( ($(date +%s) - $(stat -c %Y "$latest_backup")) / 86400 ))
            if [ "$backup_age" -gt 2 ]; then
                send_notification "💾 Backup Warning" "Latest backup is $backup_age days old" "3" "warning,backup"
            fi
        else
            send_notification "💾 No Backups Found" "No backup files found in $backup_dir" "4" "warning,backup"
        fi
    fi
}

# Function to check WHMCS cron job
check_whmcs_cron() {
    if [ -f "$WHMCS_PATH/storage/logs/laravel.log" ]; then
        local last_cron=$(grep -i "daily cron" "$WHMCS_PATH/storage/logs/laravel.log" | tail -1 | cut -d' ' -f1-2)
        if [ -n "$last_cron" ]; then
            local cron_epoch=$(date -d "$last_cron" +%s 2>/dev/null)
            local current_epoch=$(date +%s)
            local hours_since_cron=$(( (current_epoch - cron_epoch) / 3600 ))
            
            if [ "$hours_since_cron" -gt 25 ]; then
                send_notification "⏰ WHMCS Cron Issue" "Cron hasn't run in $hours_since_cron hours" "4" "warning,clock"
            fi
        fi
    fi
}

# Function to send daily summary
send_daily_summary() {
    local uptime_info=$(uptime)
    local disk_usage=$(df -h / | awk 'NR==2 {print $5}')
    local memory_usage=$(free | awk 'NR==2{printf "%.0f%%", $3/$2*100}')
    local load_avg=$(uptime | awk -F'load average:' '{print $2}')
    
    local summary="📊 Daily Server Summary
Uptime: $uptime_info
Disk Usage: $disk_usage
Memory Usage: $memory_usage
Load Average:$load_avg
Time: $(date)"
    
    send_notification "📊 Daily Server Summary" "$summary" "1" "chart,server"
}

# Main execution
main() {
    echo "Starting WHMCS server monitoring at $(date)"
    
    # Run all checks
    check_disk_space
    check_memory
    check_cpu
    check_load
    check_services
    check_whmcs_files
    check_log_sizes
    check_error_logs
    check_database
    check_ssl_cert
    check_backups
    check_whmcs_cron
    
    # Send daily summary (only run once per day)
    if [ "$(date +%H:%M)" = "09:00" ]; then
        send_daily_summary
    fi
    
    echo "Monitoring completed at $(date)"
}

# Check if script is run with specific function
case "${1:-main}" in
    disk)
        check_disk_space
        ;;
    memory)
        check_memory
        ;;
    cpu)
        check_cpu
        ;;
    services)
        check_services
        ;;
    whmcs)
        check_whmcs_files
        check_whmcs_cron
        ;;
    ssl)
        check_ssl_cert
        ;;
    summary)
        send_daily_summary
        ;;
    *)
        main
        ;;
esac