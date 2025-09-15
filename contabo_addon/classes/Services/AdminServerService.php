<?php
/**
 * Admin Server Management Service
 * 
 * Allows administrators to view all Contabo servers and attach them to WHMCS users
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class AdminServerService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get all Contabo servers (both tracked and untracked)
     */
    public function getAllContaboServers()
    {
        try {
            // Get all instances from Contabo API
            $response = $this->apiClient->makeRequest('GET', '/v1/compute/instances?size=200');
            $contaboInstances = $response['data'] ?? [];

            // Get all locally tracked instances
            $localInstances = Capsule::table('mod_contabo_instances')
                ->get()
                ->keyBy('contabo_instance_id');

            // Get all WHMCS services for user mapping
            $whmcsServices = Capsule::table('tblhosting')
                ->leftJoin('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
                ->select('tblhosting.*', 'tblclients.firstname', 'tblclients.lastname', 'tblclients.email')
                ->get()
                ->keyBy('id');

            $serverList = [];

            foreach ($contaboInstances as $instance) {
                $instanceId = $instance['instanceId'];
                $localInstance = $localInstances->get($instanceId);
                $whmcsService = null;

                if ($localInstance) {
                    $whmcsService = $whmcsServices->get($localInstance->service_id);
                }

                $serverList[] = [
                    'contabo_id' => $instanceId,
                    'name' => $instance['name'] ?? $instance['displayName'] ?? 'Unknown',
                    'status' => $instance['status'] ?? 'unknown',
                    'region' => $instance['region'] ?? 'unknown',
                    'product_id' => $instance['productId'] ?? 'unknown',
                    'ip_address' => $instance['ipConfig']['v4']['ip'] ?? 'N/A',
                    'specs' => [
                        'ram' => $instance['ramMb'] ?? 0,
                        'cpu' => $instance['cpuCores'] ?? 0,
                        'disk' => $instance['diskMb'] ?? 0
                    ],
                    'created_date' => $instance['createdDate'] ?? null,
                    'is_tracked' => $localInstance !== null,
                    'local_id' => $localInstance->id ?? null,
                    'service_id' => $localInstance->service_id ?? null,
                    'whmcs_user' => $whmcsService ? [
                        'id' => $whmcsService->userid,
                        'name' => $whmcsService->firstname . ' ' . $whmcsService->lastname,
                        'email' => $whmcsService->email,
                        'service_id' => $whmcsService->id,
                        'domain' => $whmcsService->domain,
                        'product_name' => $whmcsService->producttype
                    ] : null,
                    'can_attach' => $localInstance === null, // Can only attach if not already tracked
                    'addons' => $instance['addOns'] ?? []
                ];
            }

            return [
                'success' => true,
                'servers' => $serverList,
                'total_servers' => count($serverList),
                'tracked_servers' => $localInstances->count(),
                'untracked_servers' => count($serverList) - $localInstances->count()
            ];

        } catch (Exception $e) {
            $this->logHelper->log('admin_server_list_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Attach Contabo server to WHMCS service
     */
    public function attachServerToService($contaboInstanceId, $serviceId, $options = [])
    {
        try {
            // Verify the service exists
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if (!$service) {
                throw new Exception('WHMCS service not found');
            }

            // Verify the instance exists and isn't already tracked
            $existingInstance = Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $contaboInstanceId)
                ->first();

            if ($existingInstance) {
                throw new Exception('Server is already attached to a WHMCS service');
            }

            // Get instance details from Contabo
            $response = $this->apiClient->makeRequest('GET', "/v1/compute/instances/{$contaboInstanceId}");
            $instance = $response['data'][0] ?? null;

            if (!$instance) {
                throw new Exception('Contabo instance not found');
            }

            // Create local tracking record
            $localId = Capsule::table('mod_contabo_instances')->insertGetId([
                'service_id' => $serviceId,
                'contabo_instance_id' => $contaboInstanceId,
                'name' => $instance['name'] ?? $instance['displayName'] ?? 'Imported Server',
                'status' => $instance['status'] ?? 'unknown',
                'image_id' => $instance['imageId'] ?? 'unknown',
                'datacenter' => $instance['region'] ?? 'unknown',
                'specs' => json_encode([
                    'productId' => $instance['productId'] ?? 'unknown',
                    'ramMb' => $instance['ramMb'] ?? 0,
                    'cpuCores' => $instance['cpuCores'] ?? 0,
                    'diskMb' => $instance['diskMb'] ?? 0,
                    'imported' => true,
                    'imported_at' => now(),
                    'imported_by' => $_SESSION['adminid'] ?? 'system'
                ]),
                'network_config' => json_encode([
                    'ipv4' => $instance['ipConfig']['v4']['ip'] ?? null,
                    'ipv6' => $instance['ipConfig']['v6']['ip'] ?? null,
                ]),
                'cloud_init_script' => null,
                'created_at' => $instance['createdDate'] ? date('Y-m-d H:i:s', strtotime($instance['createdDate'])) : now(),
                'updated_at' => now()
            ]);

            // Update WHMCS service status if requested
            if ($options['update_service_status'] ?? false) {
                $whmcsStatus = $this->mapContaboStatusToWHMCS($instance['status']);
                Capsule::table('tblhosting')
                    ->where('id', $serviceId)
                    ->update([
                        'domainstatus' => $whmcsStatus,
                        'updated_at' => now()
                    ]);
            }

            $this->logHelper->log('server_attached_to_service', [
                'contabo_instance_id' => $contaboInstanceId,
                'service_id' => $serviceId,
                'local_id' => $localId,
                'admin_id' => $_SESSION['adminid'] ?? null
            ]);

            return [
                'success' => true,
                'message' => 'Server successfully attached to WHMCS service',
                'local_id' => $localId,
                'service_id' => $serviceId,
                'contabo_instance_id' => $contaboInstanceId
            ];

        } catch (Exception $e) {
            $this->logHelper->log('server_attach_failed', [
                'contabo_instance_id' => $contaboInstanceId,
                'service_id' => $serviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Detach server from WHMCS service
     */
    public function detachServerFromService($contaboInstanceId)
    {
        try {
            $instance = Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $contaboInstanceId)
                ->first();

            if (!$instance) {
                throw new Exception('Server tracking record not found');
            }

            // Remove tracking record
            Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $contaboInstanceId)
                ->delete();

            // Also clean up related records
            Capsule::table('mod_contabo_backups')
                ->where('instance_id', $contaboInstanceId)
                ->delete();

            $this->logHelper->log('server_detached_from_service', [
                'contabo_instance_id' => $contaboInstanceId,
                'service_id' => $instance->service_id,
                'admin_id' => $_SESSION['adminid'] ?? null
            ]);

            return [
                'success' => true,
                'message' => 'Server successfully detached from WHMCS service'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('server_detach_failed', [
                'contabo_instance_id' => $contaboInstanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available WHMCS services for attachment
     */
    public function getAvailableServicesForAttachment()
    {
        try {
            // Get services that don't have Contabo instances attached
            $attachedServiceIds = Capsule::table('mod_contabo_instances')
                ->pluck('service_id')
                ->toArray();

            $services = Capsule::table('tblhosting')
                ->leftJoin('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
                ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->whereNotIn('tblhosting.id', $attachedServiceIds)
                ->whereIn('tblhosting.domainstatus', ['Active', 'Pending', 'Suspended'])
                ->select(
                    'tblhosting.id as service_id',
                    'tblhosting.domain',
                    'tblhosting.domainstatus',
                    'tblhosting.regdate',
                    'tblclients.id as client_id',
                    'tblclients.firstname',
                    'tblclients.lastname',
                    'tblclients.email',
                    'tblproducts.name as product_name'
                )
                ->orderBy('tblhosting.regdate', 'desc')
                ->get();

            return $services->map(function($service) {
                return [
                    'service_id' => $service->service_id,
                    'domain' => $service->domain,
                    'status' => $service->domainstatus,
                    'created' => $service->regdate,
                    'product_name' => $service->product_name,
                    'client' => [
                        'id' => $service->client_id,
                        'name' => $service->firstname . ' ' . $service->lastname,
                        'email' => $service->email
                    ]
                ];
            })->toArray();

        } catch (Exception $e) {
            $this->logHelper->log('available_services_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk import all untracked servers
     */
    public function bulkImportUntrackedServers($options = [])
    {
        try {
            $allServers = $this->getAllContaboServers();
            $untrackedServers = array_filter($allServers['servers'], function($server) {
                return !$server['is_tracked'];
            });

            $results = [
                'success' => true,
                'total_processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'errors' => []
            ];

            foreach ($untrackedServers as $server) {
                $results['total_processed']++;
                
                try {
                    // Create placeholder service if auto_create_services is enabled
                    if ($options['auto_create_services'] ?? false) {
                        $serviceId = $this->createPlaceholderService($server);
                    } else {
                        // Skip if no service to attach to
                        $results['skipped']++;
                        continue;
                    }

                    $this->attachServerToService($server['contabo_id'], $serviceId, [
                        'update_service_status' => true
                    ]);
                    
                    $results['imported']++;

                } catch (Exception $e) {
                    $results['errors'][] = [
                        'server_id' => $server['contabo_id'],
                        'server_name' => $server['name'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return $results;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Map Contabo status to WHMCS status
     */
    private function mapContaboStatusToWHMCS($contaboStatus)
    {
        $statusMap = [
            'running' => 'Active',
            'stopped' => 'Suspended',
            'provisioning' => 'Pending',
            'installing' => 'Pending',
            'error' => 'Suspended',
            'unknown' => 'Pending'
        ];

        return $statusMap[$contaboStatus] ?? 'Pending';
    }

    /**
     * Create placeholder WHMCS service for imported server
     */
    private function createPlaceholderService($server)
    {
        // This would create a basic WHMCS service
        // Implementation depends on your specific requirements
        throw new Exception('Auto-create services not implemented yet');
    }

    /**
     * Get server management statistics
     */
    public function getServerManagementStats()
    {
        try {
            $allServers = $this->getAllContaboServers();
            
            $stats = [
                'total_contabo_servers' => $allServers['total_servers'],
                'tracked_servers' => $allServers['tracked_servers'], 
                'untracked_servers' => $allServers['untracked_servers'],
                'tracking_percentage' => $allServers['total_servers'] > 0 ? 
                    round(($allServers['tracked_servers'] / $allServers['total_servers']) * 100, 1) : 0,
                'servers_by_status' => [],
                'servers_by_region' => []
            ];

            // Group by status and region
            foreach ($allServers['servers'] as $server) {
                $status = $server['status'];
                $region = $server['region'];

                if (!isset($stats['servers_by_status'][$status])) {
                    $stats['servers_by_status'][$status] = 0;
                }
                $stats['servers_by_status'][$status]++;

                if (!isset($stats['servers_by_region'][$region])) {
                    $stats['servers_by_region'][$region] = 0;
                }
                $stats['servers_by_region'][$region]++;
            }

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('server_stats_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
