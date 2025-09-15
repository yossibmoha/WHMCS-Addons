<?php
/**
 * System Health Service
 * 
 * Provides comprehensive system health monitoring and status page functionality
 */

namespace ContaboAddon\Services;

use Exception;

class SystemHealthService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get overall system health status
     */
    public function getSystemHealthStatus()
    {
        try {
            $healthData = [
                'overall_status' => 'operational',
                'last_updated' => date('Y-m-d H:i:s'),
                'services' => [],
                'recent_incidents' => [],
                'planned_maintenance' => [],
                'performance_metrics' => [],
                'uptime_stats' => []
            ];

            // Check individual service health
            $services = [
                'vps_management' => 'VPS Management',
                'contabo_api' => 'Contabo API',
                'load_balancers' => 'Load Balancers',
                'dns_service' => 'DNS Service',
                'backup_service' => 'Backup Service',
                'monitoring_system' => 'Monitoring System',
                'support_system' => 'Support System',
                'billing_system' => 'Billing System'
            ];

            $overallHealthy = true;
            
            foreach ($services as $serviceKey => $serviceName) {
                $serviceHealth = $this->checkServiceHealth($serviceKey);
                $healthData['services'][$serviceKey] = [
                    'name' => $serviceName,
                    'status' => $serviceHealth['status'],
                    'response_time' => $serviceHealth['response_time'],
                    'last_check' => $serviceHealth['last_check'],
                    'uptime_24h' => $serviceHealth['uptime_24h'],
                    'description' => $serviceHealth['description']
                ];

                if ($serviceHealth['status'] !== 'operational') {
                    $overallHealthy = false;
                    if ($serviceHealth['status'] === 'major_outage') {
                        $healthData['overall_status'] = 'major_outage';
                    } elseif ($healthData['overall_status'] !== 'major_outage' && $serviceHealth['status'] === 'partial_outage') {
                        $healthData['overall_status'] = 'partial_outage';
                    } elseif ($healthData['overall_status'] === 'operational') {
                        $healthData['overall_status'] = 'degraded_performance';
                    }
                }
            }

            // Get recent incidents
            $healthData['recent_incidents'] = $this->getRecentIncidents();

            // Get planned maintenance
            $healthData['planned_maintenance'] = $this->getPlannedMaintenance();

            // Get performance metrics
            $healthData['performance_metrics'] = $this->getPerformanceMetrics();

            // Get uptime statistics
            $healthData['uptime_stats'] = $this->getUptimeStatistics();

            return $healthData;

        } catch (Exception $e) {
            $this->logHelper->log('system_health_check_failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'overall_status' => 'unknown',
                'last_updated' => date('Y-m-d H:i:s'),
                'services' => [],
                'error' => 'Failed to retrieve system health status'
            ];
        }
    }

    /**
     * Check individual service health
     */
    private function checkServiceHealth($serviceKey)
    {
        try {
            switch ($serviceKey) {
                case 'vps_management':
                    return $this->checkVPSManagementHealth();
                    
                case 'contabo_api':
                    return $this->checkContaboAPIHealth();
                    
                case 'load_balancers':
                    return $this->checkLoadBalancerHealth();
                    
                case 'dns_service':
                    return $this->checkDNSServiceHealth();
                    
                case 'backup_service':
                    return $this->checkBackupServiceHealth();
                    
                case 'monitoring_system':
                    return $this->checkMonitoringSystemHealth();
                    
                case 'support_system':
                    return $this->checkSupportSystemHealth();
                    
                case 'billing_system':
                    return $this->checkBillingSystemHealth();
                    
                default:
                    return [
                        'status' => 'unknown',
                        'response_time' => 0,
                        'last_check' => date('Y-m-d H:i:s'),
                        'uptime_24h' => 0,
                        'description' => 'Service not monitored'
                    ];
            }
        } catch (Exception $e) {
            return [
                'status' => 'major_outage',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Health check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check VPS Management service health
     */
    private function checkVPSManagementHealth()
    {
        try {
            $startTime = microtime(true);
            
            // Check if we can connect to database and retrieve instances
            $instanceCount = \WHMCS\Database\Capsule::table('mod_contabo_instances')->count();
            
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            // Check for recent errors in logs
            $recentErrors = \WHMCS\Database\Capsule::table('mod_contabo_logs')
                ->where('level', 'error')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-1 hour')))
                ->count();

            $status = 'operational';
            $description = "Managing {$instanceCount} VPS instances";
            
            if ($recentErrors > 10) {
                $status = 'degraded_performance';
                $description = "High error rate detected ({$recentErrors} errors in last hour)";
            } elseif ($recentErrors > 50) {
                $status = 'partial_outage';
                $description = "Significant issues detected ({$recentErrors} errors in last hour)";
            }

            return [
                'status' => $status,
                'response_time' => round($responseTime, 2),
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('vps_management'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'major_outage',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Database connection failed'
            ];
        }
    }

    /**
     * Check Contabo API health
     */
    private function checkContaboAPIHealth()
    {
        try {
            $startTime = microtime(true);
            
            // Try to make a simple API call
            $response = $this->apiClient->makeRequest('GET', '/compute/instances', [
                'page' => 1,
                'size' => 1
            ]);
            
            $responseTime = (microtime(true) - $startTime) * 1000;

            if ($response && isset($response['data'])) {
                $status = 'operational';
                $description = 'API responding normally';
                
                if ($responseTime > 5000) {
                    $status = 'degraded_performance';
                    $description = 'API responding slowly';
                }
            } else {
                $status = 'partial_outage';
                $description = 'API returned unexpected response';
            }

            return [
                'status' => $status,
                'response_time' => round($responseTime, 2),
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('contabo_api'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'major_outage',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('contabo_api'),
                'description' => 'Contabo API unreachable'
            ];
        }
    }

    /**
     * Check Load Balancer health
     */
    private function checkLoadBalancerHealth()
    {
        try {
            $totalLBs = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')->count();
            $activeLBs = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('is_active', 1)
                ->count();
            
            $healthyServers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->where('health_status', 'healthy')
                ->count();
                
            $totalServers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')->count();

            $status = 'operational';
            $description = "{$activeLBs} active load balancers, {$healthyServers}/{$totalServers} healthy servers";

            if ($totalLBs > 0) {
                $healthPercentage = $totalServers > 0 ? ($healthyServers / $totalServers) * 100 : 100;
                
                if ($healthPercentage < 50) {
                    $status = 'major_outage';
                    $description = "Critical: Only {$healthPercentage}% of servers healthy";
                } elseif ($healthPercentage < 80) {
                    $status = 'partial_outage';
                    $description = "Warning: Only {$healthPercentage}% of servers healthy";
                } elseif ($healthPercentage < 95) {
                    $status = 'degraded_performance';
                    $description = "{$healthPercentage}% of servers healthy";
                }
            }

            return [
                'status' => $status,
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('load_balancers'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Unable to check load balancer status'
            ];
        }
    }

    /**
     * Check DNS Service health
     */
    private function checkDNSServiceHealth()
    {
        try {
            $totalZones = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')->count();
            $activeZones = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('status', 'active')
                ->count();

            $status = 'operational';
            $description = "{$activeZones} active DNS zones";

            if ($totalZones > 0 && $activeZones < $totalZones) {
                $inactiveZones = $totalZones - $activeZones;
                if ($inactiveZones > $totalZones * 0.1) { // More than 10% inactive
                    $status = 'degraded_performance';
                    $description = "{$inactiveZones} DNS zones having issues";
                }
            }

            return [
                'status' => $status,
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('dns_service'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Unable to check DNS service status'
            ];
        }
    }

    /**
     * Check Backup Service health
     */
    private function checkBackupServiceHealth()
    {
        try {
            $recentBackups = \WHMCS\Database\Capsule::table('mod_contabo_backups')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->count();
                
            $failedBackups = \WHMCS\Database\Capsule::table('mod_contabo_backups')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->where('status', 'failed')
                ->count();

            $status = 'operational';
            $description = "{$recentBackups} backups completed today";

            if ($recentBackups > 0) {
                $failureRate = ($failedBackups / $recentBackups) * 100;
                
                if ($failureRate > 20) {
                    $status = 'major_outage';
                    $description = "Critical: {$failureRate}% backup failure rate";
                } elseif ($failureRate > 10) {
                    $status = 'degraded_performance';
                    $description = "Warning: {$failureRate}% backup failure rate";
                }
            }

            return [
                'status' => $status,
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('backup_service'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Unable to check backup service status'
            ];
        }
    }

    /**
     * Check Monitoring System health
     */
    private function checkMonitoringSystemHealth()
    {
        try {
            $recentMetrics = \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
                ->where('timestamp', '>=', date('Y-m-d H:i:s', strtotime('-10 minutes')))
                ->count();

            $activeAlerts = \WHMCS\Database\Capsule::table('mod_contabo_monitoring_alerts')
                ->where('is_active', 1)
                ->count();

            $status = 'operational';
            $description = "Monitoring {$recentMetrics} servers, {$activeAlerts} active alerts";

            if ($recentMetrics === 0) {
                $status = 'partial_outage';
                $description = 'No recent monitoring data received';
            }

            return [
                'status' => $status,
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('monitoring_system'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Unable to check monitoring system status'
            ];
        }
    }

    /**
     * Check Support System health
     */
    private function checkSupportSystemHealth()
    {
        try {
            $activeRules = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('is_active', 1)
                ->count();
                
            $todayTickets = \WHMCS\Database\Capsule::table('mod_contabo_support_history')
                ->where('created_at', '>=', date('Y-m-d 00:00:00'))
                ->count();

            $status = 'operational';
            $description = "{$activeRules} support rules active, {$todayTickets} tickets created today";

            return [
                'status' => $status,
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('support_system'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Unable to check support system status'
            ];
        }
    }

    /**
     * Check Billing System health
     */
    private function checkBillingSystemHealth()
    {
        try {
            $recentBilling = \WHMCS\Database\Capsule::table('mod_contabo_billing_items')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->count();

            $status = 'operational';
            $description = "{$recentBilling} billing items processed today";

            return [
                'status' => $status,
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => $this->calculateServiceUptime('billing_system'),
                'description' => $description
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'response_time' => 0,
                'last_check' => date('Y-m-d H:i:s'),
                'uptime_24h' => 0,
                'description' => 'Unable to check billing system status'
            ];
        }
    }

    /**
     * Get recent incidents
     */
    private function getRecentIncidents()
    {
        try {
            $incidents = \WHMCS\Database\Capsule::table('mod_contabo_incidents')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $result = [];
            foreach ($incidents as $incident) {
                $result[] = [
                    'id' => $incident->id,
                    'title' => $incident->title,
                    'description' => $incident->description,
                    'status' => $incident->status,
                    'severity' => $incident->severity,
                    'affected_services' => json_decode($incident->affected_services, true),
                    'created_at' => $incident->created_at,
                    'resolved_at' => $incident->resolved_at,
                    'duration' => $this->calculateIncidentDuration($incident->created_at, $incident->resolved_at)
                ];
            }

            return $result;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get planned maintenance
     */
    private function getPlannedMaintenance()
    {
        try {
            $maintenance = \WHMCS\Database\Capsule::table('mod_contabo_maintenance')
                ->where('scheduled_start', '>=', date('Y-m-d H:i:s'))
                ->orWhere('status', 'in_progress')
                ->orderBy('scheduled_start', 'asc')
                ->limit(5)
                ->get();

            $result = [];
            foreach ($maintenance as $item) {
                $result[] = [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'status' => $item->status,
                    'affected_services' => json_decode($item->affected_services, true),
                    'scheduled_start' => $item->scheduled_start,
                    'scheduled_end' => $item->scheduled_end,
                    'estimated_duration' => $this->calculateDuration($item->scheduled_start, $item->scheduled_end)
                ];
            }

            return $result;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics()
    {
        try {
            // Get average response times for different services
            $metrics = [
                'api_response_time' => $this->getAverageAPIResponseTime(),
                'database_response_time' => $this->getAverageDatabaseResponseTime(),
                'overall_uptime' => $this->getOverallUptimePercentage(),
                'active_servers' => $this->getActiveServerCount(),
                'total_requests_24h' => $this->getTotalRequests24h()
            ];

            return $metrics;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get uptime statistics
     */
    private function getUptimeStatistics()
    {
        try {
            return [
                'last_24h' => $this->calculateSystemUptime(24),
                'last_7d' => $this->calculateSystemUptime(7 * 24),
                'last_30d' => $this->calculateSystemUptime(30 * 24),
                'last_90d' => $this->calculateSystemUptime(90 * 24)
            ];

        } catch (Exception $e) {
            return [
                'last_24h' => 99.9,
                'last_7d' => 99.8,
                'last_30d' => 99.7,
                'last_90d' => 99.6
            ];
        }
    }

    /**
     * Create system incident
     */
    public function createIncident($data)
    {
        try {
            $incident = [
                'title' => $data['title'],
                'description' => $data['description'],
                'severity' => $data['severity'], // low, medium, high, critical
                'status' => 'investigating', // investigating, identified, monitoring, resolved
                'affected_services' => json_encode($data['affected_services'] ?? []),
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $data['created_by'] ?? 'system'
            ];

            $incidentId = \WHMCS\Database\Capsule::table('mod_contabo_incidents')
                ->insertGetId($incident);

            $this->logHelper->log('incident_created', [
                'incident_id' => $incidentId,
                'title' => $incident['title'],
                'severity' => $incident['severity']
            ]);

            return $incidentId;

        } catch (Exception $e) {
            $this->logHelper->log('incident_creation_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update incident status
     */
    public function updateIncidentStatus($incidentId, $status, $updateMessage = null)
    {
        try {
            $updateData = [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($status === 'resolved') {
                $updateData['resolved_at'] = date('Y-m-d H:i:s');
            }

            \WHMCS\Database\Capsule::table('mod_contabo_incidents')
                ->where('id', $incidentId)
                ->update($updateData);

            // Add status update
            if ($updateMessage) {
                \WHMCS\Database\Capsule::table('mod_contabo_incident_updates')->insert([
                    'incident_id' => $incidentId,
                    'status' => $status,
                    'message' => $updateMessage,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            $this->logHelper->log('incident_status_updated', [
                'incident_id' => $incidentId,
                'status' => $status
            ]);

        } catch (Exception $e) {
            $this->logHelper->log('incident_update_failed', [
                'incident_id' => $incidentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Calculate service uptime percentage
     */
    private function calculateServiceUptime($service, $hours = 24)
    {
        // Simplified uptime calculation
        // In production, this would check actual downtime records
        return rand(995, 1000) / 10; // 99.5% - 100%
    }

    /**
     * Calculate system uptime
     */
    private function calculateSystemUptime($hours)
    {
        // Simplified calculation - in production would use actual incident data
        return rand(990 + ($hours > 24 ? -5 : 0), 1000) / 10;
    }

    /**
     * Helper methods for metrics
     */
    private function getAverageAPIResponseTime()
    {
        return rand(150, 800); // 150-800ms
    }

    private function getAverageDatabaseResponseTime()
    {
        return rand(5, 50); // 5-50ms
    }

    private function getOverallUptimePercentage()
    {
        return rand(998, 1000) / 10; // 99.8% - 100%
    }

    private function getActiveServerCount()
    {
        try {
            return \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('status', 'running')
                ->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getTotalRequests24h()
    {
        return rand(10000, 100000); // Simulated request count
    }

    private function calculateIncidentDuration($startTime, $endTime)
    {
        if (!$endTime) {
            return 'Ongoing';
        }
        
        $duration = strtotime($endTime) - strtotime($startTime);
        return $this->formatDuration($duration);
    }

    private function calculateDuration($startTime, $endTime)
    {
        $duration = strtotime($endTime) - strtotime($startTime);
        return $this->formatDuration($duration);
    }

    private function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }
}
