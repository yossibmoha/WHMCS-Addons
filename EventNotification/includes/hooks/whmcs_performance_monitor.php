<?php
// File: /includes/hooks/performance_monitor.php

require_once __DIR__ . '/notification_config.php';

class WHMCSPerformanceMonitor {
    private static $startTime;
    private static $queries = [];
    private static $memoryStart;
    
    public static function init() {
        self::$startTime = microtime(true);
        self::$memoryStart = memory_get_usage();
        
        // Hook into database queries if using Laravel/Eloquent
        if (class_exists('\Illuminate\Database\Events\QueryExecuted')) {
            \Illuminate\Support\Facades\DB::listen(function($query) {
                self::logQuery($query->sql, $query->time, $query->bindings);
            });
        }
        
        // Register shutdown function to analyze performance
        register_shutdown_function([self::class, 'analyzePerformance']);
    }
    
    public static function logQuery($sql, $time, $bindings = []) {
        self::$queries[] = [
            'sql' => $sql,
            'time' => $time,
            'bindings' => $bindings,
            'timestamp' => microtime(true)
        ];
        
        // Alert on slow queries immediately
        if ($time > 2000) { // 2 seconds
            $title = "🐌 Slow Database Query";
            $message = "Query took " . number_format($time, 2) . "ms\n" .
                      "SQL: " . substr($sql, 0, 100) . "...\n" .
                      "Page: " . $_SERVER['REQUEST_URI'] . "\n" .
                      "Time: " . date('H:i:s');
            
            sendDualNotification($title, $message, 3, 'warning,database');
        }
    }
    
    public static function analyzePerformance() {
        $totalTime = (microtime(true) - self::$startTime) * 1000;
        $memoryUsed = (memory_get_usage() - self::$memoryStart) / 1024 / 1024;
        $peakMemory = memory_get_peak_usage() / 1024 / 1024;
        
        $queryCount = count(self::$queries);
        $totalQueryTime = array_sum(array_column(self::$queries, 'time'));
        
        // Performance thresholds
        $slowPageThreshold = 3000; // 3 seconds
        $highMemoryThreshold = 64; // 64MB
        $tooManyQueriesThreshold = 50;
        
        $alerts = [];
        
        // Check page load time
        if ($totalTime > $slowPageThreshold) {
            $alerts[] = "🐌 Slow Page Load: " . number_format($totalTime, 2) . "ms";
        }
        
        // Check memory usage
        if ($peakMemory > $highMemoryThreshold) {
            $alerts[] = "🧠 High Memory Usage: " . number_format($peakMemory, 1) . "MB";
        }
        
        // Check query count
        if ($queryCount > $tooManyQueriesThreshold) {
            $alerts[] = "🗄️ Too Many Queries: " . $queryCount . " queries";
        }
        
        // Check query time vs total time
        if ($totalQueryTime > ($totalTime * 0.7)) {
            $alerts[] = "⏱️ Database Bottleneck: " . number_format(($totalQueryTime / $totalTime) * 100, 1) . "% of time";
        }
        
        // Send alerts if any issues found
        if (!empty($alerts)) {
            $title = "⚠️ WHMCS Performance Issue";
            $message = implode("\n", $alerts) . "\n\n" .
                      "Page: " . $_SERVER['REQUEST_URI'] . "\n" .
                      "Total Time: " . number_format($totalTime, 2) . "ms\n" .
                      "Memory Peak: " . number_format($peakMemory, 1) . "MB\n" .
                      "Queries: " . $queryCount . "\n" .
                      "Query Time: " . number_format($totalQueryTime, 2) . "ms";
            
            sendDualNotification($title, $message, 3, 'warning,performance');
        }
        
        // Log performance data for trends (store in file or database)
        self::logPerformanceData($totalTime, $peakMemory, $queryCount, $totalQueryTime);
    }
    
    private static function logPerformanceData($totalTime, $peakMemory, $queryCount, $totalQueryTime) {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/performance.log';
        
        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'total_time' => round($totalTime, 2),
            'memory_peak' => round($peakMemory, 2),
            'query_count' => $queryCount,
            'query_time' => round($totalQueryTime, 2),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ];
        
        file_put_contents($logFile, json_encode($data) . "\n", FILE_APPEND | LOCK_EX);
        
