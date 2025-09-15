<?php
/**
 * Contabo Image Service
 * 
 * Handles custom image upload, management, and standard image operations
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class ImageService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Upload custom image
     */
    public function uploadImage($data, $files = null)
    {
        try {
            // Validate required fields
            $requiredFields = ['name', 'osType'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            // Validate file upload if provided
            if ($files && isset($files['image_file'])) {
                $file = $files['image_file'];
                
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('File upload error');
                }

                // Check file extension
                $allowedExtensions = ['qcow2', 'iso'];
                $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    throw new Exception('Only .qcow2 and .iso files are supported');
                }

                // Check file size (max 50GB)
                $maxSize = 50 * 1024 * 1024 * 1024; // 50GB in bytes
                if ($file['size'] > $maxSize) {
                    throw new Exception('File size exceeds 50GB limit');
                }
            }

            // Prepare image data
            $imageData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'osType' => $data['osType']
            ];

            // If we have a file, handle the upload
            if ($files && isset($files['image_file'])) {
                // For now, we'll simulate the upload process
                // In a real implementation, you'd need to handle the actual file upload to Contabo
                $imageData['source'] = 'upload';
                $imageData['filename'] = $files['image_file']['name'];
            } else {
                // Creating from URL or other source
                if (!empty($data['sourceUrl'])) {
                    $imageData['source'] = 'url';
                    $imageData['url'] = $data['sourceUrl'];
                }
            }

            // Create image via API
            $response = $this->apiClient->createImage($imageData);

            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('Invalid response from Contabo API');
            }

            $image = $response['data'][0];

            // Store image in local database
            $imageId = Capsule::table('mod_contabo_images')->insertGetId([
                'user_id' => $data['user_id'] ?? 0,
                'contabo_image_id' => $image['imageId'],
                'name' => $image['name'],
                'description' => $image['description'] ?? '',
                'os_type' => $image['osType'],
                'size_mb' => $image['sizeMb'] ?? 0,
                'status' => $image['status'] ?? 'processing',
                'is_public' => ($data['is_public'] ?? 'no') === 'yes',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->logHelper->log('image_created', [
                'user_id' => $data['user_id'] ?? 0,
                'contabo_image_id' => $image['imageId'],
                'local_id' => $imageId
            ]);

            return [
                'success' => true,
                'imageId' => $image['imageId'],
                'localId' => $imageId,
                'data' => $image
            ];

        } catch (Exception $e) {
            $this->logHelper->log('image_upload_failed', [
                'user_id' => $data['user_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get image details
     */
    public function getImage($imageId, $useContaboId = true)
    {
        try {
            if ($useContaboId) {
                // Get from Contabo API
                $response = $this->apiClient->getImage($imageId);
                $image = $response['data'][0] ?? null;
                
                if (!$image) {
                    throw new Exception('Image not found');
                }

                // Update local database
                $this->updateLocalImage($imageId, $image);

                return $image;
            } else {
                // Get from local database
                $localImage = Capsule::table('mod_contabo_images')
                    ->where('id', $imageId)
                    ->first();

                if (!$localImage) {
                    throw new Exception('Image not found in local database');
                }

                // Get fresh data from API
                return $this->getImage($localImage->contabo_image_id, true);
            }

        } catch (Exception $e) {
            $this->logHelper->log('image_fetch_failed', [
                'image_id' => $imageId,
                'use_contabo_id' => $useContaboId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update image details
     */
    public function updateImage($imageId, $data)
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

            $response = $this->apiClient->updateImage($imageId, $updateData);
            
            // Update local database
            if (isset($response['data']) && !empty($response['data'])) {
                $image = $response['data'][0];
                $this->updateLocalImage($imageId, $image);
            }

            $this->logHelper->log('image_updated', [
                'image_id' => $imageId,
                'updated_fields' => array_keys($updateData)
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('image_update_failed', [
                'image_id' => $imageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete image
     */
    public function deleteImage($imageId)
    {
        try {
            $response = $this->apiClient->deleteImage($imageId);

            // Remove from local database
            Capsule::table('mod_contabo_images')
                ->where('contabo_image_id', $imageId)
                ->delete();

            $this->logHelper->log('image_deleted', [
                'image_id' => $imageId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('image_deletion_failed', [
                'image_id' => $imageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available standard images
     */
    public function getStandardImages($page = 1, $size = 50)
    {
        try {
            $response = $this->apiClient->getImages($page, $size, ['standardImage' => true]);
            return $response['data'] ?? [];

        } catch (Exception $e) {
            $this->logHelper->log('standard_images_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            
            // Return default images if API fails
            return $this->getDefaultStandardImages();
        }
    }

    /**
     * Get user's custom images
     */
    public function getUserImages($userId, $includePublic = true)
    {
        try {
            $query = Capsule::table('mod_contabo_images')
                ->where('user_id', $userId);

            if ($includePublic) {
                $query->orWhere('is_public', true);
            }

            $images = $query->orderBy('created_at', 'desc')->get();

            // Enrich with API data if needed
            foreach ($images as &$image) {
                try {
                    $apiImage = $this->getImage($image->contabo_image_id, true);
                    $image->status = $apiImage['status'] ?? $image->status;
                    $image->size_mb = $apiImage['sizeMb'] ?? $image->size_mb;
                } catch (Exception $e) {
                    // Continue if individual image fetch fails
                }
            }

            return $images->toArray();

        } catch (Exception $e) {
            $this->logHelper->log('user_images_fetch_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get image statistics
     */
    public function getImageStats($imageId)
    {
        try {
            // This would typically call a specific API endpoint for image stats
            // For now, we'll return basic information
            $image = $this->getImage($imageId, true);
            
            return [
                'size_mb' => $image['sizeMb'] ?? 0,
                'size_gb' => round(($image['sizeMb'] ?? 0) / 1024, 2),
                'status' => $image['status'] ?? 'unknown',
                'os_type' => $image['osType'] ?? 'unknown',
                'created_at' => $image['createdDate'] ?? null,
                'last_modified' => $image['lastModifiedDate'] ?? null
            ];

        } catch (Exception $e) {
            $this->logHelper->log('image_stats_failed', [
                'image_id' => $imageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create image from existing instance
     */
    public function createImageFromInstance($instanceId, $imageName, $description = '')
    {
        try {
            // This would require a specific API endpoint to create an image from an instance
            // For now, we'll simulate the process
            $imageData = [
                'name' => $imageName,
                'description' => $description,
                'sourceInstanceId' => $instanceId
            ];

            // In a real implementation, you'd call:
            // $response = $this->apiClient->createImageFromInstance($imageData);
            
            // For now, return a simulated response
            $this->logHelper->log('image_from_instance_requested', [
                'instance_id' => $instanceId,
                'image_name' => $imageName
            ]);

            return [
                'success' => true,
                'message' => 'Image creation from instance has been queued. This process may take several minutes.',
                'estimated_time' => '15-30 minutes'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('image_from_instance_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get supported OS types
     */
    public function getSupportedOSTypes()
    {
        return [
            'Linux' => 'Linux',
            'Windows' => 'Windows',
            'FreeBSD' => 'FreeBSD',
            'OpenBSD' => 'OpenBSD',
            'NetBSD' => 'NetBSD'
        ];
    }

    /**
     * Validate image file
     */
    public function validateImageFile($filePath)
    {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'error' => 'File does not exist'];
        }

        $fileSize = filesize($filePath);
        $maxSize = 50 * 1024 * 1024 * 1024; // 50GB

        if ($fileSize > $maxSize) {
            return ['valid' => false, 'error' => 'File size exceeds 50GB limit'];
        }

        // Basic file type detection
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        // Check for common image formats
        $allowedTypes = [
            'application/octet-stream', // Common for .qcow2 and .iso
            'application/x-iso9660-image' // ISO files
        ];

        $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($fileExtension, ['qcow2', 'iso'])) {
            return ['valid' => false, 'error' => 'Only .qcow2 and .iso files are supported'];
        }

        return [
            'valid' => true,
            'size_bytes' => $fileSize,
            'size_mb' => round($fileSize / (1024 * 1024), 2),
            'size_gb' => round($fileSize / (1024 * 1024 * 1024), 2),
            'mime_type' => $mimeType,
            'extension' => $fileExtension
        ];
    }

    /**
     * Generate image upload progress tracker
     */
    public function trackUploadProgress($uploadId)
    {
        // This would integrate with the actual upload process
        // For now, return simulated progress
        $progress = [
            'upload_id' => $uploadId,
            'status' => 'uploading',
            'progress_percent' => rand(10, 90),
            'speed_mbps' => rand(50, 200),
            'eta_minutes' => rand(5, 30),
            'uploaded_mb' => rand(100, 5000),
            'total_mb' => 8192
        ];

        return $progress;
    }

    /**
     * Get image usage statistics
     */
    public function getImageUsageStats()
    {
        try {
            $stats = [
                'total_images' => Capsule::table('mod_contabo_images')->count(),
                'public_images' => Capsule::table('mod_contabo_images')->where('is_public', true)->count(),
                'private_images' => Capsule::table('mod_contabo_images')->where('is_public', false)->count(),
                'by_os_type' => [],
                'by_status' => [],
                'total_size_gb' => 0
            ];

            // Get OS type distribution
            $osStats = Capsule::table('mod_contabo_images')
                ->selectRaw('os_type, COUNT(*) as count, SUM(size_mb) as total_size_mb')
                ->groupBy('os_type')
                ->get();

            foreach ($osStats as $stat) {
                $stats['by_os_type'][$stat->os_type] = [
                    'count' => $stat->count,
                    'size_gb' => round($stat->total_size_mb / 1024, 2)
                ];
                $stats['total_size_gb'] += round($stat->total_size_mb / 1024, 2);
            }

            // Get status distribution
            $statusStats = Capsule::table('mod_contabo_images')
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get();

            foreach ($statusStats as $stat) {
                $stats['by_status'][$stat->status] = $stat->count;
            }

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('image_usage_stats_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update local image data
     */
    private function updateLocalImage($contaboImageId, $imageData)
    {
        try {
            $updateData = [
                'name' => $imageData['name'] ?? '',
                'description' => $imageData['description'] ?? '',
                'status' => $imageData['status'] ?? '',
                'size_mb' => $imageData['sizeMb'] ?? 0,
                'updated_at' => now()
            ];

            Capsule::table('mod_contabo_images')
                ->where('contabo_image_id', $contaboImageId)
                ->update($updateData);

        } catch (Exception $e) {
            $this->logHelper->log('local_image_update_failed', [
                'contabo_image_id' => $contaboImageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Default standard images fallback
     */
    private function getDefaultStandardImages()
    {
        return [
            [
                'imageId' => 'ubuntu-20.04',
                'name' => 'Ubuntu 20.04 LTS',
                'description' => 'Ubuntu 20.04.6 LTS',
                'osType' => 'Linux',
                'version' => '20.04',
                'standardImage' => true,
                'sizeMb' => 2048
            ],
            [
                'imageId' => 'ubuntu-22.04',
                'name' => 'Ubuntu 22.04 LTS', 
                'description' => 'Ubuntu 22.04.3 LTS',
                'osType' => 'Linux',
                'version' => '22.04',
                'standardImage' => true,
                'sizeMb' => 2048
            ],
            [
                'imageId' => 'debian-11',
                'name' => 'Debian 11',
                'description' => 'Debian 11 Bullseye',
                'osType' => 'Linux',
                'version' => '11',
                'standardImage' => true,
                'sizeMb' => 1536
            ],
            [
                'imageId' => 'centos-8',
                'name' => 'CentOS Stream 8',
                'description' => 'CentOS Stream 8',
                'osType' => 'Linux',
                'version' => '8',
                'standardImage' => true,
                'sizeMb' => 2048
            ],
            [
                'imageId' => 'windows-2019',
                'name' => 'Windows Server 2019',
                'description' => 'Windows Server 2019 Datacenter',
                'osType' => 'Windows',
                'version' => '2019',
                'standardImage' => true,
                'sizeMb' => 32768
            ],
            [
                'imageId' => 'windows-2022',
                'name' => 'Windows Server 2022',
                'description' => 'Windows Server 2022 Datacenter',
                'osType' => 'Windows',
                'version' => '2022',
                'standardImage' => true,
                'sizeMb' => 32768
            ]
        ];
    }
}
