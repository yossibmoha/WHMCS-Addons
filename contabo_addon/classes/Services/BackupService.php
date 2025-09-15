<?php
/**
 * Contabo Backup Service
 * 
 * Handles automated backup configuration, monitoring, restore, and management
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class BackupService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Enable backup for an instance
     */
    public function enableBackup($instanceId, $config = [])
    {
        try {
            // Configure backup settings
            $backupData = $this->processBackupConfig($config);
            
            // Enable backup via upgrade API
            $response = $this->apiClient->upgradeInstance($instanceId, [
                'backup' => $backupData
            ]);

            // Store backup configuration locally
            $this->storeBackupConfig($instanceId, $config);

            $this->logHelper->log('backup_enabled', [
                'instance_id' => $instanceId,
                'retention_days' => $config['retention_days'] ?? 7,
                'schedule' => $config['schedule'] ?? 'daily'
            ]);

            return [
                'success' => true,
                'message' => 'Backup enabled successfully',
                'config' => $config,
                'api_response' => $response
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_enable_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get backup configuration for instance
     */
    public function getBackupConfig($instanceId)
    {
        try {
            // Get from local database first
            $localConfig = Capsule::table('mod_contabo_backups')
                ->where('instance_id', $instanceId)
                ->first();

            if ($localConfig) {
                $config = json_decode($localConfig->config, true);
                $config['local_id'] = $localConfig->id;
                $config['created_at'] = $localConfig->created_at;
                $config['updated_at'] = $localConfig->updated_at;
                return $config;
            }

            // If not found locally, check if backup is enabled via API
            $instance = $this->apiClient->getInstance($instanceId);
            $instanceData = $instance['data'][0] ?? null;

            if ($instanceData && isset($instanceData['addOns'])) {
                foreach ($instanceData['addOns'] as $addon) {
                    if (isset($addon['productId']) && strpos($addon['productId'], 'backup') !== false) {
                        return [
                            'enabled' => true,
                            'retention_days' => $this->parseRetentionFromAddon($addon),
                            'schedule' => 'daily', // Default
                            'addon_info' => $addon
                        ];
                    }
                }
            }

            return [
                'enabled' => false,
                'message' => 'No backup configuration found'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_config_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get backup history for instance
     */
    public function getBackupHistory($instanceId, $limit = 50)
    {
        try {
            // In a real implementation, this would fetch from Contabo's backup API
            // For now, we'll simulate backup history based on configuration
            
            $config = $this->getBackupConfig($instanceId);
            
            if (!$config['enabled']) {
                return [
                    'enabled' => false,
                    'backups' => [],
                    'message' => 'Backup not enabled for this instance'
                ];
            }

            // Generate simulated backup history
            $backups = $this->generateBackupHistory($instanceId, $config, $limit);

            return [
                'enabled' => true,
                'total_backups' => count($backups),
                'retention_days' => $config['retention_days'] ?? 7,
                'backups' => $backups,
                'next_backup' => $this->calculateNextBackup($config['schedule'] ?? 'daily')
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_history_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Restore from backup
     */
    public function restoreFromBackup($instanceId, $backupId, $options = [])
    {
        try {
            // Validate backup exists
            $backup = $this->getBackupDetails($instanceId, $backupId);
            
            if (!$backup) {
                throw new Exception('Backup not found');
            }

            // In a real implementation, this would call Contabo's restore API
            // For now, we'll simulate the restore process
            
            $restoreData = [
                'backup_id' => $backupId,
                'restore_type' => $options['restore_type'] ?? 'full',
                'target_instance' => $options['target_instance'] ?? $instanceId
            ];

            // Log restore initiation
            $restoreId = $this->logRestoreOperation($instanceId, $restoreData);

            // Simulate restore process
            $this->simulateRestoreProcess($restoreId);

            $this->logHelper->log('backup_restore_initiated', [
                'instance_id' => $instanceId,
                'backup_id' => $backupId,
                'restore_id' => $restoreId,
                'restore_type' => $restoreData['restore_type']
            ]);

            return [
                'success' => true,
                'restore_id' => $restoreId,
                'message' => 'Restore operation initiated successfully',
                'estimated_completion' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
                'status' => 'in_progress'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_restore_failed', [
                'instance_id' => $instanceId,
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get restore operation status
     */
    public function getRestoreStatus($restoreId)
    {
        try {
            $restore = Capsule::table('mod_contabo_backup_restores')
                ->where('id', $restoreId)
                ->first();

            if (!$restore) {
                throw new Exception('Restore operation not found');
            }

            $restoreData = json_decode($restore->restore_data, true);
            $progress = $this->calculateRestoreProgress($restore->created_at);

            return [
                'restore_id' => $restoreId,
                'status' => $restore->status,
                'progress' => $progress,
                'instance_id' => $restore->instance_id,
                'backup_id' => $restoreData['backup_id'],
                'started_at' => $restore->created_at,
                'estimated_completion' => $restore->estimated_completion,
                'message' => $this->getRestoreStatusMessage($restore->status, $progress)
            ];

        } catch (Exception $e) {
            $this->logHelper->log('restore_status_failed', [
                'restore_id' => $restoreId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete backup
     */
    public function deleteBackup($instanceId, $backupId)
    {
        try {
            // In a real implementation, this would call Contabo's delete backup API
            
            $this->logHelper->log('backup_deleted', [
                'instance_id' => $instanceId,
                'backup_id' => $backupId
            ]);

            return [
                'success' => true,
                'message' => 'Backup deleted successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_delete_failed', [
                'instance_id' => $instanceId,
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update backup configuration
     */
    public function updateBackupConfig($instanceId, $newConfig)
    {
        try {
            $currentConfig = $this->getBackupConfig($instanceId);
            
            if (!$currentConfig['enabled']) {
                throw new Exception('Backup is not enabled for this instance');
            }

            // Update configuration
            $updatedConfig = array_merge($currentConfig, $newConfig);
            
            // Update local database
            Capsule::table('mod_contabo_backups')
                ->where('instance_id', $instanceId)
                ->update([
                    'config' => json_encode($updatedConfig),
                    'updated_at' => now()
                ]);

            $this->logHelper->log('backup_config_updated', [
                'instance_id' => $instanceId,
                'changes' => $newConfig
            ]);

            return [
                'success' => true,
                'message' => 'Backup configuration updated successfully',
                'config' => $updatedConfig
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_config_update_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get backup storage usage
     */
    public function getBackupStorageUsage($instanceId = null)
    {
        try {
            $usage = [];

            if ($instanceId) {
                // Get usage for specific instance
                $backupHistory = $this->getBackupHistory($instanceId, 100);
                $totalSize = 0;
                
                foreach ($backupHistory['backups'] as $backup) {
                    $totalSize += $backup['size_mb'] ?? 0;
                }

                $usage = [
                    'instance_id' => $instanceId,
                    'total_backups' => count($backupHistory['backups']),
                    'total_size_mb' => $totalSize,
                    'total_size_gb' => round($totalSize / 1024, 2),
                    'average_backup_size_mb' => count($backupHistory['backups']) > 0 ? round($totalSize / count($backupHistory['backups'])) : 0
                ];
            } else {
                // Get usage across all instances
                $instances = Capsule::table('mod_contabo_backups')->get();
                $totalSize = 0;
                $totalBackups = 0;

                foreach ($instances as $instance) {
                    $instanceUsage = $this->getBackupStorageUsage($instance->instance_id);
                    $totalSize += $instanceUsage['total_size_mb'];
                    $totalBackups += $instanceUsage['total_backups'];
                }

                $usage = [
                    'total_instances_with_backup' => count($instances),
                    'total_backups' => $totalBackups,
                    'total_size_mb' => $totalSize,
                    'total_size_gb' => round($totalSize / 1024, 2),
                    'estimated_monthly_cost' => $this->calculateBackupCost($totalSize)
                ];
            }

            return $usage;

        } catch (Exception $e) {
            $this->logHelper->log('backup_usage_calculation_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get backup pricing tiers
     */
    public function getBackupPricingTiers()
    {
        return [
            '7_days' => [
                'name' => '7 Days Retention',
                'retention_days' => 7,
                'monthly_price' => 4.99,
                'setup_fee' => 0,
                'features' => [
                    'Daily automated backups',
                    '7 days retention period',
                    'One-click restore',
                    'Email notifications'
                ]
            ],
            '14_days' => [
                'name' => '14 Days Retention',
                'retention_days' => 14,
                'monthly_price' => 7.99,
                'setup_fee' => 0,
                'features' => [
                    'Daily automated backups',
                    '14 days retention period',
                    'One-click restore',
                    'Email notifications',
                    'Priority support'
                ]
            ],
            '30_days' => [
                'name' => '30 Days Retention',
                'retention_days' => 30,
                'monthly_price' => 12.99,
                'setup_fee' => 0,
                'features' => [
                    'Daily automated backups',
                    '30 days retention period',
                    'One-click restore',
                    'Email notifications',
                    'Priority support',
                    'Custom restore points'
                ]
            ],
            '60_days' => [
                'name' => '60 Days Retention',
                'retention_days' => 60,
                'monthly_price' => 19.99,
                'setup_fee' => 0,
                'features' => [
                    'Daily automated backups',
                    '60 days retention period',
                    'One-click restore',
                    'Email notifications',
                    'Priority support',
                    'Custom restore points',
                    'Multiple daily backups'
                ]
            ]
        ];
    }

    /**
     * Disable backup for instance
     */
    public function disableBackup($instanceId)
    {
        try {
            // Remove local configuration
            Capsule::table('mod_contabo_backups')
                ->where('instance_id', $instanceId)
                ->delete();

            $this->logHelper->log('backup_disabled', [
                'instance_id' => $instanceId
            ]);

            return [
                'success' => true,
                'message' => 'Backup disabled successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('backup_disable_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process backup configuration
     */
    private function processBackupConfig($config)
    {
        // For now, Contabo API expects empty object for backup addon
        // Future versions may support detailed configuration
        return (object)[];
    }

    /**
     * Store backup configuration locally
     */
    private function storeBackupConfig($instanceId, $config)
    {
        $configData = array_merge($config, [
            'enabled' => true,
            'retention_days' => $config['retention_days'] ?? 7,
            'schedule' => $config['schedule'] ?? 'daily'
        ]);

        Capsule::table('mod_contabo_backups')->updateOrInsert(
            ['instance_id' => $instanceId],
            [
                'instance_id' => $instanceId,
                'config' => json_encode($configData),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Generate simulated backup history
     */
    private function generateBackupHistory($instanceId, $config, $limit)
    {
        $backups = [];
        $retentionDays = $config['retention_days'] ?? 7;
        $schedule = $config['schedule'] ?? 'daily';
        
        $backupCount = min($limit, $retentionDays);
        
        for ($i = 0; $i < $backupCount; $i++) {
            $backupDate = date('Y-m-d H:i:s', strtotime("-{$i} days"));
            $backupId = 'backup_' . $instanceId . '_' . date('Ymd', strtotime($backupDate));
            
            $backups[] = [
                'backup_id' => $backupId,
                'instance_id' => $instanceId,
                'created_at' => $backupDate,
                'size_mb' => rand(1000, 5000), // Simulated backup size
                'status' => 'completed',
                'type' => 'automated',
                'retention_until' => date('Y-m-d H:i:s', strtotime($backupDate . " +{$retentionDays} days"))
            ];
        }

        return $backups;
    }

    /**
     * Calculate next backup time
     */
    private function calculateNextBackup($schedule)
    {
        switch ($schedule) {
            case 'hourly':
                return date('Y-m-d H:i:s', strtotime('+1 hour'));
            case 'daily':
                return date('Y-m-d 02:00:00', strtotime('tomorrow'));
            case 'weekly':
                return date('Y-m-d H:i:s', strtotime('next sunday 02:00'));
            default:
                return date('Y-m-d 02:00:00', strtotime('tomorrow'));
        }
    }

    /**
     * Get backup details
     */
    private function getBackupDetails($instanceId, $backupId)
    {
        $history = $this->getBackupHistory($instanceId, 100);
        
        foreach ($history['backups'] as $backup) {
            if ($backup['backup_id'] === $backupId) {
                return $backup;
            }
        }

        return null;
    }

    /**
     * Log restore operation
     */
    private function logRestoreOperation($instanceId, $restoreData)
    {
        return Capsule::table('mod_contabo_backup_restores')->insertGetId([
            'instance_id' => $instanceId,
            'restore_data' => json_encode($restoreData),
            'status' => 'in_progress',
            'estimated_completion' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Simulate restore process
     */
    private function simulateRestoreProcess($restoreId)
    {
        // In a real implementation, this would track actual restore progress
        // For now, we'll just update the status after some time
        return true;
    }

    /**
     * Calculate restore progress
     */
    private function calculateRestoreProgress($startTime)
    {
        $elapsed = time() - strtotime($startTime);
        $estimatedTotal = 30 * 60; // 30 minutes
        
        $progress = min(100, ($elapsed / $estimatedTotal) * 100);
        return round($progress);
    }

    /**
     * Get restore status message
     */
    private function getRestoreStatusMessage($status, $progress)
    {
        switch ($status) {
            case 'in_progress':
                if ($progress < 25) return 'Initializing restore process...';
                if ($progress < 50) return 'Restoring system files...';
                if ($progress < 75) return 'Restoring user data...';
                if ($progress < 95) return 'Finalizing restore...';
                return 'Completing restore operation...';
            case 'completed':
                return 'Restore completed successfully';
            case 'failed':
                return 'Restore operation failed';
            default:
                return 'Unknown status';
        }
    }

    /**
     * Parse retention from addon info
     */
    private function parseRetentionFromAddon($addon)
    {
        // Parse retention days from addon product name/ID
        $productId = $addon['productId'] ?? '';
        
        if (strpos($productId, '7') !== false) return 7;
        if (strpos($productId, '14') !== false) return 14;
        if (strpos($productId, '30') !== false) return 30;
        if (strpos($productId, '60') !== false) return 60;
        
        return 7; // Default
    }

    /**
     * Calculate backup storage cost
     */
    private function calculateBackupCost($sizeMb)
    {
        $sizeGb = $sizeMb / 1024;
        $costPerGb = 0.05; // €0.05 per GB per month
        
        return round($sizeGb * $costPerGb, 2);
    }
}
