<?php
/**
 * Contabo Compute Service
 * 
 * Handles VPS/VDS instance management, snapshots, and cloud-init
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class ComputeService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Create a new compute instance
     */
    public function createInstance($data)
    {
        try {
            // Validate required fields
            $requiredFields = ['imageId', 'productId', 'region'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            // Prepare instance data
            $instanceData = [
                'imageId' => $data['imageId'],
                'productId' => $data['productId'],
                'region' => $data['region'],
                'period' => $data['period'] ?? 1,
                'displayName' => $data['displayName'] ?? 'WHMCS Server',
                'defaultUser' => $data['defaultUser'] ?? 'admin',
            ];

            // Add SSH keys if provided
            if (!empty($data['sshKeys'])) {
                $instanceData['sshKeys'] = is_array($data['sshKeys']) ? $data['sshKeys'] : [$data['sshKeys']];
            }

            // Add cloud-init user data if provided
            if (!empty($data['userData'])) {
                $instanceData['userData'] = $data['userData'];
            }

            // Add add-ons if specified
            $addOns = [];
            if (!empty($data['privateNetworking']) && $data['privateNetworking'] === 'yes') {
                $addOns[] = ['productId' => 'private-networking'];
            }
            
            if (!empty($addOns)) {
                $instanceData['addOns'] = $addOns;
            }

            // Create instance via API
            $response = $this->apiClient->createInstance($instanceData);

            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('Invalid response from Contabo API');
            }

            $instance = $response['data'][0];

            // Store instance in local database
            $instanceId = Capsule::table('mod_contabo_instances')->insertGetId([
                'service_id' => $data['service_id'],
                'contabo_instance_id' => $instance['instanceId'],
                'name' => $instance['name'] ?? $instanceData['displayName'],
                'status' => $instance['status'] ?? 'provisioning',
                'image_id' => $instanceData['imageId'],
                'datacenter' => $instanceData['region'],
                'specs' => json_encode([
                    'productId' => $instanceData['productId'],
                    'cpu' => $instance['productId'] ?? null,
                    'ram' => $instance['productId'] ?? null,
                    'disk' => $instance['productId'] ?? null
                ]),
                'network_config' => json_encode([
                    'ipv4' => $instance['ipConfig']['v4']['ip'] ?? null,
                    'ipv6' => $instance['ipConfig']['v6']['ip'] ?? null,
                ]),
                'cloud_init_script' => $data['userData'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->logHelper->log('instance_created', [
                'service_id' => $data['service_id'],
                'contabo_instance_id' => $instance['instanceId'],
                'local_id' => $instanceId
            ]);

            return [
                'success' => true,
                'instanceId' => $instance['instanceId'],
                'localId' => $instanceId,
                'data' => $instance
            ];

        } catch (Exception $e) {
            $this->logHelper->log('instance_creation_failed', [
                'service_id' => $data['service_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get instance details
     */
    public function getInstance($instanceId, $useContaboId = true)
    {
        try {
            if ($useContaboId) {
                // Get from Contabo API
                $response = $this->apiClient->getInstance($instanceId);
                $instance = $response['data'][0] ?? null;
                
                if (!$instance) {
                    throw new Exception('Instance not found');
                }

                // Update local database
                $this->updateLocalInstance($instanceId, $instance);

                return $instance;
            } else {
                // Get from local database
                $localInstance = Capsule::table('mod_contabo_instances')
                    ->where('id', $instanceId)
                    ->first();

                if (!$localInstance) {
                    throw new Exception('Instance not found in local database');
                }

                // Get fresh data from API
                return $this->getInstance($localInstance->contabo_instance_id, true);
            }

        } catch (Exception $e) {
            $this->logHelper->log('instance_fetch_failed', [
                'instance_id' => $instanceId,
                'use_contabo_id' => $useContaboId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update instance configuration
     */
    public function updateInstance($instanceId, $data)
    {
        try {
            $updateData = [];

            // Only include fields that can be updated
            $allowedFields = ['displayName', 'defaultUser'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (empty($updateData)) {
                throw new Exception('No valid fields to update');
            }

            $response = $this->apiClient->updateInstance($instanceId, $updateData);
            
            // Update local database
            if (isset($response['data']) && !empty($response['data'])) {
                $instance = $response['data'][0];
                $this->updateLocalInstance($instanceId, $instance);
            }

            $this->logHelper->log('instance_updated', [
                'instance_id' => $instanceId,
                'updated_fields' => array_keys($updateData)
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('instance_update_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Manage instance (start, stop, restart, etc.)
     */
    public function manageInstance($instanceId, $action)
    {
        try {
            $response = null;
            
            switch ($action) {
                case 'start':
                    $response = $this->apiClient->startInstance($instanceId);
                    break;
                    
                case 'stop':
                    $response = $this->apiClient->stopInstance($instanceId);
                    break;
                    
                case 'restart':
                    $response = $this->apiClient->restartInstance($instanceId);
                    break;
                    
                case 'shutdown':
                    $response = $this->apiClient->shutdownInstance($instanceId);
                    break;
                    
                case 'rescue':
                    $response = $this->apiClient->rescueInstance($instanceId);
                    break;
                    
                case 'reset_password':
                    $response = $this->apiClient->resetInstancePassword($instanceId);
                    break;
                    
                default:
                    throw new Exception("Unsupported action: {$action}");
            }

            // Update local instance status if response contains data
            if (isset($response['data']) && !empty($response['data'])) {
                $instance = $response['data'][0];
                $this->updateLocalInstance($instanceId, $instance);
            }

            $this->logHelper->log('instance_action_executed', [
                'instance_id' => $instanceId,
                'action' => $action
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('instance_action_failed', [
                'instance_id' => $instanceId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Upgrade instance
     */
    public function upgradeInstance($instanceId, $newProductId)
    {
        try {
            $upgradeData = [
                'productId' => $newProductId
            ];

            $response = $this->apiClient->upgradeInstance($instanceId, $upgradeData);

            // Update local database
            if (isset($response['data']) && !empty($response['data'])) {
                $instance = $response['data'][0];
                $this->updateLocalInstance($instanceId, $instance);
            }

            $this->logHelper->log('instance_upgraded', [
                'instance_id' => $instanceId,
                'new_product_id' => $newProductId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('instance_upgrade_failed', [
                'instance_id' => $instanceId,
                'new_product_id' => $newProductId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cancel/Delete instance
     */
    public function cancelInstance($instanceId, $deleteImmediately = false)
    {
        try {
            if ($deleteImmediately) {
                $response = $this->apiClient->deleteInstance($instanceId);
                
                // Remove from local database
                Capsule::table('mod_contabo_instances')
                    ->where('contabo_instance_id', $instanceId)
                    ->delete();
                    
                $this->logHelper->log('instance_deleted', ['instance_id' => $instanceId]);
            } else {
                $response = $this->apiClient->cancelInstance($instanceId);
                
                // Update status in local database
                Capsule::table('mod_contabo_instances')
                    ->where('contabo_instance_id', $instanceId)
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => now()
                    ]);
                    
                $this->logHelper->log('instance_cancelled', ['instance_id' => $instanceId]);
            }

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('instance_cancellation_failed', [
                'instance_id' => $instanceId,
                'delete_immediately' => $deleteImmediately,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create snapshot
     */
    public function createSnapshot($instanceId, $name, $description = '')
    {
        try {
            $snapshotData = [
                'name' => $name,
                'description' => $description
            ];

            $response = $this->apiClient->createSnapshot($instanceId, $snapshotData);

            $this->logHelper->log('snapshot_created', [
                'instance_id' => $instanceId,
                'snapshot_name' => $name
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('snapshot_creation_failed', [
                'instance_id' => $instanceId,
                'snapshot_name' => $name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get instance snapshots
     */
    public function getSnapshots($instanceId)
    {
        try {
            $response = $this->apiClient->getInstanceSnapshots($instanceId);
            return $response['data'] ?? [];

        } catch (Exception $e) {
            $this->logHelper->log('snapshots_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Rollback to snapshot
     */
    public function rollbackSnapshot($instanceId, $snapshotId)
    {
        try {
            $response = $this->apiClient->rollbackSnapshot($instanceId, $snapshotId);

            $this->logHelper->log('snapshot_rollback', [
                'instance_id' => $instanceId,
                'snapshot_id' => $snapshotId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('snapshot_rollback_failed', [
                'instance_id' => $instanceId,
                'snapshot_id' => $snapshotId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete snapshot
     */
    public function deleteSnapshot($instanceId, $snapshotId)
    {
        try {
            $response = $this->apiClient->deleteSnapshot($instanceId, $snapshotId);

            $this->logHelper->log('snapshot_deleted', [
                'instance_id' => $instanceId,
                'snapshot_id' => $snapshotId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('snapshot_deletion_failed', [
                'instance_id' => $instanceId,
                'snapshot_id' => $snapshotId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available products/plans
     */
    public function getAvailableProducts()
    {
        // This would typically be cached or configured in the module
        // For now, return common Contabo VPS plans
        return [
            'vps-s' => [
                'name' => 'VPS S',
                'cpu' => 1,
                'ram' => '4 GB',
                'disk' => '50 GB NVMe',
                'traffic' => '32 TB',
                'price' => 4.99
            ],
            'vps-m' => [
                'name' => 'VPS M',
                'cpu' => 2,
                'ram' => '8 GB',
                'disk' => '100 GB NVMe',
                'traffic' => '32 TB',
                'price' => 8.99
            ],
            'vps-l' => [
                'name' => 'VPS L',
                'cpu' => 4,
                'ram' => '16 GB',
                'disk' => '200 GB NVMe',
                'traffic' => '32 TB',
                'price' => 16.99
            ],
            'vps-xl' => [
                'name' => 'VPS XL',
                'cpu' => 6,
                'ram' => '32 GB',
                'disk' => '400 GB NVMe',
                'traffic' => '32 TB',
                'price' => 29.99
            ]
        ];
    }

    /**
     * Get available operating system images
     */
    public function getAvailableImages()
    {
        try {
            $response = $this->apiClient->getImages(1, 100, ['standardImage' => true]);
            return $response['data'] ?? [];

        } catch (Exception $e) {
            $this->logHelper->log('images_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            
            // Return default images if API fails
            return $this->getDefaultImages();
        }
    }

    /**
     * Process cloud-init template
     */
    public function processCloudInitTemplate($templateId, $variables = [])
    {
        try {
            $template = Capsule::table('mod_contabo_cloud_init_templates')
                ->where('id', $templateId)
                ->first();

            if (!$template) {
                throw new Exception('Cloud-init template not found');
            }

            $content = $template->template_content;
            
            // Replace variables in template
            foreach ($variables as $key => $value) {
                $content = str_replace("{{${key}}}", $value, $content);
            }

            return $content;

        } catch (Exception $e) {
            $this->logHelper->log('cloud_init_processing_failed', [
                'template_id' => $templateId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get service instances for a WHMCS service
     */
    public function getServiceInstances($serviceId)
    {
        return Capsule::table('mod_contabo_instances')
            ->where('service_id', $serviceId)
            ->get();
    }

    /**
     * Update local instance data
     */
    private function updateLocalInstance($contaboInstanceId, $instanceData)
    {
        try {
            $updateData = [
                'name' => $instanceData['name'] ?? $instanceData['displayName'] ?? '',
                'status' => $instanceData['status'] ?? '',
                'updated_at' => now()
            ];

            // Update network config if available
            if (isset($instanceData['ipConfig'])) {
                $updateData['network_config'] = json_encode([
                    'ipv4' => $instanceData['ipConfig']['v4']['ip'] ?? null,
                    'ipv6' => $instanceData['ipConfig']['v6']['ip'] ?? null,
                ]);
            }

            Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $contaboInstanceId)
                ->update($updateData);

        } catch (Exception $e) {
            $this->logHelper->log('local_instance_update_failed', [
                'contabo_instance_id' => $contaboInstanceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Default images fallback
     */
    private function getDefaultImages()
    {
        return [
            [
                'imageId' => 'ubuntu-20.04',
                'name' => 'Ubuntu 20.04 LTS',
                'description' => 'Ubuntu 20.04 LTS',
                'osType' => 'Linux',
                'standardImage' => true
            ],
            [
                'imageId' => 'ubuntu-22.04',
                'name' => 'Ubuntu 22.04 LTS',
                'description' => 'Ubuntu 22.04 LTS',
                'osType' => 'Linux',
                'standardImage' => true
            ],
            [
                'imageId' => 'centos-8',
                'name' => 'CentOS 8',
                'description' => 'CentOS 8 Stream',
                'osType' => 'Linux',
                'standardImage' => true
            ],
            [
                'imageId' => 'debian-11',
                'name' => 'Debian 11',
                'description' => 'Debian 11 Bullseye',
                'osType' => 'Linux',
                'standardImage' => true
            ]
        ];
    }
}
