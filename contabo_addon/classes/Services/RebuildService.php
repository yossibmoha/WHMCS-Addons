<?php
/**
 * VPS Server Rebuild Service
 * 
 * Handles server rebuilding/reinstallation with OS selection and custom images
 */

namespace ContaboAddon\Services;

use Exception;

// Import WHMCS classes when available
if (class_exists('\WHMCS\Database\Capsule')) {
    // WHMCS classes are available
}

class RebuildService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get available operating systems for rebuild
     */
    public function getAvailableOperatingSystems()
    {
        try {
            $response = $this->apiClient->makeRequest('GET', '/v1/compute/images?standardImage=true&size=100');
            $images = $response['data'] ?? [];

            $operatingSystems = [];
            
            foreach ($images as $image) {
                $operatingSystems[] = [
                    'id' => $image['imageId'],
                    'name' => $image['name'],
                    'description' => $image['description'] ?? '',
                    'os_type' => $image['osType'] ?? 'linux',
                    'version' => $image['version'] ?? '',
                    'category' => $this->categorizeOS($image['name']),
                    'recommended' => $this->isRecommended($image['name'])
                ];
            }

            // Sort by category and name
            usort($operatingSystems, function($a, $b) {
                if ($a['category'] === $b['category']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $this->getCategoryOrder($a['category']) - $this->getCategoryOrder($b['category']);
            });

            return $operatingSystems;

        } catch (Exception $e) {
            $this->logHelper->log('os_list_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get custom images available for rebuild
     */
    public function getCustomImages($userId = null)
    {
        try {
            $response = $this->apiClient->makeRequest('GET', '/v1/compute/images?standardImage=false&size=100');
            $images = $response['data'] ?? [];

            $customImages = [];
            
            foreach ($images as $image) {
                $customImages[] = [
                    'id' => $image['imageId'],
                    'name' => $image['name'],
                    'description' => $image['description'] ?? '',
                    'os_type' => $image['osType'] ?? 'linux',
                    'version' => $image['version'] ?? '',
                    'size_mb' => $image['sizeMb'] ?? 0,
                    'created_date' => $image['createdDate'] ?? null,
                    'is_public' => $image['standardImage'] ?? false
                ];
            }

            return $customImages;

        } catch (Exception $e) {
            $this->logHelper->log('custom_images_failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Rebuild instance with new OS or custom image
     */
    public function rebuildInstance($instanceId, $rebuildData, $isAdmin = false)
    {
        try {
            // Validate rebuild data
            if (empty($rebuildData['imageId'])) {
                throw new Exception('Image ID is required for rebuild');
            }

            // Prepare API request data
            $apiData = [
                'imageId' => $rebuildData['imageId']
            ];

            // Add optional parameters
            if (!empty($rebuildData['sshKeys'])) {
                $apiData['sshKeys'] = $rebuildData['sshKeys'];
            }

            if (!empty($rebuildData['userData'])) {
                $apiData['userData'] = $rebuildData['userData'];
            }

            // Make rebuild request
            $response = $this->apiClient->makeRequest('POST', "/v1/compute/instances/{$instanceId}/reinstall", $apiData);

            // Log the rebuild operation
            $this->logRebuildOperation($instanceId, $rebuildData, $isAdmin);

            // Update local database
            $this->updateLocalInstanceAfterRebuild($instanceId, $rebuildData);

            return [
                'success' => true,
                'message' => 'Server rebuild initiated successfully. The process may take 5-15 minutes.',
                'estimated_completion' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                'rebuild_id' => $response['data'][0]['instanceId'] ?? $instanceId
            ];

        } catch (Exception $e) {
            $this->logHelper->log('rebuild_failed', [
                'instance_id' => $instanceId,
                'image_id' => $rebuildData['imageId'] ?? null,
                'is_admin' => $isAdmin,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get rebuild status
     */
    public function getRebuildStatus($instanceId)
    {
        try {
            // Get current instance status
            $response = $this->apiClient->makeRequest('GET', "/v1/compute/instances/{$instanceId}");
            $instance = $response['data'][0] ?? null;

            if (!$instance) {
                throw new Exception('Instance not found');
            }

            $status = $instance['status'] ?? 'unknown';
            
            return [
                'instance_id' => $instanceId,
                'status' => $status,
                'status_description' => $this->getStatusDescription($status),
                'progress' => $this->calculateRebuildProgress($status),
                'estimated_completion' => $this->estimateCompletionTime($status),
                'can_access' => in_array($status, ['running', 'stopped']),
                'last_updated' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logHelper->log('rebuild_status_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get rebuild history for instance
     */
    public function getRebuildHistory($instanceId)
    {
        try {
            // Get from activity logs
            $rebuilds = \WHMCS\Database\Capsule::table('mod_contabo_api_logs')
                ->where('action', 'instance_rebuild')
                ->where('request_data->instance_id', $instanceId)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $history = [];
            foreach ($rebuilds as $rebuild) {
                $requestData = json_decode($rebuild->request_data, true);
                $responseData = json_decode($rebuild->response_data, true);

                $history[] = [
                    'date' => $rebuild->created_at,
                    'image_name' => $requestData['image_name'] ?? 'Unknown',
                    'initiated_by' => $requestData['is_admin'] ? 'Administrator' : 'Customer',
                    'status' => $rebuild->response_code === 200 ? 'Success' : 'Failed',
                    'user_id' => $rebuild->user_id
                ];
            }

            return $history;

        } catch (Exception $e) {
            $this->logHelper->log('rebuild_history_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Validate rebuild permissions
     */
    public function canUserRebuild($instanceId, $userId, $isAdmin = false)
    {
        if ($isAdmin) {
            return true;
        }

        try {
            // Check if user owns the service
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->leftJoin('tblhosting', 'mod_contabo_instances.service_id', '=', 'tblhosting.id')
                ->where('mod_contabo_instances.contabo_instance_id', $instanceId)
                ->where('tblhosting.userid', $userId)
                ->first();

            return $instance !== null;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get rebuild presets (common configurations)
     */
    public function getRebuildPresets()
    {
        return [
            'fresh_start' => [
                'name' => 'Fresh Start',
                'description' => 'Clean server with latest Ubuntu LTS',
                'image_filter' => 'ubuntu.*22.04.*lts',
                'recommended_for' => ['Web hosting', 'Development', 'General purpose']
            ],
            'web_server' => [
                'name' => 'Web Server Ready', 
                'description' => 'Ubuntu with web server essentials pre-installed',
                'image_filter' => 'ubuntu.*22.04.*lts',
                'cloud_init_template' => 'web_server_basic',
                'recommended_for' => ['WordPress', 'Web applications', 'E-commerce']
            ],
            'development' => [
                'name' => 'Development Environment',
                'description' => 'Development tools and popular languages pre-installed',
                'image_filter' => 'ubuntu.*22.04.*lts',
                'cloud_init_template' => 'development_stack',
                'recommended_for' => ['Coding', 'CI/CD', 'Testing']
            ],
            'docker_ready' => [
                'name' => 'Docker Ready',
                'description' => 'Docker and Docker Compose pre-installed and configured',
                'image_filter' => 'ubuntu.*22.04.*lts',
                'cloud_init_template' => 'docker_installation',
                'recommended_for' => ['Containers', 'Microservices', 'Application deployment']
            ]
        ];
    }

    /**
     * Generate cloud-init script for rebuild
     */
    public function generateRebuildCloudInit($presetId, $customOptions = [])
    {
        $presets = $this->getRebuildPresets();
        
        if (!isset($presets[$presetId])) {
            return null;
        }

        $preset = $presets[$presetId];
        $cloudInitTemplate = $preset['cloud_init_template'] ?? null;

        if (!$cloudInitTemplate) {
            return null;
        }

        // Get template from database
        $template = \WHMCS\Database\Capsule::table('mod_contabo_cloud_init_templates')
            ->where('name', $cloudInitTemplate)
            ->first();

        if (!$template) {
            return null;
        }

        $cloudInit = $template->template_content;

        // Replace variables with custom options
        foreach ($customOptions as $key => $value) {
            $cloudInit = str_replace("{{" . strtoupper($key) . "}}", $value, $cloudInit);
        }

        return $cloudInit;
    }

    /**
     * Categorize operating system
     */
    private function categorizeOS($osName)
    {
        $name = strtolower($osName);
        
        if (strpos($name, 'ubuntu') !== false) return 'Ubuntu';
        if (strpos($name, 'debian') !== false) return 'Debian';
        if (strpos($name, 'centos') !== false) return 'CentOS';
        if (strpos($name, 'fedora') !== false) return 'Fedora';
        if (strpos($name, 'windows') !== false) return 'Windows';
        if (strpos($name, 'freebsd') !== false) return 'FreeBSD';
        
        return 'Other';
    }

    /**
     * Check if OS is recommended
     */
    private function isRecommended($osName)
    {
        $recommended = [
            'ubuntu 22.04 lts',
            'ubuntu 20.04 lts',
            'debian 12',
            'centos 9'
        ];

        $name = strtolower($osName);
        
        foreach ($recommended as $rec) {
            if (strpos($name, $rec) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get category display order
     */
    private function getCategoryOrder($category)
    {
        $order = [
            'Ubuntu' => 1,
            'Debian' => 2,
            'CentOS' => 3,
            'Fedora' => 4,
            'Windows' => 5,
            'FreeBSD' => 6,
            'Other' => 7
        ];

        return $order[$category] ?? 99;
    }

    /**
     * Get human-readable status description
     */
    private function getStatusDescription($status)
    {
        $descriptions = [
            'running' => 'Server is running and accessible',
            'stopped' => 'Server is stopped',
            'provisioning' => 'Server is being rebuilt - please wait...',
            'installing' => 'Operating system is being installed',
            'error' => 'An error occurred during rebuild',
            'unknown' => 'Status unknown'
        ];

        return $descriptions[$status] ?? 'Status unknown';
    }

    /**
     * Calculate rebuild progress percentage
     */
    private function calculateRebuildProgress($status)
    {
        $progress = [
            'provisioning' => 25,
            'installing' => 50,
            'configuring' => 75,
            'running' => 100,
            'error' => 0
        ];

        return $progress[$status] ?? 0;
    }

    /**
     * Estimate completion time
     */
    private function estimateCompletionTime($status)
    {
        if ($status === 'running') {
            return 'Completed';
        }

        if ($status === 'error') {
            return 'Failed';
        }

        // Estimate remaining time based on status
        $remainingMinutes = [
            'provisioning' => 10,
            'installing' => 8,
            'configuring' => 3
        ];

        $minutes = $remainingMinutes[$status] ?? 15;
        return date('H:i', strtotime("+{$minutes} minutes"));
    }

    /**
     * Log rebuild operation
     */
    private function logRebuildOperation($instanceId, $rebuildData, $isAdmin)
    {
        $this->logHelper->log('instance_rebuild', [
            'instance_id' => $instanceId,
            'image_id' => $rebuildData['imageId'],
            'image_name' => $rebuildData['imageName'] ?? 'Unknown',
            'is_admin' => $isAdmin,
            'user_id' => $isAdmin ? ($_SESSION['adminid'] ?? null) : null,
            'has_ssh_keys' => !empty($rebuildData['sshKeys']),
            'has_cloud_init' => !empty($rebuildData['userData']),
            'initiated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Update local instance record after rebuild
     */
    private function updateLocalInstanceAfterRebuild($instanceId, $rebuildData)
    {
        try {
            \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->update([
                    'image_id' => $rebuildData['imageId'],
                    'status' => 'provisioning',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

        } catch (Exception $e) {
            // Log but don't fail the rebuild
            $this->logHelper->log('local_update_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