        // Keep log file manageable (keep last 1000 entries)
        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) { // 5MB
            $lines = file($logFile);
            $lines = array_slice($lines, -1000);
            file_put_contents($logFile, implode('', $lines));
        }
    }
    
    public static function getSlowQueries($hours = 1) {
        $slowQueries = array_filter(self::$queries, function($query) {
            return $query['time'] > 1000; // 1 second
        });
        
        return $slowQueries;
    }
    
    public static function getPerformanceReport($hours = 24) {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/performance.log';
        
        if (!file_exists($logFile)) {
            return null;
        }
        
        $lines = file($logFile);
        $cutoffTime = time() - ($hours * 3600);
        $recentData = [];
        
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data && strtotime($data['timestamp']) > $cutoffTime) {
                $recentData[] = $data;
            }
        }
        
        if (empty($recentData)) {
            return null;
        }
        
        $report = [
            'total_requests' => count($recentData),
            'avg_response_time' => array_sum(array_column($recentData, 'total_time')) / count($recentData),
            'max_response_time' => max(array_column($recentData, 'total_time')),
            'avg_memory_usage' => array_sum(array_column($recentData, 'memory_peak')) / count($recentData),
            'max_memory_usage' => max(array_column($recentData, 'memory_peak')),
            'avg_queries' => array_sum(array_column($recentData, 'query_count')) / count($recentData),
            'max_queries' => max(array_column($recentData, 'query_count')),
            'slow_pages' => array_filter($recentData, function($d) { return $d['total_time'] > 3000; })
        ];
        
        return $report;
    }
}

// Initialize performance monitoring
WHMCSPerformanceMonitor::init();

// Hook to send daily performance reports
add_hook('DailyCronJob', 1, function($vars) {
    $report = WHMCSPerformanceMonitor::getPerformanceReport(24);
    
    if ($report) {
        $title = "📊 WHMCS Performance Report";
        $message = "Last 24 hours performance:\n" .
                   "Requests: " . $report['total_requests'] . "\n" .
                   "Avg Response: " . number_format($report['avg_response_time'], 2) . "ms\n" .
                   "Max Response: " . number_format($report['max_response_time'], 2) . "ms\n" .
                   "Avg Memory: " . number_format($report['avg_memory_usage'], 1) . "MB\n" .
                   "Max Memory: " . number_format($report['max_memory_usage'], 1) . "MB\n" .
                   "Avg Queries: " . number_format($report['avg_queries'], 1) . "\n" .
                   "Slow Pages: " . count($report['slow_pages']);
        
        $priority = (count($report['slow_pages']) > 10 || $report['avg_response_time'] > 2000) ? 3 : 1;
        sendDualNotification($title, $message, $priority, 'chart_with_upwards_trend,performance');
    }
});

// Hook to monitor specific WHMCS pages
add_hook('ClientAreaPage', 1, function($vars) {
    // Monitor critical pages
    $criticalPages = ['cart', 'checkout', 'clientarea', 'login', 'register'];
    
    if (in_array($vars['templatefile'], $criticalPages)) {
        $startTime = microtime(true);
        
        // Register a shutdown function for this specific page
        register_shutdown_function(function() use ($vars, $startTime) {
            $loadTime = (microtime(true) - $startTime) * 1000;
            $memoryUsage = memory_get_peak_usage() / 1024 / 1024;
            
            // Alert on slow critical pages
            if ($loadTime > 2000) { // 2 seconds
                $title = "🐌 Slow Critical Page";
                $message = "Page: " . $vars['templatefile'] . "\n" .
                          "Load Time: " . number_format($loadTime, 2) . "ms\n" .
                          "Memory: " . number_format($memoryUsage, 1) . "MB\n" .
                          "User: " . (isset($_SESSION['uid']) ? $_SESSION['uid'] : 'Guest');
                
                sendDualNotification($title, $message, 4, 'warning,globe_with_meridians');
            }
        });
    }
});

// Monitor admin area performance
add_hook('AdminAreaPage', 1, function($vars) {
    $startTime = microtime(true);
    
    register_shutdown_function(function() use ($vars, $startTime) {
        $loadTime = (microtime(true) - $startTime) * 1000;
        
        // Alert on slow admin pages
        if ($loadTime > 3000) { // 3 seconds for admin area
            $title = "🐌 Slow Admin Page";
            $message = "Admin Page: " . $vars['filename'] . "\n" .
                      "Load Time: " . number_format($loadTime, 2) . "ms\n" .
                      "Admin: " . $vars['adminuser'];
            
            sendDualNotification($title, $message, 3, 'warning,man_technologist');
        }
    });
});
?>