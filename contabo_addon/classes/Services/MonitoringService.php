<?php
/**
 * Server Monitoring Service
 * 
 * Handles server monitoring, performance metrics, alerts, and uptime tracking
 */

namespace ContaboAddon\Services;

use Exception;

class MonitoringService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Collect server metrics for all instances
     */
    public function collectAllServerMetrics()
    {
        try {
            $instances = \WHMCS\Database\Capsule::table('mod_contabo_instances')->get();
            $results = ['processed' => 0, 'success' => 0, 'failed' => 0];

            foreach ($instances as $instance) {
                $results['processed']++;
                
                try {
                    $this->collectServerMetrics($instance->contabo_instance_id);
                    $results['success']++;
                } catch (Exception $e) {
                    $results['failed']++;
                    $this->logHelper->log('metric_collection_failed', [
                        'instance_id' => $instance->contabo_instance_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('bulk_metric_collection_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Collect metrics for a specific server
     */
    public function collectServerMetrics($instanceId)
    {
        try {
            // Get current server status from Contabo API
            $serverInfo = $this->apiClient->makeRequest('GET', "/v1/compute/instances/{$instanceId}");
            $server = $serverInfo['data'][0] ?? null;

            if (!$server) {
                throw new Exception('Server not found');
            }

            // Collect various metrics
            $metrics = [
                'instance_id' => $instanceId,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => $server['status'] ?? 'unknown',
                'cpu_usage' => $this->getCPUUsage($instanceId),
                'memory_usage' => $this->getMemoryUsage($instanceId, $server),
                'disk_usage' => $this->getDiskUsage($instanceId, $server),
                'network_usage' => $this->getNetworkUsage($instanceId),
                'uptime' => $this->getUptime($instanceId),
                'load_average' => $this->getLoadAverage($instanceId),
                'response_time' => $this->getResponseTime($instanceId),
                'is_online' => $server['status'] === 'running'
            ];

            // Store metrics in database
            $this->storeMetrics($metrics);

            // Check for alerts
            $this->checkAlerts($instanceId, $metrics);

            // Update server status
            $this->updateServerStatus($instanceId, $metrics['status'], $metrics['is_online']);

            return $metrics;

        } catch (Exception $e) {
            $this->logHelper->log('metric_collection_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get server performance history
     */
    public function getServerMetricsHistory($instanceId, $timeRange = '24h')
    {
        try {
            $timeCondition = $this->getTimeCondition($timeRange);
            
            $metrics = \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
                ->where('instance_id', $instanceId)
                ->where('timestamp', '>=', $timeCondition)
                ->orderBy('timestamp', 'asc')
                ->get();

            if ($metrics->isEmpty()) {
                return [
                    'instance_id' => $instanceId,
                    'time_range' => $timeRange,
                    'data_points' => 0,
                    'metrics' => [],
                    'summary' => []
                ];
            }

            // Calculate summary statistics
            $summary = $this->calculateMetricsSummary($metrics);

            return [
                'instance_id' => $instanceId,
                'time_range' => $timeRange,
                'data_points' => $metrics->count(),
                'metrics' => $metrics->toArray(),
                'summary' => $summary,
                'chart_data' => $this->formatChartData($metrics)
            ];

        } catch (Exception $e) {
            $this->logHelper->log('metrics_history_failed', [
                'instance_id' => $instanceId,
                'time_range' => $timeRange,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create or update monitoring alert
     */
    public function createMonitoringAlert($data)
    {
        try {
            $alert = [
                'instance_id' => $data['instance_id'],
                'alert_type' => $data['alert_type'], // cpu, memory, disk, uptime, response_time
                'metric_name' => $data['metric_name'],
                'condition' => $data['condition'], // greater_than, less_than, equals
                'threshold_value' => $data['threshold_value'],
                'duration_minutes' => $data['duration_minutes'] ?? 5,
                'notification_email' => $data['notification_email'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $data['created_by'] ?? 'user'
            ];

            // Validate alert configuration
            $this->validateAlertConfiguration($alert);

            $alertId = \WHMCS\Database\Capsule::table('mod_contabo_monitoring_alerts')
                ->insertGetId($alert);

            $this->logHelper->log('monitoring_alert_created', [
                'alert_id' => $alertId,
                'instance_id' => $alert['instance_id'],
                'alert_type' => $alert['alert_type']
            ]);

            return [
                'success' => true,
                'alert_id' => $alertId,
                'message' => 'Monitoring alert created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('alert_creation_failed', [
                'alert_data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get monitoring alerts for instance
     */
    public function getMonitoringAlerts($instanceId = null)
    {
        try {
            $query = \WHMCS\Database\Capsule::table('mod_contabo_monitoring_alerts')
                ->leftJoin('mod_contabo_instances', 
                    'mod_contabo_monitoring_alerts.instance_id', '=', 
                    'mod_contabo_instances.contabo_instance_id'
                );

            if ($instanceId) {
                $query->where('mod_contabo_monitoring_alerts.instance_id', $instanceId);
            }

            $alerts = $query
                ->select(
                    'mod_contabo_monitoring_alerts.*',
                    'mod_contabo_instances.name as instance_name'
                )
                ->orderBy('mod_contabo_monitoring_alerts.created_at', 'desc')
                ->get();

            return $alerts->map(function($alert) {
                return [
                    'id' => $alert->id,
                    'instance_id' => $alert->instance_id,
                    'instance_name' => $alert->instance_name ?: $alert->instance_id,
                    'alert_type' => $alert->alert_type,
                    'metric_name' => $alert->metric_name,
                    'condition' => $alert->condition,
                    'threshold_value' => $alert->threshold_value,
                    'duration_minutes' => $alert->duration_minutes,
                    'notification_email' => $alert->notification_email,
                    'is_active' => (bool)$alert->is_active,
                    'last_triggered' => $alert->last_triggered,
                    'trigger_count' => $alert->trigger_count ?? 0,
                    'created_at' => $alert->created_at
                ];
            })->toArray();

        } catch (Exception $e) {
            $this->logHelper->log('alerts_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get monitoring dashboard data
     */
    public function getMonitoringDashboard()
    {
        try {
            $dashboard = [
                'overview' => $this->getMonitoringOverview(),
                'server_status' => $this->getServerStatusSummary(),
                'recent_alerts' => $this->getRecentAlerts(),
                'performance_summary' => $this->getPerformanceSummary(),
                'uptime_stats' => $this->getUptimeStats()
            ];

            return $dashboard;

        } catch (Exception $e) {
            $this->logHelper->log('dashboard_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Test server connectivity and response time
     */
    public function testServerConnectivity($instanceId)
    {
        try {
            // Get server IP
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if (!$instance) {
                throw new Exception('Instance not found');
            }

            $networkConfig = json_decode($instance->network_config, true);
            $ip = $networkConfig['ipv4'] ?? null;

            if (!$ip) {
                throw new Exception('Server IP not found');
            }

            // Test multiple protocols
            $tests = [
                'ping' => $this->testPing($ip),
                'http' => $this->testHTTP($ip, 80),
                'https' => $this->testHTTP($ip, 443),
                'ssh' => $this->testPort($ip, 22)
            ];

            // Calculate overall health score
            $healthScore = $this->calculateHealthScore($tests);

            $result = [
                'instance_id' => $instanceId,
                'ip_address' => $ip,
                'tests' => $tests,
                'health_score' => $healthScore,
                'overall_status' => $healthScore >= 75 ? 'healthy' : ($healthScore >= 50 ? 'warning' : 'critical'),
                'tested_at' => date('Y-m-d H:i:s')
            ];

            // Store connectivity test result
            \WHMCS\Database\Capsule::table('mod_contabo_connectivity_tests')
                ->insert([
                    'instance_id' => $instanceId,
                    'ip_address' => $ip,
                    'test_results' => json_encode($tests),
                    'health_score' => $healthScore,
                    'overall_status' => $result['overall_status'],
                    'created_at' => $result['tested_at']
                ]);

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('connectivity_test_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get CPU usage (simulated)
     */
    private function getCPUUsage($instanceId)
    {
        // In real implementation, this would connect to the server and get actual CPU usage
        // For now, we'll simulate realistic data
        $baseUsage = 15; // Base CPU usage
        $variation = rand(-10, 30); // Random variation
        return max(5, min(95, $baseUsage + $variation)); // Keep between 5-95%
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage($instanceId, $serverInfo)
    {
        $totalRAM = $serverInfo['ramMb'] ?? 1024;
        $usedRAM = $totalRAM * (rand(30, 85) / 100); // Simulate 30-85% usage
        
        return [
            'total_mb' => $totalRAM,
            'used_mb' => round($usedRAM),
            'free_mb' => $totalRAM - round($usedRAM),
            'usage_percent' => round(($usedRAM / $totalRAM) * 100, 2)
        ];
    }

    /**
     * Get disk usage
     */
    private function getDiskUsage($instanceId, $serverInfo)
    {
        $totalDisk = $serverInfo['diskMb'] ?? 25600; // Default 25GB
        $usedDisk = $totalDisk * (rand(20, 80) / 100); // Simulate 20-80% usage
        
        return [
            'total_mb' => $totalDisk,
            'used_mb' => round($usedDisk),
            'free_mb' => $totalDisk - round($usedDisk),
            'usage_percent' => round(($usedDisk / $totalDisk) * 100, 2)
        ];
    }

    /**
     * Get network usage
     */
    private function getNetworkUsage($instanceId)
    {
        return [
            'bytes_in' => rand(1000000, 50000000), // 1MB to 50MB
            'bytes_out' => rand(500000, 25000000), // 0.5MB to 25MB
            'packets_in' => rand(1000, 10000),
            'packets_out' => rand(800, 8000)
        ];
    }

    /**
     * Get server uptime (simulated)
     */
    private function getUptime($instanceId)
    {
        // Simulate uptime in seconds (1-30 days)
        return rand(86400, 2592000);
    }

    /**
     * Get load average (simulated)
     */
    private function getLoadAverage($instanceId)
    {
        return [
            '1min' => round(rand(10, 200) / 100, 2), // 0.1 to 2.0
            '5min' => round(rand(15, 180) / 100, 2),
            '15min' => round(rand(20, 160) / 100, 2)
        ];
    }

    /**
     * Get response time
     */
    private function getResponseTime($instanceId)
    {
        // Get server IP and test response time
        try {
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if ($instance) {
                $networkConfig = json_decode($instance->network_config, true);
                $ip = $networkConfig['ipv4'] ?? null;

                if ($ip) {
                    $startTime = microtime(true);
                    $connection = @fsockopen($ip, 80, $errno, $errstr, 5);
                    $endTime = microtime(true);

                    if ($connection) {
                        fclose($connection);
                        return round(($endTime - $startTime) * 1000, 2); // Convert to milliseconds
                    }
                }
            }

            return null; // Unable to test
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Store metrics in database
     */
    private function storeMetrics($metrics)
    {
        $data = [
            'instance_id' => $metrics['instance_id'],
            'timestamp' => $metrics['timestamp'],
            'status' => $metrics['status'],
            'cpu_usage_percent' => $metrics['cpu_usage'],
            'memory_total_mb' => $metrics['memory_usage']['total_mb'] ?? 0,
            'memory_used_mb' => $metrics['memory_usage']['used_mb'] ?? 0,
            'memory_usage_percent' => $metrics['memory_usage']['usage_percent'] ?? 0,
            'disk_total_mb' => $metrics['disk_usage']['total_mb'] ?? 0,
            'disk_used_mb' => $metrics['disk_usage']['used_mb'] ?? 0,
            'disk_usage_percent' => $metrics['disk_usage']['usage_percent'] ?? 0,
            'network_bytes_in' => $metrics['network_usage']['bytes_in'] ?? 0,
            'network_bytes_out' => $metrics['network_usage']['bytes_out'] ?? 0,
            'uptime_seconds' => $metrics['uptime'],
            'load_average_1min' => $metrics['load_average']['1min'] ?? 0,
            'response_time_ms' => $metrics['response_time'],
            'is_online' => $metrics['is_online'] ? 1 : 0
        ];

        \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')->insert($data);

        // Clean up old metrics (keep only last 30 days)
        \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
            ->where('timestamp', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->delete();
    }

    /**
     * Check for alert conditions
     */
    private function checkAlerts($instanceId, $metrics)
    {
        try {
            $alerts = \WHMCS\Database\Capsule::table('mod_contabo_monitoring_alerts')
                ->where('instance_id', $instanceId)
                ->where('is_active', 1)
                ->get();

            foreach ($alerts as $alert) {
                if ($this->shouldTriggerAlert($alert, $metrics)) {
                    $this->triggerAlert($alert, $metrics);
                }
            }
        } catch (Exception $e) {
            $this->logHelper->log('alert_check_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if alert should be triggered
     */
    private function shouldTriggerAlert($alert, $metrics)
    {
        $value = null;

        // Get the metric value based on alert type
        switch ($alert->alert_type) {
            case 'cpu':
                $value = $metrics['cpu_usage'];
                break;
            case 'memory':
                $value = $metrics['memory_usage']['usage_percent'];
                break;
            case 'disk':
                $value = $metrics['disk_usage']['usage_percent'];
                break;
            case 'response_time':
                $value = $metrics['response_time'];
                break;
            case 'uptime':
                $value = $metrics['uptime'] / 3600; // Convert to hours
                break;
            default:
                return false;
        }

        if ($value === null) {
            return false;
        }

        // Check condition
        switch ($alert->condition) {
            case 'greater_than':
                return $value > $alert->threshold_value;
            case 'less_than':
                return $value < $alert->threshold_value;
            case 'equals':
                return abs($value - $alert->threshold_value) < 0.1;
            default:
                return false;
        }
    }

    /**
     * Trigger alert notification
     */
    private function triggerAlert($alert, $metrics)
    {
        try {
            // Update alert trigger count and timestamp
            \WHMCS\Database\Capsule::table('mod_contabo_monitoring_alerts')
                ->where('id', $alert->id)
                ->update([
                    'last_triggered' => date('Y-m-d H:i:s'),
                    'trigger_count' => $alert->trigger_count + 1
                ]);

            // Send notification if email is configured
            if ($alert->notification_email) {
                $this->sendAlertNotification($alert, $metrics);
            }

            // Log alert trigger
            \WHMCS\Database\Capsule::table('mod_contabo_alert_history')->insert([
                'alert_id' => $alert->id,
                'instance_id' => $alert->instance_id,
                'alert_type' => $alert->alert_type,
                'metric_value' => $this->getMetricValue($alert, $metrics),
                'threshold_value' => $alert->threshold_value,
                'message' => $this->generateAlertMessage($alert, $metrics),
                'triggered_at' => date('Y-m-d H:i:s')
            ]);

            $this->logHelper->log('alert_triggered', [
                'alert_id' => $alert->id,
                'instance_id' => $alert->instance_id,
                'alert_type' => $alert->alert_type
            ]);

        } catch (Exception $e) {
            $this->logHelper->log('alert_trigger_failed', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get monitoring overview
     */
    private function getMonitoringOverview()
    {
        $totalServers = \WHMCS\Database\Capsule::table('mod_contabo_instances')->count();
        
        $onlineServers = \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
            ->where('timestamp', '>=', date('Y-m-d H:i:s', strtotime('-15 minutes')))
            ->where('is_online', 1)
            ->distinct('instance_id')
            ->count();

        $activeAlerts = \WHMCS\Database\Capsule::table('mod_contabo_monitoring_alerts')
            ->where('is_active', 1)
            ->count();

        $criticalAlerts = \WHMCS\Database\Capsule::table('mod_contabo_alert_history')
            ->where('triggered_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ->count();

        return [
            'total_servers' => $totalServers,
            'online_servers' => $onlineServers,
            'offline_servers' => $totalServers - $onlineServers,
            'uptime_percentage' => $totalServers > 0 ? round(($onlineServers / $totalServers) * 100, 2) : 0,
            'active_alerts' => $activeAlerts,
            'critical_alerts' => $criticalAlerts
        ];
    }

    /**
     * Get time condition for queries
     */
    private function getTimeCondition($timeRange)
    {
        switch ($timeRange) {
            case '1h':
                return date('Y-m-d H:i:s', strtotime('-1 hour'));
            case '6h':
                return date('Y-m-d H:i:s', strtotime('-6 hours'));
            case '24h':
                return date('Y-m-d H:i:s', strtotime('-24 hours'));
            case '7d':
                return date('Y-m-d H:i:s', strtotime('-7 days'));
            case '30d':
                return date('Y-m-d H:i:s', strtotime('-30 days'));
            default:
                return date('Y-m-d H:i:s', strtotime('-24 hours'));
        }
    }

    /**
     * Test ping connectivity
     */
    private function testPing($ip)
    {
        $output = [];
        $returnVar = null;
        
        // Execute ping command
        exec("ping -c 1 -W 3 {$ip} 2>&1", $output, $returnVar);
        
        $success = $returnVar === 0;
        $responseTime = null;

        if ($success && !empty($output)) {
            // Extract response time from ping output
            foreach ($output as $line) {
                if (preg_match('/time=(\d+\.?\d*)\s*ms/', $line, $matches)) {
                    $responseTime = floatval($matches[1]);
                    break;
                }
            }
        }

        return [
            'success' => $success,
            'response_time_ms' => $responseTime,
            'output' => implode("\n", $output)
        ];
    }

    /**
     * Test HTTP/HTTPS connectivity
     */
    private function testHTTP($ip, $port)
    {
        $startTime = microtime(true);
        $connection = @fsockopen($ip, $port, $errno, $errstr, 10);
        $endTime = microtime(true);

        $success = $connection !== false;
        $responseTime = $success ? round(($endTime - $startTime) * 1000, 2) : null;

        if ($connection) {
            fclose($connection);
        }

        return [
            'success' => $success,
            'response_time_ms' => $responseTime,
            'error' => $success ? null : $errstr
        ];
    }

    /**
     * Test port connectivity
     */
    private function testPort($ip, $port)
    {
        return $this->testHTTP($ip, $port);
    }

    /**
     * Calculate health score from connectivity tests
     */
    private function calculateHealthScore($tests)
    {
        $totalTests = count($tests);
        $successfulTests = 0;
        $totalResponseTime = 0;
        $responseTimeCount = 0;

        foreach ($tests as $test) {
            if ($test['success']) {
                $successfulTests++;
            }
            if (isset($test['response_time_ms']) && $test['response_time_ms'] !== null) {
                $totalResponseTime += $test['response_time_ms'];
                $responseTimeCount++;
            }
        }

        // Base score from successful connections
        $connectivityScore = ($successfulTests / $totalTests) * 70;

        // Performance score from response times
        $performanceScore = 30;
        if ($responseTimeCount > 0) {
            $avgResponseTime = $totalResponseTime / $responseTimeCount;
            if ($avgResponseTime <= 100) {
                $performanceScore = 30;
            } elseif ($avgResponseTime <= 500) {
                $performanceScore = 20;
            } elseif ($avgResponseTime <= 1000) {
                $performanceScore = 10;
            } else {
                $performanceScore = 0;
            }
        }

        return round($connectivityScore + $performanceScore);
    }

    /**
     * Calculate metrics summary
     */
    private function calculateMetricsSummary($metrics)
    {
        if ($metrics->isEmpty()) {
            return [];
        }

        $cpuUsages = $metrics->pluck('cpu_usage_percent')->filter()->toArray();
        $memoryUsages = $metrics->pluck('memory_usage_percent')->filter()->toArray();
        $diskUsages = $metrics->pluck('disk_usage_percent')->filter()->toArray();
        $responseTimes = $metrics->pluck('response_time_ms')->filter()->toArray();

        return [
            'cpu' => [
                'avg' => !empty($cpuUsages) ? round(array_sum($cpuUsages) / count($cpuUsages), 2) : 0,
                'max' => !empty($cpuUsages) ? max($cpuUsages) : 0,
                'min' => !empty($cpuUsages) ? min($cpuUsages) : 0
            ],
            'memory' => [
                'avg' => !empty($memoryUsages) ? round(array_sum($memoryUsages) / count($memoryUsages), 2) : 0,
                'max' => !empty($memoryUsages) ? max($memoryUsages) : 0,
                'min' => !empty($memoryUsages) ? min($memoryUsages) : 0
            ],
            'disk' => [
                'avg' => !empty($diskUsages) ? round(array_sum($diskUsages) / count($diskUsages), 2) : 0,
                'max' => !empty($diskUsages) ? max($diskUsages) : 0,
                'min' => !empty($diskUsages) ? min($diskUsages) : 0
            ],
            'response_time' => [
                'avg' => !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes), 2) : 0,
                'max' => !empty($responseTimes) ? max($responseTimes) : 0,
                'min' => !empty($responseTimes) ? min($responseTimes) : 0
            ]
        ];
    }

    /**
     * Format data for charts
     */
    private function formatChartData($metrics)
    {
        $chartData = [
            'labels' => [],
            'cpu' => [],
            'memory' => [],
            'disk' => [],
            'response_time' => []
        ];

        foreach ($metrics as $metric) {
            $chartData['labels'][] = date('H:i', strtotime($metric->timestamp));
            $chartData['cpu'][] = $metric->cpu_usage_percent;
            $chartData['memory'][] = $metric->memory_usage_percent;
            $chartData['disk'][] = $metric->disk_usage_percent;
            $chartData['response_time'][] = $metric->response_time_ms;
        }

        return $chartData;
    }

    /**
     * Validate alert configuration
     */
    private function validateAlertConfiguration($alert)
    {
        $validTypes = ['cpu', 'memory', 'disk', 'uptime', 'response_time'];
        $validConditions = ['greater_than', 'less_than', 'equals'];

        if (!in_array($alert['alert_type'], $validTypes)) {
            throw new Exception('Invalid alert type');
        }

        if (!in_array($alert['condition'], $validConditions)) {
            throw new Exception('Invalid alert condition');
        }

        if (!is_numeric($alert['threshold_value'])) {
            throw new Exception('Threshold value must be numeric');
        }

        if ($alert['duration_minutes'] < 1) {
            throw new Exception('Duration must be at least 1 minute');
        }
    }

    /**
     * Send alert notification email
     */
    private function sendAlertNotification($alert, $metrics)
    {
        // In a real implementation, this would send an email
        // For now, we'll just log the notification
        $this->logHelper->log('alert_notification_sent', [
            'alert_id' => $alert->id,
            'instance_id' => $alert->instance_id,
            'email' => $alert->notification_email,
            'message' => $this->generateAlertMessage($alert, $metrics)
        ]);
    }

    /**
     * Generate alert message
     */
    private function generateAlertMessage($alert, $metrics)
    {
        $value = $this->getMetricValue($alert, $metrics);
        $unit = $this->getMetricUnit($alert->alert_type);

        return "Alert triggered: {$alert->metric_name} is {$value}{$unit}, threshold is {$alert->threshold_value}{$unit}";
    }

    /**
     * Get metric value for alert
     */
    private function getMetricValue($alert, $metrics)
    {
        switch ($alert->alert_type) {
            case 'cpu':
                return $metrics['cpu_usage'];
            case 'memory':
                return $metrics['memory_usage']['usage_percent'];
            case 'disk':
                return $metrics['disk_usage']['usage_percent'];
            case 'response_time':
                return $metrics['response_time'];
            case 'uptime':
                return round($metrics['uptime'] / 3600, 2);
            default:
                return 0;
        }
    }

    /**
     * Get metric unit
     */
    private function getMetricUnit($alertType)
    {
        switch ($alertType) {
            case 'cpu':
            case 'memory':
            case 'disk':
                return '%';
            case 'response_time':
                return 'ms';
            case 'uptime':
                return 'h';
            default:
                return '';
        }
    }

    /**
     * Get server status summary
     */
    private function getServerStatusSummary()
    {
        return \WHMCS\Database\Capsule::table('mod_contabo_instances')
            ->leftJoin('mod_contabo_server_metrics', function($join) {
                $join->on('mod_contabo_instances.contabo_instance_id', '=', 'mod_contabo_server_metrics.instance_id')
                     ->where('mod_contabo_server_metrics.timestamp', '>=', date('Y-m-d H:i:s', strtotime('-15 minutes')));
            })
            ->select(
                'mod_contabo_instances.contabo_instance_id',
                'mod_contabo_instances.name',
                'mod_contabo_server_metrics.is_online',
                'mod_contabo_server_metrics.cpu_usage_percent',
                'mod_contabo_server_metrics.memory_usage_percent',
                'mod_contabo_server_metrics.timestamp'
            )
            ->get()
            ->toArray();
    }

    /**
     * Get recent alerts
     */
    private function getRecentAlerts($limit = 10)
    {
        return \WHMCS\Database\Capsule::table('mod_contabo_alert_history')
            ->leftJoin('mod_contabo_instances', 'mod_contabo_alert_history.instance_id', '=', 'mod_contabo_instances.contabo_instance_id')
            ->select(
                'mod_contabo_alert_history.*',
                'mod_contabo_instances.name as instance_name'
            )
            ->orderBy('mod_contabo_alert_history.triggered_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get performance summary
     */
    private function getPerformanceSummary()
    {
        $last24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        return \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
            ->where('timestamp', '>=', $last24h)
            ->selectRaw('
                AVG(cpu_usage_percent) as avg_cpu,
                AVG(memory_usage_percent) as avg_memory,
                AVG(disk_usage_percent) as avg_disk,
                AVG(response_time_ms) as avg_response_time,
                COUNT(*) as data_points
            ')
            ->first();
    }

    /**
     * Get uptime statistics
     */
    private function getUptimeStats()
    {
        $last30Days = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $stats = \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
            ->where('timestamp', '>=', $last30Days)
            ->selectRaw('
                COUNT(*) as total_checks,
                SUM(is_online) as online_checks,
                instance_id
            ')
            ->groupBy('instance_id')
            ->get();

        $overallUptime = 0;
        $serverCount = $stats->count();

        if ($serverCount > 0) {
            foreach ($stats as $stat) {
                if ($stat->total_checks > 0) {
                    $overallUptime += ($stat->online_checks / $stat->total_checks) * 100;
                }
            }
            $overallUptime = round($overallUptime / $serverCount, 2);
        }

        return [
            'overall_uptime_percent' => $overallUptime,
            'monitored_servers' => $serverCount,
            'last_30_days' => true
        ];
    }

    /**
     * Update server status
     */
    private function updateServerStatus($instanceId, $status, $isOnline)
    {
        \WHMCS\Database\Capsule::table('mod_contabo_instances')
            ->where('contabo_instance_id', $instanceId)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
}
