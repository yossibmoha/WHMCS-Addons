<?php
/**
 * Contabo Networking Service
 * 
 * Handles private networks and VIP address management
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class NetworkingService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Create a new private network
     */
    public function createPrivateNetwork($data)
    {
        try {
            // Validate required fields
            $requiredFields = ['name', 'cidr', 'region'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            // Validate CIDR format
            if (!$this->isValidCIDR($data['cidr'])) {
                throw new Exception('Invalid CIDR format');
            }

            // Prepare network data
            $networkData = [
                'name' => $data['name'],
                'cidr' => $data['cidr'],
                'region' => $data['region'],
                'description' => $data['description'] ?? ''
            ];

            // Create private network via API
            $response = $this->apiClient->createPrivateNetwork($networkData);

            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('Invalid response from Contabo API');
            }

            $network = $response['data'][0];

            // Store network in local database
            $networkId = Capsule::table('mod_contabo_private_networks')->insertGetId([
                'service_id' => $data['service_id'],
                'contabo_network_id' => $network['privateNetworkId'],
                'name' => $network['name'],
                'cidr' => $network['cidr'],
                'datacenter' => $network['region'],
                'connected_instances' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->logHelper->log('private_network_created', [
                'service_id' => $data['service_id'],
                'contabo_network_id' => $network['privateNetworkId'],
                'local_id' => $networkId
            ]);

            return [
                'success' => true,
                'networkId' => $network['privateNetworkId'],
                'localId' => $networkId,
                'data' => $network
            ];

        } catch (Exception $e) {
            $this->logHelper->log('private_network_creation_failed', [
                'service_id' => $data['service_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get private network details
     */
    public function getPrivateNetwork($networkId, $useContaboId = true)
    {
        try {
            if ($useContaboId) {
                // Get from Contabo API
                $response = $this->apiClient->getPrivateNetwork($networkId);
                $network = $response['data'][0] ?? null;
                
                if (!$network) {
                    throw new Exception('Private network not found');
                }

                // Update local database
                $this->updateLocalNetwork($networkId, $network);

                return $network;
            } else {
                // Get from local database
                $localNetwork = Capsule::table('mod_contabo_private_networks')
                    ->where('id', $networkId)
                    ->first();

                if (!$localNetwork) {
                    throw new Exception('Private network not found in local database');
                }

                // Get fresh data from API
                return $this->getPrivateNetwork($localNetwork->contabo_network_id, true);
            }

        } catch (Exception $e) {
            $this->logHelper->log('private_network_fetch_failed', [
                'network_id' => $networkId,
                'use_contabo_id' => $useContaboId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update private network
     */
    public function updatePrivateNetwork($networkId, $data)
    {
        try {
            $updateData = [];

            // Only include fields that can be updated
            $allowedFields = ['name', 'description'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (empty($updateData)) {
                throw new Exception('No valid fields to update');
            }

            $response = $this->apiClient->updatePrivateNetwork($networkId, $updateData);
            
            // Update local database
            if (isset($response['data']) && !empty($response['data'])) {
                $network = $response['data'][0];
                $this->updateLocalNetwork($networkId, $network);
            }

            $this->logHelper->log('private_network_updated', [
                'network_id' => $networkId,
                'updated_fields' => array_keys($updateData)
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('private_network_update_failed', [
                'network_id' => $networkId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete private network
     */
    public function deletePrivateNetwork($networkId)
    {
        try {
            $response = $this->apiClient->deletePrivateNetwork($networkId);

            // Remove from local database
            Capsule::table('mod_contabo_private_networks')
                ->where('contabo_network_id', $networkId)
                ->delete();

            $this->logHelper->log('private_network_deleted', [
                'network_id' => $networkId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('private_network_deletion_failed', [
                'network_id' => $networkId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Assign instance to private network
     */
    public function assignInstanceToNetwork($networkId, $instanceId, $ipAddress = null)
    {
        try {
            $assignData = [];
            
            if ($ipAddress && $this->isValidIPAddress($ipAddress)) {
                $assignData['ipv4'] = $ipAddress;
            }

            $response = $this->apiClient->assignInstanceToNetwork($networkId, $instanceId, $assignData);

            // Update local database with connected instance
            $this->updateNetworkInstances($networkId, $instanceId, 'add');

            $this->logHelper->log('instance_assigned_to_network', [
                'network_id' => $networkId,
                'instance_id' => $instanceId,
                'ip_address' => $ipAddress
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('instance_network_assignment_failed', [
                'network_id' => $networkId,
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Remove instance from private network
     */
    public function removeInstanceFromNetwork($networkId, $instanceId)
    {
        try {
            $response = $this->apiClient->removeInstanceFromNetwork($networkId, $instanceId);

            // Update local database
            $this->updateNetworkInstances($networkId, $instanceId, 'remove');

            $this->logHelper->log('instance_removed_from_network', [
                'network_id' => $networkId,
                'instance_id' => $instanceId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('instance_network_removal_failed', [
                'network_id' => $networkId,
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create VIP address
     */
    public function createVip($data)
    {
        try {
            // Validate required fields
            $requiredFields = ['region'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            // Prepare VIP data
            $vipData = [
                'region' => $data['region'],
                'type' => $data['type'] ?? 'ipv4'
            ];

            // Create VIP via API
            $response = $this->apiClient->createVip($vipData);

            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('Invalid response from Contabo API');
            }

            $vip = $response['data'][0];

            // Store VIP in local database
            $vipId = Capsule::table('mod_contabo_vips')->insertGetId([
                'service_id' => $data['service_id'],
                'ip_address' => $vip['ip'],
                'contabo_resource_type' => '',
                'contabo_resource_id' => '',
                'datacenter' => $vip['region'],
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->logHelper->log('vip_created', [
                'service_id' => $data['service_id'],
                'ip_address' => $vip['ip'],
                'local_id' => $vipId
            ]);

            return [
                'success' => true,
                'ip' => $vip['ip'],
                'localId' => $vipId,
                'data' => $vip
            ];

        } catch (Exception $e) {
            $this->logHelper->log('vip_creation_failed', [
                'service_id' => $data['service_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Assign VIP to resource
     */
    public function assignVip($ip, $resourceType, $resourceId)
    {
        try {
            $assignData = [
                'type' => $resourceType,
                'resourceId' => $resourceId
            ];

            $response = $this->apiClient->assignVip($ip, $resourceType, $resourceId, $assignData);

            // Update local database
            Capsule::table('mod_contabo_vips')
                ->where('ip_address', $ip)
                ->update([
                    'contabo_resource_type' => $resourceType,
                    'contabo_resource_id' => $resourceId,
                    'updated_at' => now()
                ]);

            $this->logHelper->log('vip_assigned', [
                'ip' => $ip,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('vip_assignment_failed', [
                'ip' => $ip,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Unassign VIP from resource
     */
    public function unassignVip($ip, $resourceType, $resourceId)
    {
        try {
            $response = $this->apiClient->unassignVip($ip, $resourceType, $resourceId);

            // Update local database
            Capsule::table('mod_contabo_vips')
                ->where('ip_address', $ip)
                ->update([
                    'contabo_resource_type' => '',
                    'contabo_resource_id' => '',
                    'updated_at' => now()
                ]);

            $this->logHelper->log('vip_unassigned', [
                'ip' => $ip,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('vip_unassignment_failed', [
                'ip' => $ip,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get VIP details
     */
    public function getVip($ip)
    {
        try {
            $response = $this->apiClient->getVip($ip);
            return $response['data'][0] ?? null;

        } catch (Exception $e) {
            $this->logHelper->log('vip_fetch_failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get network topology for visualization
     */
    public function getNetworkTopology($serviceId)
    {
        try {
            $topology = [
                'networks' => [],
                'instances' => [],
                'vips' => [],
                'connections' => []
            ];

            // Get private networks
            $networks = Capsule::table('mod_contabo_private_networks')
                ->where('service_id', $serviceId)
                ->get();

            foreach ($networks as $network) {
                $topology['networks'][] = [
                    'id' => $network->contabo_network_id,
                    'name' => $network->name,
                    'cidr' => $network->cidr,
                    'datacenter' => $network->datacenter,
                    'connected_instances' => json_decode($network->connected_instances, true)
                ];
            }

            // Get instances
            $instances = Capsule::table('mod_contabo_instances')
                ->where('service_id', $serviceId)
                ->get();

            foreach ($instances as $instance) {
                $networkConfig = json_decode($instance->network_config, true);
                $topology['instances'][] = [
                    'id' => $instance->contabo_instance_id,
                    'name' => $instance->name,
                    'status' => $instance->status,
                    'datacenter' => $instance->datacenter,
                    'ipv4' => $networkConfig['ipv4'] ?? null,
                    'ipv6' => $networkConfig['ipv6'] ?? null
                ];
            }

            // Get VIPs
            $vips = Capsule::table('mod_contabo_vips')
                ->where('service_id', $serviceId)
                ->get();

            foreach ($vips as $vip) {
                $topology['vips'][] = [
                    'ip' => $vip->ip_address,
                    'resource_type' => $vip->contabo_resource_type,
                    'resource_id' => $vip->contabo_resource_id,
                    'datacenter' => $vip->datacenter
                ];
            }

            return $topology;

        } catch (Exception $e) {
            $this->logHelper->log('network_topology_failed', [
                'service_id' => $serviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate network configuration script
     */
    public function generateNetworkConfigScript($instanceId, $networkId, $ipAddress)
    {
        try {
            // Get network details
            $network = Capsule::table('mod_contabo_private_networks')
                ->where('contabo_network_id', $networkId)
                ->first();

            if (!$network) {
                throw new Exception('Network not found');
            }

            // Extract network information
            $cidrParts = explode('/', $network->cidr);
            $networkAddress = $cidrParts[0];
            $prefixLength = $cidrParts[1] ?? 24;
            $netmask = $this->cidrToNetmask($prefixLength);
            $gateway = $this->calculateGateway($networkAddress, $prefixLength);

            // Generate Ubuntu/Debian netplan configuration
            $netplanConfig = [
                'network' => [
                    'version' => 2,
                    'ethernets' => [
                        'eth1' => [
                            'addresses' => [$ipAddress . '/' . $prefixLength],
                            'routes' => [
                                [
                                    'to' => $network->cidr,
                                    'via' => $gateway,
                                    'metric' => 100
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // Generate traditional network configuration
            $traditionalConfig = [
                'interface' => 'eth1',
                'address' => $ipAddress,
                'netmask' => $netmask,
                'network' => $networkAddress,
                'gateway' => $gateway
            ];

            return [
                'netplan_yaml' => yaml_emit($netplanConfig),
                'traditional' => $traditionalConfig,
                'scripts' => [
                    'netplan' => [
                        'title' => 'Ubuntu/Debian Netplan Configuration',
                        'commands' => [
                            'sudo tee /etc/netplan/60-private-network.yaml << EOF',
                            yaml_emit($netplanConfig),
                            'EOF',
                            'sudo netplan apply'
                        ]
                    ],
                    'ifconfig' => [
                        'title' => 'Manual Network Configuration',
                        'commands' => [
                            "sudo ifconfig eth1 {$ipAddress} netmask {$netmask}",
                            "sudo route add -net {$network->cidr} gw {$gateway} dev eth1"
                        ]
                    ]
                ]
            ];

        } catch (Exception $e) {
            $this->logHelper->log('network_config_generation_failed', [
                'instance_id' => $instanceId,
                'network_id' => $networkId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get service networks for a WHMCS service
     */
    public function getServiceNetworks($serviceId)
    {
        return Capsule::table('mod_contabo_private_networks')
            ->where('service_id', $serviceId)
            ->get();
    }

    /**
     * Get service VIPs for a WHMCS service
     */
    public function getServiceVips($serviceId)
    {
        return Capsule::table('mod_contabo_vips')
            ->where('service_id', $serviceId)
            ->get();
    }

    /**
     * Validate CIDR format
     */
    private function isValidCIDR($cidr)
    {
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) {
            return false;
        }

        list($ip, $prefix) = explode('/', $cidr);
        
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false 
               && $prefix >= 8 && $prefix <= 30;
    }

    /**
     * Validate IP address
     */
    private function isValidIPAddress($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Convert CIDR prefix to netmask
     */
    private function cidrToNetmask($prefix)
    {
        $mask = str_repeat('1', $prefix) . str_repeat('0', 32 - $prefix);
        $mask = chunk_split($mask, 8, '.');
        $mask = rtrim($mask, '.');
        
        $parts = explode('.', $mask);
        foreach ($parts as $key => $part) {
            $parts[$key] = bindec($part);
        }
        
        return implode('.', $parts);
    }

    /**
     * Calculate gateway address
     */
    private function calculateGateway($networkAddress, $prefix)
    {
        $ip = ip2long($networkAddress);
        $gateway = $ip + 1; // Usually first available IP
        return long2ip($gateway);
    }

    /**
     * Update local network data
     */
    private function updateLocalNetwork($contaboNetworkId, $networkData)
    {
        try {
            $updateData = [
                'name' => $networkData['name'] ?? '',
                'updated_at' => now()
            ];

            Capsule::table('mod_contabo_private_networks')
                ->where('contabo_network_id', $contaboNetworkId)
                ->update($updateData);

        } catch (Exception $e) {
            $this->logHelper->log('local_network_update_failed', [
                'contabo_network_id' => $contaboNetworkId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update network instances
     */
    private function updateNetworkInstances($networkId, $instanceId, $action)
    {
        try {
            $network = Capsule::table('mod_contabo_private_networks')
                ->where('contabo_network_id', $networkId)
                ->first();

            if (!$network) {
                return;
            }

            $connectedInstances = json_decode($network->connected_instances, true) ?? [];

            if ($action === 'add' && !in_array($instanceId, $connectedInstances)) {
                $connectedInstances[] = $instanceId;
            } elseif ($action === 'remove') {
                $connectedInstances = array_filter($connectedInstances, function($id) use ($instanceId) {
                    return $id !== $instanceId;
                });
                $connectedInstances = array_values($connectedInstances);
            }

            Capsule::table('mod_contabo_private_networks')
                ->where('contabo_network_id', $networkId)
                ->update([
                    'connected_instances' => json_encode($connectedInstances),
                    'updated_at' => now()
                ]);

        } catch (Exception $e) {
            $this->logHelper->log('network_instances_update_failed', [
                'network_id' => $networkId,
                'instance_id' => $instanceId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }
}
