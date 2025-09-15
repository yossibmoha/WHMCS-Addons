<?php
/**
 * Contabo Application Service
 * 
 * Handles application marketplace and one-click installations
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class ApplicationService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get available applications from Contabo
     */
    public function getAvailableApplications($page = 1, $size = 50)
    {
        try {
            $response = $this->apiClient->makeRequest('GET', "/v1/compute/applications?page={$page}&size={$size}");
            return $response['data'] ?? [];

        } catch (Exception $e) {
            $this->logHelper->log('applications_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            
            // Return default applications if API fails
            return $this->getDefaultApplications();
        }
    }

    /**
     * Create instance with application
     */
    public function createInstanceWithApplication($instanceData, $applicationId)
    {
        try {
            // Add application ID to instance creation data
            $instanceData['applicationId'] = $applicationId;

            // Create instance via API
            $response = $this->apiClient->createInstance($instanceData);

            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('Invalid response from Contabo API');
            }

            $instance = $response['data'][0];

            // Store instance with application info
            $instanceDbId = Capsule::table('mod_contabo_instances')->insertGetId([
                'service_id' => $instanceData['service_id'],
                'contabo_instance_id' => $instance['instanceId'],
                'name' => $instance['name'] ?? $instanceData['displayName'],
                'status' => $instance['status'] ?? 'provisioning',
                'image_id' => $instanceData['imageId'],
                'datacenter' => $instanceData['region'],
                'specs' => json_encode([
                    'productId' => $instanceData['productId'],
                    'applicationId' => $applicationId,
                    'application_installed' => true
                ]),
                'network_config' => json_encode([
                    'ipv4' => $instance['ipConfig']['v4']['ip'] ?? null,
                    'ipv6' => $instance['ipConfig']['v6']['ip'] ?? null,
                ]),
                'cloud_init_script' => $instanceData['userData'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->logHelper->log('instance_with_application_created', [
                'service_id' => $instanceData['service_id'],
                'contabo_instance_id' => $instance['instanceId'],
                'application_id' => $applicationId,
                'local_id' => $instanceDbId
            ]);

            return [
                'success' => true,
                'instanceId' => $instance['instanceId'],
                'localId' => $instanceDbId,
                'data' => $instance,
                'application_id' => $applicationId
            ];

        } catch (Exception $e) {
            $this->logHelper->log('instance_with_application_creation_failed', [
                'service_id' => $instanceData['service_id'] ?? null,
                'application_id' => $applicationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get application categories
     */
    public function getApplicationCategories()
    {
        return [
            'web_servers' => [
                'name' => 'Web Servers',
                'description' => 'Web server software and stacks',
                'icon' => 'fas fa-server'
            ],
            'cms' => [
                'name' => 'Content Management',
                'description' => 'CMS platforms and blogging software',
                'icon' => 'fas fa-edit'
            ],
            'ecommerce' => [
                'name' => 'E-commerce',
                'description' => 'Online store and shopping cart solutions',
                'icon' => 'fas fa-shopping-cart'
            ],
            'databases' => [
                'name' => 'Databases',
                'description' => 'Database servers and management tools',
                'icon' => 'fas fa-database'
            ],
            'development' => [
                'name' => 'Development',
                'description' => 'Development tools and frameworks',
                'icon' => 'fas fa-code'
            ],
            'monitoring' => [
                'name' => 'Monitoring',
                'description' => 'System monitoring and analytics',
                'icon' => 'fas fa-chart-line'
            ],
            'security' => [
                'name' => 'Security',
                'description' => 'Security tools and firewalls',
                'icon' => 'fas fa-shield-alt'
            ],
            'collaboration' => [
                'name' => 'Collaboration',
                'description' => 'Team collaboration and communication',
                'icon' => 'fas fa-users'
            ],
            'automation' => [
                'name' => 'Automation',
                'description' => 'Workflow automation and CI/CD',
                'icon' => 'fas fa-robot'
            ]
        ];
    }

    /**
     * Get popular applications
     */
    public function getPopularApplications()
    {
        return [
            [
                'id' => 'wordpress',
                'name' => 'WordPress',
                'category' => 'cms',
                'description' => 'Popular CMS and blogging platform',
                'version' => '6.4',
                'icon' => 'fab fa-wordpress',
                'popularity_score' => 95,
                'setup_time' => '2-3 minutes'
            ],
            [
                'id' => 'docker',
                'name' => 'Docker',
                'category' => 'development',
                'description' => 'Container platform for applications',
                'version' => '24.0',
                'icon' => 'fab fa-docker',
                'popularity_score' => 90,
                'setup_time' => '3-5 minutes'
            ],
            [
                'id' => 'nginx',
                'name' => 'Nginx',
                'category' => 'web_servers',
                'description' => 'High-performance web server',
                'version' => '1.24',
                'icon' => 'fas fa-server',
                'popularity_score' => 88,
                'setup_time' => '1-2 minutes'
            ],
            [
                'id' => 'mysql',
                'name' => 'MySQL',
                'category' => 'databases',
                'description' => 'Popular relational database',
                'version' => '8.0',
                'icon' => 'fas fa-database',
                'popularity_score' => 85,
                'setup_time' => '2-3 minutes'
            ]
        ];
    }

    /**
     * Get application installation status
     */
    public function getApplicationStatus($instanceId, $applicationId)
    {
        try {
            // Check local database first
            $instance = Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if ($instance) {
                $specs = json_decode($instance->specs, true);
                if (isset($specs['applicationId']) && $specs['applicationId'] === $applicationId) {
                    return [
                        'installed' => $specs['application_installed'] ?? false,
                        'status' => $instance->status,
                        'installation_progress' => $this->getInstallationProgress($instanceId, $applicationId)
                    ];
                }
            }

            return [
                'installed' => false,
                'status' => 'not_installed',
                'installation_progress' => null
            ];

        } catch (Exception $e) {
            return [
                'installed' => false,
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate application configuration
     */
    public function generateApplicationConfig($applicationId, $customConfig = [])
    {
        $applications = $this->getDefaultApplications();
        
        foreach ($applications as $app) {
            if ($app['id'] === $applicationId) {
                $config = $app['default_config'] ?? [];
                
                // Merge with custom configuration
                return array_merge($config, $customConfig);
            }
        }

        return [];
    }

    /**
     * Get application access information
     */
    public function getApplicationAccessInfo($instanceId, $applicationId)
    {
        try {
            // Get instance details
            $instance = $this->apiClient->getInstance($instanceId);
            $instanceData = $instance['data'][0] ?? null;

            if (!$instanceData) {
                throw new Exception('Instance not found');
            }

            $ipv4 = $instanceData['ipConfig']['v4']['ip'] ?? null;
            $applications = $this->getDefaultApplications();

            foreach ($applications as $app) {
                if ($app['id'] === $applicationId) {
                    $accessInfo = $app['access_info'] ?? [];
                    
                    // Replace placeholders with actual values
                    foreach ($accessInfo as &$info) {
                        if (isset($info['url'])) {
                            $info['url'] = str_replace('{{SERVER_IP}}', $ipv4, $info['url']);
                        }
                    }

                    return $accessInfo;
                }
            }

            return [];

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get installation progress (simulated)
     */
    private function getInstallationProgress($instanceId, $applicationId)
    {
        // This would typically integrate with actual installation monitoring
        // For now, return simulated progress based on instance age
        
        try {
            $instance = Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if (!$instance) {
                return null;
            }

            $createdTime = strtotime($instance->created_at);
            $currentTime = time();
            $elapsedMinutes = ($currentTime - $createdTime) / 60;

            // Simulate installation progress
            if ($elapsedMinutes < 1) {
                return ['progress' => 10, 'status' => 'Initializing system...'];
            } elseif ($elapsedMinutes < 3) {
                return ['progress' => 50, 'status' => 'Installing application...'];
            } elseif ($elapsedMinutes < 5) {
                return ['progress' => 80, 'status' => 'Configuring application...'];
            } else {
                return ['progress' => 100, 'status' => 'Installation complete'];
            }

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Default applications fallback
     */
    private function getDefaultApplications()
    {
        return [
            [
                'id' => 'wordpress',
                'name' => 'WordPress',
                'category' => 'cms',
                'description' => 'The world\'s most popular CMS platform',
                'long_description' => 'WordPress powers over 40% of all websites. It\'s a flexible, user-friendly content management system perfect for blogs, business websites, and e-commerce stores.',
                'version' => '6.4',
                'icon' => 'fab fa-wordpress',
                'tags' => ['cms', 'blog', 'website', 'php'],
                'requirements' => [
                    'min_ram' => '1GB',
                    'min_storage' => '10GB',
                    'php' => '8.0+',
                    'database' => 'MySQL 8.0+'
                ],
                'default_config' => [
                    'admin_user' => 'admin',
                    'database_name' => 'wordpress',
                    'php_version' => '8.2'
                ],
                'access_info' => [
                    [
                        'name' => 'WordPress Admin',
                        'url' => 'http://{{SERVER_IP}}/wp-admin',
                        'description' => 'WordPress administration panel'
                    ],
                    [
                        'name' => 'Website',
                        'url' => 'http://{{SERVER_IP}}',
                        'description' => 'Your WordPress website'
                    ]
                ],
                'setup_time' => '2-3 minutes',
                'popularity_score' => 95
            ],
            [
                'id' => 'cloudpanel_n8n',
                'name' => 'CloudPanel + n8n',
                'category' => 'automation',
                'description' => 'Web hosting control panel with workflow automation',
                'long_description' => 'CloudPanel provides an easy-to-use web hosting control panel, combined with n8n for powerful workflow automation. Perfect for managing websites and automating tasks.',
                'version' => '2.4 + 1.15',
                'icon' => 'fas fa-cogs',
                'tags' => ['control-panel', 'automation', 'workflow', 'hosting'],
                'requirements' => [
                    'min_ram' => '2GB',
                    'min_storage' => '20GB',
                    'docker' => 'required'
                ],
                'default_config' => [
                    'db_engine' => 'MYSQL_8.4',
                    'n8n_domain_override' => ''
                ],
                'access_info' => [
                    [
                        'name' => 'CloudPanel',
                        'url' => 'https://{{SERVER_IP}}:8443',
                        'description' => 'CloudPanel control panel'
                    ],
                    [
                        'name' => 'n8n Automation',
                        'url' => 'http://n8n.{{SERVER_IP}}.nip.io',
                        'description' => 'n8n workflow automation'
                    ]
                ],
                'setup_time' => '5-8 minutes',
                'popularity_score' => 78
            ],
            [
                'id' => 'docker',
                'name' => 'Docker',
                'category' => 'development',
                'description' => 'Container platform for modern applications',
                'long_description' => 'Docker enables you to package applications into containers - standardized executable components combining application source code with all the OS libraries and dependencies.',
                'version' => '24.0',
                'icon' => 'fab fa-docker',
                'tags' => ['containers', 'development', 'devops'],
                'requirements' => [
                    'min_ram' => '1GB',
                    'min_storage' => '10GB'
                ],
                'setup_time' => '3-5 minutes',
                'popularity_score' => 90
            ],
            [
                'id' => 'lemp_stack',
                'name' => 'LEMP Stack',
                'category' => 'web_servers',
                'description' => 'Linux, Nginx, MySQL, PHP web server stack',
                'long_description' => 'Complete web server stack with Linux, Nginx web server, MySQL database, and PHP scripting language. Perfect foundation for web applications.',
                'version' => 'Latest',
                'icon' => 'fas fa-layer-group',
                'tags' => ['nginx', 'mysql', 'php', 'web-server'],
                'requirements' => [
                    'min_ram' => '1GB',
                    'min_storage' => '15GB'
                ],
                'access_info' => [
                    [
                        'name' => 'Web Server',
                        'url' => 'http://{{SERVER_IP}}',
                        'description' => 'Nginx web server'
                    ],
                    [
                        'name' => 'phpMyAdmin',
                        'url' => 'http://{{SERVER_IP}}/phpmyadmin',
                        'description' => 'MySQL database management'
                    ]
                ],
                'setup_time' => '4-6 minutes',
                'popularity_score' => 82
            ]
        ];
    }
}
