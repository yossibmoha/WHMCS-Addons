<?php
/**
 * Log Helper
 * 
 * Manages logging and audit trails
 */

namespace ContaboAddon\Helpers;

use WHMCS\Database\Capsule;
use Exception;

class LogHelper
{
    private $logToDatabase = true;
    private $logToFile = true;
    private $logPath;

    public function __construct()
    {
        $this->logPath = dirname(__DIR__, 2) . '/logs/';
        
        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * Log an event
     */
    public function log($action, $data = [], $level = 'info')
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'level' => $level,
            'data' => $data,
            'user_id' => $this->getCurrentUserId(),
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];

        if ($this->logToDatabase) {
            $this->logToDatabase($logEntry);
        }

        if ($this->logToFile) {
            $this->logToFile($logEntry);
        }
    }

    /**
     * Log to database
     */
    private function logToDatabase($logEntry)
    {
        try {
            Capsule::table('mod_contabo_api_logs')->insert([
                'action' => $logEntry['action'],
                'method' => 'LOG',
                'endpoint' => '',
                'request_data' => json_encode($logEntry['data']),
                'response_data' => null,
                'response_code' => null,
                'request_id' => $this->generateRequestId(),
                'user_id' => $logEntry['user_id'],
                'created_at' => $logEntry['timestamp']
            ]);
        } catch (Exception $e) {
            // Fail silently to avoid infinite loops
            error_log("Failed to log to database: " . $e->getMessage());
        }
    }

    /**
     * Log to file
     */
    private function logToFile($logEntry)
    {
        try {
            $logFile = $this->logPath . 'contabo_' . date('Y-m-d') . '.log';
            $logLine = sprintf(
                "[%s] %s.%s: %s %s\n",
                $logEntry['timestamp'],
                strtoupper($logEntry['level']),
                $logEntry['action'],
                json_encode($logEntry['data']),
                "User: " . ($logEntry['user_id'] ?: 'N/A') . " IP: " . $logEntry['ip_address']
            );

            file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("Failed to log to file: " . $e->getMessage());
        }
    }

    /**
     * Get recent logs
     */
    public function getRecentLogs($limit = 100, $level = null, $action = null)
    {
        try {
            $query = Capsule::table('mod_contabo_api_logs')
                ->orderBy('created_at', 'desc')
                ->limit($limit);

            if ($action) {
                $query->where('action', 'like', "%{$action}%");
            }

            return $query->get();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get error logs
     */
    public function getErrorLogs($limit = 50)
    {
        try {
            return Capsule::table('mod_contabo_api_logs')
                ->where(function($query) {
                    $query->where('response_code', '>=', 400)
                          ->orWhere('action', 'like', '%_failed');
                })
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get statistics
     */
    public function getLogStats($days = 30)
    {
        try {
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            $stats = [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'by_action' => [],
                'by_day' => [],
                'error_rate' => 0,
                'avg_response_time' => 0
            ];

            // Get total counts
            $totalLogs = Capsule::table('mod_contabo_api_logs')
                ->where('created_at', '>=', $startDate)
                ->count();

            $successfulLogs = Capsule::table('mod_contabo_api_logs')
                ->where('created_at', '>=', $startDate)
                ->where(function($query) {
                    $query->whereBetween('response_code', [200, 299])
                          ->orWhere('response_code', null);
                })
                ->count();

            $failedLogs = $totalLogs - $successfulLogs;

            $stats['total_requests'] = $totalLogs;
            $stats['successful_requests'] = $successfulLogs;
            $stats['failed_requests'] = $failedLogs;
            $stats['error_rate'] = $totalLogs > 0 ? round(($failedLogs / $totalLogs) * 100, 2) : 0;

            // Get by action
            $actionStats = Capsule::table('mod_contabo_api_logs')
                ->selectRaw('action, COUNT(*) as count')
                ->where('created_at', '>=', $startDate)
                ->groupBy('action')
                ->orderBy('count', 'desc')
                ->get();

            foreach ($actionStats as $stat) {
                $stats['by_action'][$stat->action] = $stat->count;
            }

            // Get by day
            $dailyStats = Capsule::table('mod_contabo_api_logs')
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->where('created_at', '>=', $startDate)
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            foreach ($dailyStats as $stat) {
                $stats['by_day'][$stat->date] = $stat->count;
            }

            return $stats;

        } catch (Exception $e) {
            return [
                'error' => 'Failed to generate statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs($retentionDays = 90)
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
            
            $deleted = Capsule::table('mod_contabo_api_logs')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            // Also clean log files
            $this->cleanLogFiles($retentionDays);

            return $deleted;

        } catch (Exception $e) {
            error_log("Failed to clean old logs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clean log files
     */
    private function cleanLogFiles($retentionDays)
    {
        try {
            $files = glob($this->logPath . 'contabo_*.log');
            $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);

            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to clean log files: " . $e->getMessage());
        }
    }

    /**
     * Export logs
     */
    public function exportLogs($startDate, $endDate, $format = 'json')
    {
        try {
            $logs = Capsule::table('mod_contabo_api_logs')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'asc')
                ->get();

            if ($format === 'csv') {
                return $this->exportToCSV($logs);
            }

            return $logs->toArray();

        } catch (Exception $e) {
            throw new Exception("Failed to export logs: " . $e->getMessage());
        }
    }

    /**
     * Export to CSV
     */
    private function exportToCSV($logs)
    {
        $csv = "Timestamp,Action,Method,Endpoint,Response Code,User ID,Request ID\n";
        
        foreach ($logs as $log) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $log->created_at,
                $log->action,
                $log->method,
                $log->endpoint,
                $log->response_code ?: 'N/A',
                $log->user_id ?: 'N/A',
                $log->request_id ?: 'N/A'
            );
        }

        return $csv;
    }

    /**
     * Search logs
     */
    public function searchLogs($query, $limit = 100)
    {
        try {
            return Capsule::table('mod_contabo_api_logs')
                ->where('action', 'like', "%{$query}%")
                ->orWhere('endpoint', 'like', "%{$query}%")
                ->orWhere('request_data', 'like', "%{$query}%")
                ->orWhere('response_data', 'like', "%{$query}%")
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get current user ID
     */
    private function getCurrentUserId()
    {
        // Check for admin session
        if (isset($_SESSION['adminid']) && !empty($_SESSION['adminid'])) {
            return $_SESSION['adminid'];
        }

        // Check for client session
        if (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
            return $_SESSION['uid'];
        }

        return null;
    }

    /**
     * Get client IP address
     */
    private function getClientIP()
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // CloudFlare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Generate request ID
     */
    private function generateRequestId()
    {
        return substr(md5(uniqid(mt_rand(), true)), 0, 16);
    }

    /**
     * Monitor system performance
     */
    public function logPerformance($operation, $duration, $memoryUsage = null)
    {
        $performanceData = [
            'operation' => $operation,
            'duration_ms' => $duration,
            'memory_usage' => $memoryUsage ?: memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ];

        $this->log('performance_metric', $performanceData, 'info');
    }

    /**
     * Log security event
     */
    public function logSecurity($event, $severity = 'medium', $details = [])
    {
        $securityData = [
            'event' => $event,
            'severity' => $severity,
            'details' => $details,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'session_id' => session_id()
        ];

        $this->log('security_event', $securityData, 'warning');

        // If high severity, also log to system error log
        if ($severity === 'high' || $severity === 'critical') {
            error_log("SECURITY ALERT: {$event} - " . json_encode($details));
        }
    }

    /**
     * Create audit trail entry
     */
    public function audit($action, $resourceType, $resourceId, $changes = [])
    {
        $auditData = [
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'changes' => $changes,
            'user_id' => $this->getCurrentUserId(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $this->log('audit_trail', $auditData, 'info');
    }
}
