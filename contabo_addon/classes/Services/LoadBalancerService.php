<?php
/**
 * Load Balancer Service
 * 
 * Handles load balancer configuration, server pools, and traffic distribution for high availability
 */

namespace ContaboAddon\Services;

use Exception;

class LoadBalancerService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Create load balancer
     */
    public function createLoadBalancer($data)
    {
        try {
            // Validate load balancer data
            $this->validateLoadBalancerData($data);

            $loadBalancer = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'algorithm' => $data['algorithm'], // round_robin, least_connections, ip_hash
                'protocol' => $data['protocol'], // http, https, tcp
                'frontend_port' => $data['frontend_port'],
                'backend_port' => $data['backend_port'],
                'ssl_certificate_id' => $data['ssl_certificate_id'] ?? null,
                'session_persistence' => $data['session_persistence'] ?? false,
                'health_check_enabled' => $data['health_check_enabled'] ?? true,
                'health_check_path' => $data['health_check_path'] ?? '/',
                'health_check_interval' => $data['health_check_interval'] ?? 30,
                'health_check_timeout' => $data['health_check_timeout'] ?? 5,
                'health_check_retries' => $data['health_check_retries'] ?? 3,
                'is_active' => $data['is_active'] ?? true,
                'public_ip' => $this->generatePublicIP(),
                'configuration' => json_encode($data['configuration'] ?? []),
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $data['created_by'] ?? 'admin'
            ];

            $loadBalancerId = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->insertGetId($loadBalancer);

            $this->logHelper->log('load_balancer_created', [
                'load_balancer_id' => $loadBalancerId,
                'name' => $loadBalancer['name'],
                'algorithm' => $loadBalancer['algorithm']
            ]);

            return [
                'success' => true,
                'load_balancer_id' => $loadBalancerId,
                'public_ip' => $loadBalancer['public_ip'],
                'message' => 'Load balancer created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('load_balancer_creation_failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get load balancers
     */
    public function getLoadBalancers()
    {
        try {
            $loadBalancers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->orderBy('created_at', 'desc')
                ->get();

            $result = [];
            foreach ($loadBalancers as $lb) {
                // Get server count for this load balancer
                $serverCount = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                    ->where('load_balancer_id', $lb->id)
                    ->count();

                $result[] = [
                    'id' => $lb->id,
                    'name' => $lb->name,
                    'description' => $lb->description,
                    'algorithm' => $lb->algorithm,
                    'protocol' => $lb->protocol,
                    'frontend_port' => $lb->frontend_port,
                    'backend_port' => $lb->backend_port,
                    'public_ip' => $lb->public_ip,
                    'is_active' => (bool)$lb->is_active,
                    'server_count' => $serverCount,
                    'health_check_enabled' => (bool)$lb->health_check_enabled,
                    'session_persistence' => (bool)$lb->session_persistence,
                    'ssl_certificate_id' => $lb->ssl_certificate_id,
                    'created_at' => $lb->created_at,
                    'updated_at' => $lb->updated_at
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('load_balancers_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get load balancer details
     */
    public function getLoadBalancerDetails($loadBalancerId)
    {
        try {
            $loadBalancer = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->first();

            if (!$loadBalancer) {
                throw new Exception('Load balancer not found');
            }

            // Get servers in this load balancer
            $servers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->leftJoin('mod_contabo_instances', 
                    'mod_contabo_load_balancer_servers.instance_id', '=', 
                    'mod_contabo_instances.contabo_instance_id'
                )
                ->where('mod_contabo_load_balancer_servers.load_balancer_id', $loadBalancerId)
                ->select(
                    'mod_contabo_load_balancer_servers.*',
                    'mod_contabo_instances.name as instance_name',
                    'mod_contabo_instances.network_config'
                )
                ->get();

            $serverList = [];
            foreach ($servers as $server) {
                $networkConfig = json_decode($server->network_config, true);
                $serverList[] = [
                    'id' => $server->id,
                    'instance_id' => $server->instance_id,
                    'instance_name' => $server->instance_name ?: $server->instance_id,
                    'private_ip' => $server->private_ip,
                    'public_ip' => $networkConfig['ipv4'] ?? 'N/A',
                    'weight' => $server->weight,
                    'is_active' => (bool)$server->is_active,
                    'health_status' => $server->health_status,
                    'last_health_check' => $server->last_health_check,
                    'added_at' => $server->created_at
                ];
            }

            return [
                'load_balancer' => [
                    'id' => $loadBalancer->id,
                    'name' => $loadBalancer->name,
                    'description' => $loadBalancer->description,
                    'algorithm' => $loadBalancer->algorithm,
                    'protocol' => $loadBalancer->protocol,
                    'frontend_port' => $loadBalancer->frontend_port,
                    'backend_port' => $loadBalancer->backend_port,
                    'public_ip' => $loadBalancer->public_ip,
                    'is_active' => (bool)$loadBalancer->is_active,
                    'health_check_enabled' => (bool)$loadBalancer->health_check_enabled,
                    'health_check_path' => $loadBalancer->health_check_path,
                    'health_check_interval' => $loadBalancer->health_check_interval,
                    'health_check_timeout' => $loadBalancer->health_check_timeout,
                    'health_check_retries' => $loadBalancer->health_check_retries,
                    'session_persistence' => (bool)$loadBalancer->session_persistence,
                    'ssl_certificate_id' => $loadBalancer->ssl_certificate_id,
                    'configuration' => json_decode($loadBalancer->configuration, true),
                    'created_at' => $loadBalancer->created_at
                ],
                'servers' => $serverList
            ];

        } catch (Exception $e) {
            $this->logHelper->log('load_balancer_details_failed', [
                'load_balancer_id' => $loadBalancerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Add server to load balancer
     */
    public function addServerToLoadBalancer($loadBalancerId, $instanceId, $options = [])
    {
        try {
            // Verify load balancer exists
            $loadBalancer = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->first();

            if (!$loadBalancer) {
                throw new Exception('Load balancer not found');
            }

            // Verify instance exists
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if (!$instance) {
                throw new Exception('Instance not found');
            }

            // Check if server is already in load balancer
            $existingServer = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->where('load_balancer_id', $loadBalancerId)
                ->where('instance_id', $instanceId)
                ->first();

            if ($existingServer) {
                throw new Exception('Server is already in this load balancer');
            }

            // Get private IP for the instance
            $networkConfig = json_decode($instance->network_config, true);
            $privateIP = $this->getPrivateIPForInstance($instanceId, $networkConfig);

            $serverData = [
                'load_balancer_id' => $loadBalancerId,
                'instance_id' => $instanceId,
                'private_ip' => $privateIP,
                'weight' => $options['weight'] ?? 100,
                'is_active' => $options['is_active'] ?? true,
                'health_status' => 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $serverId = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->insertGetId($serverData);

            // Update load balancer configuration
            $this->updateLoadBalancerConfiguration($loadBalancerId);

            $this->logHelper->log('server_added_to_load_balancer', [
                'server_id' => $serverId,
                'load_balancer_id' => $loadBalancerId,
                'instance_id' => $instanceId
            ]);

            return [
                'success' => true,
                'server_id' => $serverId,
                'message' => 'Server added to load balancer successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('server_add_to_lb_failed', [
                'load_balancer_id' => $loadBalancerId,
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Remove server from load balancer
     */
    public function removeServerFromLoadBalancer($loadBalancerId, $serverId)
    {
        try {
            $server = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->where('id', $serverId)
                ->where('load_balancer_id', $loadBalancerId)
                ->first();

            if (!$server) {
                throw new Exception('Server not found in load balancer');
            }

            \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->where('id', $serverId)
                ->delete();

            // Update load balancer configuration
            $this->updateLoadBalancerConfiguration($loadBalancerId);

            $this->logHelper->log('server_removed_from_load_balancer', [
                'server_id' => $serverId,
                'load_balancer_id' => $loadBalancerId,
                'instance_id' => $server->instance_id
            ]);

            return [
                'success' => true,
                'message' => 'Server removed from load balancer successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('server_remove_from_lb_failed', [
                'load_balancer_id' => $loadBalancerId,
                'server_id' => $serverId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update load balancer
     */
    public function updateLoadBalancer($loadBalancerId, $data)
    {
        try {
            $existingLB = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->first();

            if (!$existingLB) {
                throw new Exception('Load balancer not found');
            }

            $updateData = [
                'name' => $data['name'],
                'description' => $data['description'],
                'algorithm' => $data['algorithm'],
                'protocol' => $data['protocol'],
                'frontend_port' => $data['frontend_port'],
                'backend_port' => $data['backend_port'],
                'health_check_enabled' => $data['health_check_enabled'] ?? $existingLB->health_check_enabled,
                'health_check_path' => $data['health_check_path'],
                'health_check_interval' => $data['health_check_interval'],
                'health_check_timeout' => $data['health_check_timeout'],
                'health_check_retries' => $data['health_check_retries'],
                'session_persistence' => $data['session_persistence'] ?? $existingLB->session_persistence,
                'ssl_certificate_id' => $data['ssl_certificate_id'],
                'is_active' => $data['is_active'] ?? $existingLB->is_active,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->update($updateData);

            // Update configuration
            $this->updateLoadBalancerConfiguration($loadBalancerId);

            $this->logHelper->log('load_balancer_updated', [
                'load_balancer_id' => $loadBalancerId,
                'name' => $updateData['name']
            ]);

            return [
                'success' => true,
                'message' => 'Load balancer updated successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('load_balancer_update_failed', [
                'load_balancer_id' => $loadBalancerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete load balancer
     */
    public function deleteLoadBalancer($loadBalancerId)
    {
        try {
            $loadBalancer = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->first();

            if (!$loadBalancer) {
                throw new Exception('Load balancer not found');
            }

            // Remove all servers from load balancer
            \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->where('load_balancer_id', $loadBalancerId)
                ->delete();

            // Delete health check history
            \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_health_checks')
                ->where('load_balancer_id', $loadBalancerId)
                ->delete();

            // Delete load balancer
            \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->delete();

            $this->logHelper->log('load_balancer_deleted', [
                'load_balancer_id' => $loadBalancerId,
                'name' => $loadBalancer->name
            ]);

            return [
                'success' => true,
                'message' => 'Load balancer deleted successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('load_balancer_deletion_failed', [
                'load_balancer_id' => $loadBalancerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Perform health checks for all load balancers
     */
    public function performHealthChecks()
    {
        try {
            $loadBalancers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('is_active', 1)
                ->where('health_check_enabled', 1)
                ->get();

            $results = [
                'load_balancers_checked' => 0,
                'servers_checked' => 0,
                'healthy_servers' => 0,
                'unhealthy_servers' => 0
            ];

            foreach ($loadBalancers as $lb) {
                $results['load_balancers_checked']++;
                
                $servers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                    ->where('load_balancer_id', $lb->id)
                    ->where('is_active', 1)
                    ->get();

                foreach ($servers as $server) {
                    $results['servers_checked']++;
                    
                    $healthStatus = $this->performServerHealthCheck($lb, $server);
                    
                    if ($healthStatus === 'healthy') {
                        $results['healthy_servers']++;
                    } else {
                        $results['unhealthy_servers']++;
                    }

                    // Update server health status
                    \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                        ->where('id', $server->id)
                        ->update([
                            'health_status' => $healthStatus,
                            'last_health_check' => date('Y-m-d H:i:s')
                        ]);

                    // Record health check result
                    \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_health_checks')->insert([
                        'load_balancer_id' => $lb->id,
                        'server_id' => $server->id,
                        'instance_id' => $server->instance_id,
                        'status' => $healthStatus,
                        'response_time_ms' => $this->getLastResponseTime($server),
                        'checked_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('health_checks_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get load balancer statistics
     */
    public function getLoadBalancerStatistics()
    {
        try {
            $stats = [
                'total_load_balancers' => \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')->count(),
                'active_load_balancers' => \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                    ->where('is_active', 1)
                    ->count(),
                'total_backend_servers' => \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')->count(),
                'healthy_servers' => \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                    ->where('health_status', 'healthy')
                    ->count()
            ];

            // Get algorithm distribution
            $algorithmStats = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->selectRaw('algorithm, COUNT(*) as count')
                ->groupBy('algorithm')
                ->pluck('count', 'algorithm')
                ->toArray();

            $stats['algorithm_distribution'] = $algorithmStats;

            // Get protocol distribution
            $protocolStats = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->selectRaw('protocol, COUNT(*) as count')
                ->groupBy('protocol')
                ->pluck('count', 'protocol')
                ->toArray();

            $stats['protocol_distribution'] = $protocolStats;

            // Get health check success rate (last 24h)
            $healthChecks = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_health_checks')
                ->where('checked_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->get();

            $totalChecks = $healthChecks->count();
            $successfulChecks = $healthChecks->where('status', 'healthy')->count();

            $stats['health_check_success_rate'] = $totalChecks > 0 ? 
                round(($successfulChecks / $totalChecks) * 100, 2) : 0;

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('load_balancer_stats_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get load balancer health status
     */
    public function getLoadBalancerHealthStatus($loadBalancerId)
    {
        try {
            $loadBalancer = \WHMCS\Database\Capsule::table('mod_contabo_load_balancers')
                ->where('id', $loadBalancerId)
                ->first();

            if (!$loadBalancer) {
                throw new Exception('Load balancer not found');
            }

            $servers = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                ->leftJoin('mod_contabo_instances', 
                    'mod_contabo_load_balancer_servers.instance_id', '=', 
                    'mod_contabo_instances.contabo_instance_id'
                )
                ->where('mod_contabo_load_balancer_servers.load_balancer_id', $loadBalancerId)
                ->select(
                    'mod_contabo_load_balancer_servers.*',
                    'mod_contabo_instances.name as instance_name'
                )
                ->get();

            $healthyServers = 0;
            $totalServers = $servers->count();

            $serverHealth = [];
            foreach ($servers as $server) {
                if ($server->health_status === 'healthy') {
                    $healthyServers++;
                }

                $serverHealth[] = [
                    'id' => $server->id,
                    'instance_id' => $server->instance_id,
                    'instance_name' => $server->instance_name ?: $server->instance_id,
                    'private_ip' => $server->private_ip,
                    'health_status' => $server->health_status,
                    'is_active' => (bool)$server->is_active,
                    'last_health_check' => $server->last_health_check,
                    'weight' => $server->weight
                ];
            }

            // Determine overall health status
            $overallHealth = 'unhealthy';
            if ($healthyServers === $totalServers && $totalServers > 0) {
                $overallHealth = 'healthy';
            } elseif ($healthyServers > 0) {
                $overallHealth = 'degraded';
            }

            return [
                'load_balancer_id' => $loadBalancerId,
                'name' => $loadBalancer->name,
                'overall_health' => $overallHealth,
                'healthy_servers' => $healthyServers,
                'total_servers' => $totalServers,
                'health_percentage' => $totalServers > 0 ? round(($healthyServers / $totalServers) * 100, 2) : 0,
                'servers' => $serverHealth,
                'last_updated' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logHelper->log('lb_health_status_failed', [
                'load_balancer_id' => $loadBalancerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available instances for load balancer
     */
    public function getAvailableInstances($loadBalancerId = null)
    {
        try {
            $query = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->leftJoin('tblhosting', 'mod_contabo_instances.service_id', '=', 'tblhosting.id')
                ->leftJoin('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
                ->where('mod_contabo_instances.status', 'running');

            // Exclude instances already in this load balancer
            if ($loadBalancerId) {
                $usedInstances = \WHMCS\Database\Capsule::table('mod_contabo_load_balancer_servers')
                    ->where('load_balancer_id', $loadBalancerId)
                    ->pluck('instance_id')
                    ->toArray();

                if (!empty($usedInstances)) {
                    $query->whereNotIn('mod_contabo_instances.contabo_instance_id', $usedInstances);
                }
            }

            $instances = $query
                ->select(
                    'mod_contabo_instances.contabo_instance_id',
                    'mod_contabo_instances.name',
                    'mod_contabo_instances.network_config',
                    'tblhosting.domain',
                    'tblclients.firstname',
                    'tblclients.lastname'
                )
                ->get();

            $result = [];
            foreach ($instances as $instance) {
                $networkConfig = json_decode($instance->network_config, true);
                $result[] = [
                    'instance_id' => $instance->contabo_instance_id,
                    'name' => $instance->name ?: $instance->contabo_instance_id,
                    'domain' => $instance->domain,
                    'client_name' => trim(($instance->firstname ?: '') . ' ' . ($instance->lastname ?: '')),
                    'public_ip' => $networkConfig['ipv4'] ?? 'N/A',
                    'private_ip' => $networkConfig['private_ip'] ?? 'Auto-detect'
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('available_instances_failed', [
                'load_balancer_id' => $loadBalancerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate load balancer data
     */
    private function validateLoadBalancerData($data)
    {
        $required = ['name', 'algorithm', 'protocol', 'frontend_port', 'backend_port'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        $validAlgorithms = ['round_robin', 'least_connections', 'ip_hash'];
        if (!in_array($data['algorithm'], $validAlgorithms)) {
            throw new Exception('Invalid load balancing algorithm');
        }

        $validProtocols = ['http', 'https', 'tcp'];
        if (!in_array($data['protocol'], $validProtocols)) {
            throw new Exception('Invalid protocol');
        }

        if (!is_numeric($data['frontend_port']) || $data['frontend_port'] < 1 || $data['frontend_port'] > 65535) {
            throw new Exception('Invalid frontend port');
        }

        if (!is_numeric($data['backend_port']) || $data['backend_port'] < 1 || $data['backend_port'] > 65535) {
            throw new Exception('Invalid backend port');
        }
    }

    /**
     * Generate public IP for load balancer
     */
    private function generatePublicIP()
    {
        // In real implementation, this would request a VIP from Contabo API
        // For now, generate a simulated IP
        return '185.194.' . rand(1, 254) . '.' . rand(1, 254);
    }

    /**
     * Get private IP for instance
     */
    private function getPrivateIPForInstance($instanceId, $networkConfig)
    {
        // Try to get private IP from network config, or generate one
        if (!empty($networkConfig['private_ip'])) {
            return $networkConfig['private_ip'];
        }

        // Generate private IP based on instance ID (simplified)
        $hash = crc32($instanceId);
        $octet = abs($hash) % 254 + 1;
        return "10.0.1.{$octet}";
    }

    /**
     * Update load balancer configuration
     */
    private function updateLoadBalancerConfiguration($loadBalancerId)
    {
        // In real implementation, this would push configuration to actual load balancer
        $this->logHelper->log('load_balancer_config_updated', [
            'load_balancer_id' => $loadBalancerId
        ]);
    }

    /**
     * Perform server health check
     */
    private function performServerHealthCheck($loadBalancer, $server)
    {
        try {
            // Get instance network info
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $server->instance_id)
                ->first();

            if (!$instance) {
                return 'unhealthy';
            }

            $networkConfig = json_decode($instance->network_config, true);
            $ip = $networkConfig['ipv4'] ?? null;

            if (!$ip) {
                return 'unhealthy';
            }

            // Perform health check based on protocol
            $isHealthy = false;
            
            switch ($loadBalancer->protocol) {
                case 'http':
                case 'https':
                    $isHealthy = $this->performHTTPHealthCheck($ip, $loadBalancer->backend_port, $loadBalancer->health_check_path);
                    break;
                    
                case 'tcp':
                    $isHealthy = $this->performTCPHealthCheck($ip, $loadBalancer->backend_port);
                    break;
            }

            return $isHealthy ? 'healthy' : 'unhealthy';

        } catch (Exception $e) {
            return 'unhealthy';
        }
    }

    /**
     * Perform HTTP health check
     */
    private function performHTTPHealthCheck($ip, $port, $path)
    {
        $url = "http://{$ip}:{$port}{$path}";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method' => 'GET'
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        return $response !== false;
    }

    /**
     * Perform TCP health check
     */
    private function performTCPHealthCheck($ip, $port)
    {
        $connection = @fsockopen($ip, $port, $errno, $errstr, 5);
        
        if ($connection) {
            fclose($connection);
            return true;
        }
        
        return false;
    }

    /**
     * Get last response time for server (simulated)
     */
    private function getLastResponseTime($server)
    {
        // Simulate response time between 10-500ms
        return rand(10, 500);
    }
}
