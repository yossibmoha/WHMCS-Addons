<?php
/**
 * Firewall Management Service
 * 
 * Handles firewall rules, security groups, and DDoS protection for VPS servers
 */

namespace ContaboAddon\Services;

use Exception;

class FirewallService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get firewall rules for instance
     */
    public function getFirewallRules($instanceId)
    {
        try {
            // Note: Contabo API doesn't have direct firewall endpoints
            // This implementation simulates firewall management through server configuration
            $localRules = $this->getLocalFirewallConfig($instanceId);
            
            // Get current server status to verify connectivity
            $instance = $this->apiClient->makeRequest('GET', "/v1/compute/instances/{$instanceId}");
            
            return [
                'instance_id' => $instanceId,
                'status' => $localRules['status'] ?? 'active',
                'rules' => $localRules['rules'] ?? $this->getDefaultRules(),
                'templates' => $this->getSecurityTemplates(),
                'last_updated' => $localRules['last_updated'] ?? null
            ];

        } catch (Exception $e) {
            $this->logHelper->log('firewall_rules_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create firewall rule
     */
    public function createFirewallRule($instanceId, $ruleData)
    {
        try {
            $rule = [
                'id' => uniqid('fw_'),
                'name' => $ruleData['name'],
                'action' => $ruleData['action'], // allow, deny
                'protocol' => $ruleData['protocol'], // tcp, udp, icmp, all
                'port_range' => $ruleData['port_range'] ?? null,
                'source_ip' => $ruleData['source_ip'] ?? '0.0.0.0/0',
                'destination_ip' => $ruleData['destination_ip'] ?? 'any',
                'direction' => $ruleData['direction'], // inbound, outbound
                'priority' => $ruleData['priority'] ?? 100,
                'enabled' => $ruleData['enabled'] ?? true,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $ruleData['created_by'] ?? 'user'
            ];

            // Validate rule
            $this->validateFirewallRule($rule);

            // Store rule locally
            $this->addLocalFirewallRule($instanceId, $rule);

            // Apply rule to server (simulated via cloud-init or SSH commands)
            $this->applyFirewallRuleToServer($instanceId, $rule);

            $this->logHelper->log('firewall_rule_created', [
                'instance_id' => $instanceId,
                'rule_id' => $rule['id'],
                'rule_name' => $rule['name'],
                'protocol' => $rule['protocol'],
                'port_range' => $rule['port_range']
            ]);

            return [
                'success' => true,
                'rule_id' => $rule['id'],
                'message' => 'Firewall rule created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('firewall_rule_creation_failed', [
                'instance_id' => $instanceId,
                'rule_data' => $ruleData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update firewall rule
     */
    public function updateFirewallRule($instanceId, $ruleId, $ruleData)
    {
        try {
            $existingRules = $this->getLocalFirewallConfig($instanceId);
            
            if (!isset($existingRules['rules'][$ruleId])) {
                throw new Exception('Firewall rule not found');
            }

            $rule = array_merge($existingRules['rules'][$ruleId], $ruleData);
            $rule['updated_at'] = date('Y-m-d H:i:s');

            // Validate updated rule
            $this->validateFirewallRule($rule);

            // Update local storage
            $this->updateLocalFirewallRule($instanceId, $ruleId, $rule);

            // Apply changes to server
            $this->applyFirewallRuleToServer($instanceId, $rule);

            $this->logHelper->log('firewall_rule_updated', [
                'instance_id' => $instanceId,
                'rule_id' => $ruleId,
                'changes' => array_keys($ruleData)
            ]);

            return [
                'success' => true,
                'message' => 'Firewall rule updated successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('firewall_rule_update_failed', [
                'instance_id' => $instanceId,
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete firewall rule
     */
    public function deleteFirewallRule($instanceId, $ruleId)
    {
        try {
            $existingRules = $this->getLocalFirewallConfig($instanceId);
            
            if (!isset($existingRules['rules'][$ruleId])) {
                throw new Exception('Firewall rule not found');
            }

            $rule = $existingRules['rules'][$ruleId];

            // Remove from server first
            $this->removeFirewallRuleFromServer($instanceId, $rule);

            // Remove from local storage
            $this->deleteLocalFirewallRule($instanceId, $ruleId);

            $this->logHelper->log('firewall_rule_deleted', [
                'instance_id' => $instanceId,
                'rule_id' => $ruleId,
                'rule_name' => $rule['name']
            ]);

            return [
                'success' => true,
                'message' => 'Firewall rule deleted successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('firewall_rule_deletion_failed', [
                'instance_id' => $instanceId,
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Apply security template
     */
    public function applySecurityTemplate($instanceId, $templateName, $options = [])
    {
        try {
            $templates = $this->getSecurityTemplates();
            
            if (!isset($templates[$templateName])) {
                throw new Exception('Security template not found');
            }

            $template = $templates[$templateName];
            $results = ['applied' => 0, 'failed' => 0, 'rules' => []];

            // Clear existing rules if requested
            if ($options['replace_existing'] ?? false) {
                $this->clearAllFirewallRules($instanceId);
            }

            // Apply template rules
            foreach ($template['rules'] as $ruleTemplate) {
                try {
                    // Customize rule based on options
                    $rule = $this->customizeTemplateRule($ruleTemplate, $options);
                    
                    $result = $this->createFirewallRule($instanceId, $rule);
                    
                    if ($result['success']) {
                        $results['applied']++;
                        $results['rules'][] = $result['rule_id'];
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                }
            }

            $this->logHelper->log('security_template_applied', [
                'instance_id' => $instanceId,
                'template_name' => $templateName,
                'results' => $results
            ]);

            return [
                'success' => true,
                'template_name' => $templateName,
                'results' => $results,
                'message' => "Security template applied: {$results['applied']} rules added"
            ];

        } catch (Exception $e) {
            $this->logHelper->log('security_template_failed', [
                'instance_id' => $instanceId,
                'template_name' => $templateName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get security templates
     */
    public function getSecurityTemplates()
    {
        return [
            'web_server' => [
                'name' => 'Web Server (HTTP/HTTPS)',
                'description' => 'Standard rules for web servers running Apache, Nginx, or similar',
                'rules' => [
                    [
                        'name' => 'Allow HTTP',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '80',
                        'direction' => 'inbound',
                        'priority' => 100
                    ],
                    [
                        'name' => 'Allow HTTPS',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '443',
                        'direction' => 'inbound',
                        'priority' => 100
                    ],
                    [
                        'name' => 'Allow SSH',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '22',
                        'direction' => 'inbound',
                        'priority' => 90
                    ]
                ]
            ],
            'database_server' => [
                'name' => 'Database Server',
                'description' => 'Secure rules for database servers (MySQL, PostgreSQL)',
                'rules' => [
                    [
                        'name' => 'Allow MySQL',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '3306',
                        'direction' => 'inbound',
                        'priority' => 100,
                        'source_ip' => '192.168.0.0/16' // Private networks only
                    ],
                    [
                        'name' => 'Allow PostgreSQL',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '5432',
                        'direction' => 'inbound',
                        'priority' => 100,
                        'source_ip' => '192.168.0.0/16'
                    ],
                    [
                        'name' => 'Allow SSH',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '22',
                        'direction' => 'inbound',
                        'priority' => 90
                    ]
                ]
            ],
            'application_server' => [
                'name' => 'Application Server',
                'description' => 'Rules for application servers (Node.js, Docker, etc.)',
                'rules' => [
                    [
                        'name' => 'Allow App Port 3000',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '3000',
                        'direction' => 'inbound',
                        'priority' => 100
                    ],
                    [
                        'name' => 'Allow App Port 8080',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '8080',
                        'direction' => 'inbound',
                        'priority' => 100
                    ],
                    [
                        'name' => 'Allow SSH',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '22',
                        'direction' => 'inbound',
                        'priority' => 90
                    ]
                ]
            ],
            'high_security' => [
                'name' => 'High Security',
                'description' => 'Restrictive rules for high-security environments',
                'rules' => [
                    [
                        'name' => 'SSH from Admin IPs Only',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '22',
                        'direction' => 'inbound',
                        'priority' => 90,
                        'source_ip' => 'admin_ips' // Will be replaced with actual admin IPs
                    ],
                    [
                        'name' => 'Block All Other Inbound',
                        'action' => 'deny',
                        'protocol' => 'all',
                        'port_range' => 'all',
                        'direction' => 'inbound',
                        'priority' => 999
                    ]
                ]
            ],
            'development' => [
                'name' => 'Development Server',
                'description' => 'Open rules suitable for development environments',
                'rules' => [
                    [
                        'name' => 'Allow HTTP',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '80',
                        'direction' => 'inbound',
                        'priority' => 100
                    ],
                    [
                        'name' => 'Allow HTTPS',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '443',
                        'direction' => 'inbound',
                        'priority' => 100
                    ],
                    [
                        'name' => 'Allow SSH',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '22',
                        'direction' => 'inbound',
                        'priority' => 90
                    ],
                    [
                        'name' => 'Allow Dev Ports',
                        'action' => 'allow',
                        'protocol' => 'tcp',
                        'port_range' => '3000-9000',
                        'direction' => 'inbound',
                        'priority' => 100
                    ]
                ]
            ]
        ];
    }

    /**
     * Get firewall statistics
     */
    public function getFirewallStatistics($instanceId = null)
    {
        try {
            $stats = [
                'total_rules' => 0,
                'allow_rules' => 0,
                'deny_rules' => 0,
                'inbound_rules' => 0,
                'outbound_rules' => 0,
                'templates_used' => [],
                'most_common_ports' => [],
                'rule_distribution' => []
            ];

            $instances = [];
            if ($instanceId) {
                $instances = [$instanceId];
            } else {
                // Get all instances
                $instances = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                    ->pluck('contabo_instance_id')
                    ->toArray();
            }

            $portCount = [];

            foreach ($instances as $id) {
                $config = $this->getLocalFirewallConfig($id);
                $rules = $config['rules'] ?? [];

                foreach ($rules as $rule) {
                    $stats['total_rules']++;
                    
                    if ($rule['action'] === 'allow') {
                        $stats['allow_rules']++;
                    } else {
                        $stats['deny_rules']++;
                    }

                    if ($rule['direction'] === 'inbound') {
                        $stats['inbound_rules']++;
                    } else {
                        $stats['outbound_rules']++;
                    }

                    // Count port usage
                    $port = $rule['port_range'] ?? 'any';
                    if (!isset($portCount[$port])) {
                        $portCount[$port] = 0;
                    }
                    $portCount[$port]++;
                }
            }

            // Sort ports by usage
            arsort($portCount);
            $stats['most_common_ports'] = array_slice($portCount, 0, 10, true);

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('firewall_stats_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Test firewall connectivity
     */
    public function testFirewallConnectivity($instanceId, $testPorts = [])
    {
        try {
            $defaultTestPorts = [22, 80, 443];
            $ports = !empty($testPorts) ? $testPorts : $defaultTestPorts;

            // Get instance IP
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $instanceId)
                ->first();

            if (!$instance) {
                throw new Exception('Instance not found');
            }

            $networkConfig = json_decode($instance->network_config, true);
            $ip = $networkConfig['ipv4'] ?? null;

            if (!$ip) {
                throw new Exception('Instance IP not found');
            }

            $results = [];

            foreach ($ports as $port) {
                $startTime = microtime(true);
                $connection = @fsockopen($ip, $port, $errno, $errstr, 5);
                $endTime = microtime(true);

                $results[$port] = [
                    'port' => $port,
                    'open' => $connection !== false,
                    'response_time' => round(($endTime - $startTime) * 1000, 2),
                    'error' => $connection === false ? $errstr : null
                ];

                if ($connection) {
                    fclose($connection);
                }
            }

            $this->logHelper->log('firewall_connectivity_test', [
                'instance_id' => $instanceId,
                'ip' => $ip,
                'results' => $results
            ]);

            return [
                'success' => true,
                'instance_id' => $instanceId,
                'ip' => $ip,
                'test_results' => $results
            ];

        } catch (Exception $e) {
            $this->logHelper->log('firewall_connectivity_test_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get local firewall configuration
     */
    private function getLocalFirewallConfig($instanceId)
    {
        try {
            $config = \WHMCS\Database\Capsule::table('mod_contabo_firewall_configs')
                ->where('instance_id', $instanceId)
                ->first();

            if (!$config) {
                return ['status' => 'inactive', 'rules' => []];
            }

            return [
                'status' => $config->status,
                'rules' => json_decode($config->rules, true) ?: [],
                'last_updated' => $config->updated_at
            ];

        } catch (Exception $e) {
            return ['status' => 'inactive', 'rules' => []];
        }
    }

    /**
     * Get default firewall rules
     */
    private function getDefaultRules()
    {
        return [
            'ssh_allow' => [
                'id' => 'ssh_allow',
                'name' => 'Allow SSH',
                'action' => 'allow',
                'protocol' => 'tcp',
                'port_range' => '22',
                'source_ip' => '0.0.0.0/0',
                'direction' => 'inbound',
                'priority' => 100,
                'enabled' => true,
                'system_rule' => true
            ]
        ];
    }

    /**
     * Validate firewall rule
     */
    private function validateFirewallRule($rule)
    {
        // Validate required fields
        $required = ['name', 'action', 'protocol', 'direction'];
        foreach ($required as $field) {
            if (empty($rule[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        // Validate values
        if (!in_array($rule['action'], ['allow', 'deny'])) {
            throw new Exception('Action must be "allow" or "deny"');
        }

        if (!in_array($rule['protocol'], ['tcp', 'udp', 'icmp', 'all'])) {
            throw new Exception('Invalid protocol');
        }

        if (!in_array($rule['direction'], ['inbound', 'outbound'])) {
            throw new Exception('Direction must be "inbound" or "outbound"');
        }

        // Validate port range
        if ($rule['protocol'] !== 'icmp' && !empty($rule['port_range'])) {
            if (!$this->isValidPortRange($rule['port_range'])) {
                throw new Exception('Invalid port range format');
            }
        }

        // Validate IP addresses
        if (!empty($rule['source_ip']) && !$this->isValidIPRange($rule['source_ip'])) {
            throw new Exception('Invalid source IP format');
        }
    }

    /**
     * Check if port range is valid
     */
    private function isValidPortRange($portRange)
    {
        if ($portRange === 'all') {
            return true;
        }

        // Single port
        if (is_numeric($portRange)) {
            $port = intval($portRange);
            return $port >= 1 && $port <= 65535;
        }

        // Port range (e.g., 3000-8000)
        if (strpos($portRange, '-') !== false) {
            list($start, $end) = explode('-', $portRange, 2);
            if (is_numeric($start) && is_numeric($end)) {
                $startPort = intval($start);
                $endPort = intval($end);
                return $startPort >= 1 && $endPort <= 65535 && $startPort <= $endPort;
            }
        }

        return false;
    }

    /**
     * Check if IP range is valid
     */
    private function isValidIPRange($ipRange)
    {
        if ($ipRange === 'any' || $ipRange === '0.0.0.0/0') {
            return true;
        }

        // CIDR notation
        if (strpos($ipRange, '/') !== false) {
            return filter_var($ipRange, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ||
                   filter_var($ipRange, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        // Single IP
        return filter_var($ipRange, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Add firewall rule to local storage
     */
    private function addLocalFirewallRule($instanceId, $rule)
    {
        $config = $this->getLocalFirewallConfig($instanceId);
        $config['rules'][$rule['id']] = $rule;

        $this->saveLocalFirewallConfig($instanceId, $config);
    }

    /**
     * Update firewall rule in local storage
     */
    private function updateLocalFirewallRule($instanceId, $ruleId, $rule)
    {
        $config = $this->getLocalFirewallConfig($instanceId);
        $config['rules'][$ruleId] = $rule;

        $this->saveLocalFirewallConfig($instanceId, $config);
    }

    /**
     * Delete firewall rule from local storage
     */
    private function deleteLocalFirewallRule($instanceId, $ruleId)
    {
        $config = $this->getLocalFirewallConfig($instanceId);
        unset($config['rules'][$ruleId]);

        $this->saveLocalFirewallConfig($instanceId, $config);
    }

    /**
     * Save firewall configuration locally
     */
    private function saveLocalFirewallConfig($instanceId, $config)
    {
        \WHMCS\Database\Capsule::table('mod_contabo_firewall_configs')
            ->updateOrInsert(
                ['instance_id' => $instanceId],
                [
                    'status' => $config['status'] ?? 'active',
                    'rules' => json_encode($config['rules']),
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );
    }

    /**
     * Apply firewall rule to server (simulated)
     */
    private function applyFirewallRuleToServer($instanceId, $rule)
    {
        // In a real implementation, this would:
        // 1. Generate iptables/ufw commands
        // 2. Connect to server via SSH
        // 3. Execute firewall commands
        // 4. Verify rule application

        // For this implementation, we'll just log the action
        $this->logHelper->log('firewall_rule_applied_to_server', [
            'instance_id' => $instanceId,
            'rule_id' => $rule['id'],
            'simulated' => true
        ]);

        return true;
    }

    /**
     * Remove firewall rule from server
     */
    private function removeFirewallRuleFromServer($instanceId, $rule)
    {
        $this->logHelper->log('firewall_rule_removed_from_server', [
            'instance_id' => $instanceId,
            'rule_id' => $rule['id'],
            'simulated' => true
        ]);

        return true;
    }

    /**
     * Clear all firewall rules
     */
    private function clearAllFirewallRules($instanceId)
    {
        $config = ['status' => 'active', 'rules' => []];
        $this->saveLocalFirewallConfig($instanceId, $config);
    }

    /**
     * Customize template rule with options
     */
    private function customizeTemplateRule($ruleTemplate, $options)
    {
        $rule = $ruleTemplate;

        // Replace admin IPs placeholder
        if (isset($rule['source_ip']) && $rule['source_ip'] === 'admin_ips') {
            $rule['source_ip'] = $options['admin_ips'] ?? '0.0.0.0/0';
        }

        // Set creator
        $rule['created_by'] = 'template';

        return $rule;
    }
}
