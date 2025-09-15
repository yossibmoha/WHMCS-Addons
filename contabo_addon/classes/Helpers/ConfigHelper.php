<?php
/**
 * Configuration Helper
 * 
 * Manages addon configuration and settings
 */

namespace ContaboAddon\Helpers;

use WHMCS\Database\Capsule;
use Exception;

class ConfigHelper
{
    private static $config = null;

    /**
     * Get addon configuration
     */
    public static function getConfig($key = null)
    {
        if (self::$config === null) {
            self::loadConfig();
        }

        if ($key === null) {
            return self::$config;
        }

        return self::$config[$key] ?? null;
    }

    /**
     * Load configuration from database
     */
    private static function loadConfig()
    {
        try {
            $settings = Capsule::table('tbladdonmodules')
                ->where('module', 'contabo_addon')
                ->pluck('value', 'setting');

            self::$config = $settings->toArray();
        } catch (Exception $e) {
            self::$config = [];
        }
    }

    /**
     * Update configuration value
     */
    public static function updateConfig($key, $value)
    {
        try {
            Capsule::table('tbladdonmodules')
                ->where('module', 'contabo_addon')
                ->where('setting', $key)
                ->update(['value' => $value]);

            // Update cached config
            if (self::$config !== null) {
                self::$config[$key] = $value;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get API credentials
     */
    public static function getApiCredentials()
    {
        $config = self::getConfig();
        
        return [
            'client_id' => $config['client_id'] ?? '',
            'client_secret' => $config['client_secret'] ?? '',
            'api_user' => $config['api_user'] ?? '',
            'api_password' => $config['api_password'] ?? '',
            'debug_logging' => $config['debug_logging'] ?? 'no'
        ];
    }

    /**
     * Check if feature is enabled
     */
    public static function isFeatureEnabled($feature)
    {
        $config = self::getConfig();
        $key = 'enable_' . $feature;
        
        return ($config[$key] ?? 'no') === 'yes';
    }

    /**
     * Get default datacenter
     */
    public static function getDefaultDataCenter()
    {
        return self::getConfig('default_datacenter') ?: 'EU';
    }

    /**
     * Get configurable options for products
     */
    public static function getConfigurableOptions()
    {
        return [
            'instance_type' => [
                'type' => 'dropdown',
                'name' => 'Instance Type',
                'options' => [
                    'vps-s' => 'VPS S (1 CPU, 4GB RAM, 50GB NVMe)',
                    'vps-m' => 'VPS M (2 CPU, 8GB RAM, 100GB NVMe)',
                    'vps-l' => 'VPS L (4 CPU, 16GB RAM, 200GB NVMe)',
                    'vps-xl' => 'VPS XL (6 CPU, 32GB RAM, 400GB NVMe)'
                ],
                'default' => 'vps-s'
            ],
            'operating_system' => [
                'type' => 'dropdown',
                'name' => 'Operating System',
                'options' => [
                    'ubuntu-20.04' => 'Ubuntu 20.04 LTS',
                    'ubuntu-22.04' => 'Ubuntu 22.04 LTS',
                    'debian-11' => 'Debian 11',
                    'centos-8' => 'CentOS Stream 8',
                    'windows-2019' => 'Windows Server 2019',
                    'windows-2022' => 'Windows Server 2022'
                ],
                'default' => 'ubuntu-22.04'
            ],
            'datacenter' => [
                'type' => 'dropdown',
                'name' => 'Data Center',
                'options' => [
                    'EU' => 'Europe (Germany)',
                    'US-WEST' => 'US West',
                    'US-EAST' => 'US East',
                    'ASIA' => 'Asia Pacific'
                ],
                'default' => 'EU'
            ],
            'private_networking' => [
                'type' => 'yesno',
                'name' => 'Private Networking',
                'description' => 'Enable private networking capability',
                'default' => 'no'
            ],
            'backup_retention' => [
                'type' => 'dropdown',
                'name' => 'Backup Retention',
                'options' => [
                    '7' => '7 days',
                    '14' => '14 days',
                    '30' => '30 days',
                    '60' => '60 days'
                ],
                'default' => '7'
            ],
            'monitoring' => [
                'type' => 'yesno',
                'name' => 'Advanced Monitoring',
                'description' => 'Enable advanced monitoring and alerting',
                'default' => 'no'
            ],
            'firewall' => [
                'type' => 'yesno', 
                'name' => 'Managed Firewall',
                'description' => 'Enable managed firewall rules',
                'default' => 'yes'
            ],
            'ssh_keys' => [
                'type' => 'textarea',
                'name' => 'SSH Public Keys',
                'description' => 'One SSH public key per line',
                'rows' => 4
            ],
            'cloud_init_script' => [
                'type' => 'textarea',
                'name' => 'Cloud-Init Script',
                'description' => 'Custom cloud-init script (YAML format)',
                'rows' => 10
            ]
        ];
    }

    /**
     * Get object storage configurable options
     */
    public static function getObjectStorageOptions()
    {
        return [
            'storage_size' => [
                'type' => 'dropdown',
                'name' => 'Storage Size',
                'options' => [
                    '250' => '250 GB',
                    '500' => '500 GB', 
                    '1000' => '1 TB',
                    '2000' => '2 TB',
                    '5000' => '5 TB',
                    '10000' => '10 TB'
                ],
                'default' => '250'
            ],
            'region' => [
                'type' => 'dropdown',
                'name' => 'Region',
                'options' => [
                    'EU' => 'Europe (Germany)',
                    'US-WEST' => 'US West',
                    'US-EAST' => 'US East',
                    'ASIA' => 'Asia Pacific'
                ],
                'default' => 'EU'
            ],
            'auto_scaling' => [
                'type' => 'yesno',
                'name' => 'Auto Scaling',
                'description' => 'Automatically increase storage when needed',
                'default' => 'no'
            ],
            'auto_scaling_limit' => [
                'type' => 'text',
                'name' => 'Auto Scaling Limit (GB)',
                'description' => 'Maximum storage size for auto scaling',
                'default' => '10000'
            ]
        ];
    }

    /**
     * Get cloud-init template variables
     */
    public static function getCloudInitVariables($templateId)
    {
        try {
            $template = Capsule::table('mod_contabo_cloud_init_templates')
                ->where('id', $templateId)
                ->first();

            if (!$template) {
                return [];
            }

            $variables = json_decode($template->configurable_vars, true);
            return $variables ?: [];

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Validate configuration
     */
    public static function validateConfig()
    {
        $config = self::getConfig();
        $errors = [];

        // Check required API credentials
        $requiredFields = ['client_id', 'client_secret', 'api_user', 'api_password'];
        
        foreach ($requiredFields as $field) {
            if (empty($config[$field])) {
                $errors[] = "Missing required configuration: {$field}";
            }
        }

        // Validate data center
        $validDataCenters = ['EU', 'US-WEST', 'US-EAST', 'ASIA'];
        $defaultDC = $config['default_datacenter'] ?? '';
        
        if (!in_array($defaultDC, $validDataCenters)) {
            $errors[] = "Invalid default datacenter: {$defaultDC}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get pricing configuration
     */
    public static function getPricingConfig()
    {
        return [
            'vps' => [
                'vps-s' => ['monthly' => 4.99, 'setup' => 0],
                'vps-m' => ['monthly' => 8.99, 'setup' => 0],
                'vps-l' => ['monthly' => 16.99, 'setup' => 0],
                'vps-xl' => ['monthly' => 29.99, 'setup' => 0]
            ],
            'object_storage' => [
                'base_price_per_gb' => 0.025,
                'regions' => [
                    'EU' => 1.0,      // No multiplier
                    'US-WEST' => 1.0, // No multiplier
                    'US-EAST' => 1.0, // No multiplier
                    'ASIA' => 1.2     // 20% premium
                ]
            ],
            'add_ons' => [
                'private_networking' => 1.99,
                'backup_retention_14' => 2.99,
                'backup_retention_30' => 4.99,
                'backup_retention_60' => 7.99,
                'monitoring' => 1.99,
                'managed_firewall' => 2.99
            ],
            'vip_addresses' => [
                'ipv4' => 2.99,
                'ipv6' => 0.99
            ]
        ];
    }

    /**
     * Get webhook configuration
     */
    public static function getWebhookConfig()
    {
        return [
            'enabled' => self::getConfig('webhook_enabled') === 'yes',
            'url' => self::getConfig('webhook_url') ?: '',
            'secret' => self::getConfig('webhook_secret') ?: '',
            'events' => [
                'instance.created',
                'instance.started', 
                'instance.stopped',
                'instance.deleted',
                'storage.created',
                'storage.resized',
                'network.created'
            ]
        ];
    }

    /**
     * Get notification settings
     */
    public static function getNotificationSettings()
    {
        return [
            'email_notifications' => self::getConfig('email_notifications') === 'yes',
            'admin_email' => self::getConfig('admin_email') ?: '',
            'client_notifications' => self::getConfig('client_notifications') === 'yes',
            'notification_events' => [
                'provisioning_complete',
                'provisioning_failed',
                'service_suspended',
                'maintenance_scheduled',
                'quota_warning',
                'quota_exceeded'
            ]
        ];
    }

    /**
     * Export configuration for backup
     */
    public static function exportConfig()
    {
        $config = self::getConfig();
        
        // Remove sensitive data
        $sensitiveKeys = ['client_secret', 'api_password', 'webhook_secret'];
        foreach ($sensitiveKeys as $key) {
            if (isset($config[$key])) {
                $config[$key] = '***REDACTED***';
            }
        }

        return [
            'export_date' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
            'config' => $config
        ];
    }

    /**
     * Clear configuration cache
     */
    public static function clearCache()
    {
        self::$config = null;
    }
}
