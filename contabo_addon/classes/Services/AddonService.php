<?php
/**
 * Contabo Add-on Service
 * 
 * Handles instance upgrades and add-on management including:
 * - Additional IPv4 addresses
 * - Automated backups
 * - Firewalling
 * - Extra storage
 * - Private networking
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class AddonService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Upgrade instance with add-ons
     */
    public function upgradeInstance($instanceId, $addons)
    {
        try {
            $upgradeData = [];
            
            // Process each addon type
            foreach ($addons as $addonType => $addonConfig) {
                switch ($addonType) {
                    case 'additionalIps':
                        $upgradeData['additionalIps'] = $this->processAdditionalIpsAddon($addonConfig);
                        break;
                        
                    case 'backup':
                        $upgradeData['backup'] = $this->processBackupAddon($addonConfig);
                        break;
                        
                    case 'firewalling':
                        $upgradeData['firewalling'] = $this->processFirewallingAddon($addonConfig);
                        break;
                        
                    case 'extraStorage':
                        $upgradeData['extraStorage'] = $this->processExtraStorageAddon($addonConfig);
                        break;
                        
                    case 'privateNetworking':
                        $upgradeData['privateNetworking'] = $this->processPrivateNetworkingAddon($addonConfig);
                        break;
                }
            }

            if (empty($upgradeData)) {
                throw new Exception('No valid add-ons specified for upgrade');
            }

            // Make upgrade request
            $response = $this->apiClient->makeRequest('POST', "/v1/compute/instances/{$instanceId}/upgrade", $upgradeData);

            // Update local database with addon information
            $this->updateInstanceAddons($instanceId, $addons);

            $this->logHelper->log('instance_upgraded', [
                'instance_id' => $instanceId,
                'addons' => array_keys($addons)
            ]);

            return [
                'success' => true,
                'message' => 'Instance upgraded successfully',
                'data' => $response['data'] ?? null
            ];

        } catch (Exception $e) {
            $this->logHelper->log('instance_upgrade_failed', [
                'instance_id' => $instanceId,
                'addons' => array_keys($addons ?? []),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available add-ons and pricing
     */
    public function getAvailableAddons()
    {
        return [
            'additionalIps' => [
                'name' => 'Additional IPv4 Addresses',
                'description' => 'Add extra IPv4 addresses to your instance',
                'pricing' => [
                    '1_ip' => ['monthly' => 2.99, 'setup' => 0],
                    '5_ips' => ['monthly' => 12.99, 'setup' => 0],
                    '10_ips' => ['monthly' => 24.99, 'setup' => 0]
                ],
                'features' => [
                    'Dedicated IPv4 addresses',
                    'Instant activation',
                    'Reverse DNS support',
                    'Full control via API'
                ]
            ],
            'backup' => [
                'name' => 'Automated Backup Service',
                'description' => 'Automated daily backups with configurable retention',
                'pricing' => [
                    '7_days' => ['monthly' => 4.99, 'setup' => 0],
                    '14_days' => ['monthly' => 7.99, 'setup' => 0],
                    '30_days' => ['monthly' => 12.99, 'setup' => 0],
                    '60_days' => ['monthly' => 19.99, 'setup' => 0]
                ],
                'features' => [
                    'Daily automated backups',
                    'Configurable retention period',
                    'One-click restore',
                    'Incremental backup technology'
                ]
            ],
            'firewalling' => [
                'name' => 'Advanced Firewall',
                'description' => 'Advanced firewall with custom rules and DDoS protection',
                'pricing' => [
                    'basic' => ['monthly' => 3.99, 'setup' => 0],
                    'advanced' => ['monthly' => 7.99, 'setup' => 0],
                    'enterprise' => ['monthly' => 15.99, 'setup' => 0]
                ],
                'features' => [
                    'Custom firewall rules',
                    'DDoS protection',
                    'Port management',
                    'Traffic monitoring'
                ]
            ],
            'extraStorage' => [
                'name' => 'Additional Storage',
                'description' => 'Add extra SSD storage to your instance',
                'pricing' => [
                    '50gb' => ['monthly' => 4.99, 'setup' => 0],
                    '100gb' => ['monthly' => 8.99, 'setup' => 0],
                    '250gb' => ['monthly' => 19.99, 'setup' => 0],
                    '500gb' => ['monthly' => 34.99, 'setup' => 0]
                ],
                'features' => [
                    'High-performance NVMe SSD',
                    'Instant activation',
                    'Scalable storage',
                    'Automatic mounting'
                ]
            ],
            'privateNetworking' => [
                'name' => 'Private Networking',
                'description' => 'Connect instances via private VLAN',
                'pricing' => [
                    'single' => ['monthly' => 1.99, 'setup' => 0]
                ],
                'features' => [
                    'Private VLAN connectivity',
                    'Enhanced security',
                    'Low latency communication',
                    'No bandwidth charges'
                ]
            ]
        ];
    }

    /**
     * Get instance current add-ons
     */
    public function getInstanceAddons($instanceId)
    {
        try {
            // Get from Contabo API
            $response = $this->apiClient->getInstance($instanceId);
            $instance = $response['data'][0] ?? null;

            if (!$instance) {
                throw new Exception('Instance not found');
            }

            $addons = [];

            // Parse add-ons from instance data
            if (isset($instance['addOns']) && is_array($instance['addOns'])) {
                foreach ($instance['addOns'] as $addon) {
                    $addons[] = [
                        'type' => $addon['productId'] ?? 'unknown',
                        'name' => $addon['name'] ?? 'Unknown Add-on',
                        'status' => $addon['status'] ?? 'unknown',
                        'monthly_cost' => $addon['price'] ?? 0
                    ];
                }
            }

            // Parse additional IPs
            if (isset($instance['additionalIps']) && is_array($instance['additionalIps'])) {
                foreach ($instance['additionalIps'] as $ip) {
                    $addons[] = [
                        'type' => 'additionalIp',
                        'name' => 'Additional IPv4',
                        'value' => $ip['ip'] ?? 'N/A',
                        'status' => 'active'
                    ];
                }
            }

            return $addons;

        } catch (Exception $e) {
            $this->logHelper->log('instance_addons_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Calculate addon pricing
     */
    public function calculateAddonPricing($addons)
    {
        $availableAddons = $this->getAvailableAddons();
        $totalMonthly = 0;
        $totalSetup = 0;
        $breakdown = [];

        foreach ($addons as $addonType => $addonConfig) {
            if (!isset($availableAddons[$addonType])) {
                continue;
            }

            $addonPricing = $availableAddons[$addonType]['pricing'];
            $selectedTier = $addonConfig['tier'] ?? array_key_first($addonPricing);

            if (isset($addonPricing[$selectedTier])) {
                $monthly = $addonPricing[$selectedTier]['monthly'];
                $setup = $addonPricing[$selectedTier]['setup'];

                $totalMonthly += $monthly;
                $totalSetup += $setup;

                $breakdown[] = [
                    'addon' => $addonType,
                    'tier' => $selectedTier,
                    'monthly' => $monthly,
                    'setup' => $setup
                ];
            }
        }

        return [
            'total_monthly' => $totalMonthly,
            'total_setup' => $totalSetup,
            'breakdown' => $breakdown,
            'currency' => 'EUR'
        ];
    }

    /**
     * Process additional IPs addon configuration
     */
    private function processAdditionalIpsAddon($config)
    {
        // For now, Contabo API expects empty object
        // Future: may include IP count, reverse DNS, etc.
        return (object)[];
    }

    /**
     * Process backup addon configuration
     */
    private function processBackupAddon($config)
    {
        // For now, Contabo API expects empty object
        // Future: may include retention period, backup frequency, etc.
        return (object)[];
    }

    /**
     * Process firewalling addon configuration
     */
    private function processFirewallingAddon($config)
    {
        // For now, Contabo API expects empty object
        // Future: may include firewall rules, protection level, etc.
        return (object)[];
    }

    /**
     * Process extra storage addon configuration
     */
    private function processExtraStorageAddon($config)
    {
        // For now, Contabo API expects empty object
        // Future: may include storage size, type, mount point, etc.
        return (object)[];
    }

    /**
     * Process private networking addon configuration
     */
    private function processPrivateNetworkingAddon($config)
    {
        // For now, Contabo API expects empty object
        // Future: may include network configuration, VLAN settings, etc.
        return (object)[];
    }

    /**
     * Update local database with addon information
     */
    private function updateInstanceAddons($instanceId, $addons)
    {
        try {
            // Get local instance
            $instance = Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if (!$instance) {
                return;
            }

            // Update specs with addon information
            $specs = json_decode($instance->specs, true) ?? [];
            $specs['addons'] = $addons;
            $specs['addon_upgraded_at'] = date('Y-m-d H:i:s');

            Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->update([
                    'specs' => json_encode($specs),
                    'updated_at' => now()
                ]);

        } catch (Exception $e) {
            $this->logHelper->log('addon_database_update_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get addon usage statistics
     */
    public function getAddonUsageStats()
    {
        try {
            $stats = [
                'total_instances_with_addons' => 0,
                'addon_breakdown' => [],
                'revenue_impact' => []
            ];

            // Get all instances with addon data
            $instances = Capsule::table('mod_contabo_instances')
                ->whereNotNull('specs')
                ->get();

            foreach ($instances as $instance) {
                $specs = json_decode($instance->specs, true);
                if (isset($specs['addons']) && !empty($specs['addons'])) {
                    $stats['total_instances_with_addons']++;
                    
                    foreach ($specs['addons'] as $addonType => $addonConfig) {
                        if (!isset($stats['addon_breakdown'][$addonType])) {
                            $stats['addon_breakdown'][$addonType] = 0;
                        }
                        $stats['addon_breakdown'][$addonType]++;
                    }
                }
            }

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('addon_stats_failed', [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Generate addon recommendation based on instance usage
     */
    public function generateAddonRecommendations($instanceId)
    {
        $recommendations = [];

        try {
            // Get instance details and current add-ons
            $instance = $this->apiClient->getInstance($instanceId);
            $currentAddons = $this->getInstanceAddons($instanceId);
            
            $hasBackup = $this->hasAddon($currentAddons, 'backup');
            $hasFirewall = $this->hasAddon($currentAddons, 'firewalling');
            $hasPrivateNet = $this->hasAddon($currentAddons, 'privateNetworking');

            // Recommend backup if not present
            if (!$hasBackup) {
                $recommendations[] = [
                    'addon' => 'backup',
                    'priority' => 'high',
                    'reason' => 'Protect your data with automated daily backups',
                    'estimated_cost' => 4.99
                ];
            }

            // Recommend firewall for public instances
            if (!$hasFirewall) {
                $recommendations[] = [
                    'addon' => 'firewalling',
                    'priority' => 'medium',
                    'reason' => 'Enhanced security with advanced firewall rules',
                    'estimated_cost' => 3.99
                ];
            }

            // Recommend private networking for multi-instance setups
            if (!$hasPrivateNet) {
                $instanceCount = Capsule::table('mod_contabo_instances')
                    ->where('datacenter', $instance['data'][0]['region'] ?? '')
                    ->count();

                if ($instanceCount > 1) {
                    $recommendations[] = [
                        'addon' => 'privateNetworking',
                        'priority' => 'medium',
                        'reason' => 'Connect your instances securely via private network',
                        'estimated_cost' => 1.99
                    ];
                }
            }

            return $recommendations;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Check if instance has specific addon
     */
    private function hasAddon($addons, $addonType)
    {
        foreach ($addons as $addon) {
            if ($addon['type'] === $addonType) {
                return true;
            }
        }
        return false;
    }
}
