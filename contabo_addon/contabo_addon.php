<?php
/**
 * Contabo API WHMCS Addon Module
 * 
 * Comprehensive integration with Contabo API for VPS/VDS, Object Storage, 
 * Private Networks, VIP addresses, and more.
 * 
 * @package    ContaboAddon
 * @author     Your Name
 * @copyright  2024
 * @version    1.0.0
 * @link       https://contabo.com/
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/classes/API/ContaboAPIClient.php';
require_once __DIR__ . '/classes/Services/ComputeService.php';
require_once __DIR__ . '/classes/Services/ObjectStorageService.php';
require_once __DIR__ . '/classes/Services/NetworkingService.php';
require_once __DIR__ . '/classes/Services/ImageService.php';
require_once __DIR__ . '/classes/Services/BackupService.php';
require_once __DIR__ . '/classes/Services/VNCService.php';
require_once __DIR__ . '/classes/Services/AddonService.php';
require_once __DIR__ . '/classes/Services/ApplicationService.php';
require_once __DIR__ . '/classes/Services/AdminServerService.php';
require_once __DIR__ . '/classes/Services/SecretManagementService.php';
require_once __DIR__ . '/classes/Services/RebuildService.php';
require_once __DIR__ . '/classes/Services/BillingIntegrationService.php';
require_once __DIR__ . '/classes/Services/FirewallService.php';
require_once __DIR__ . '/classes/Services/MonitoringService.php';
require_once __DIR__ . '/classes/Services/DNSService.php';
require_once __DIR__ . '/classes/Services/AutoScalingService.php';
require_once __DIR__ . '/classes/Services/SupportIntegrationService.php';
require_once __DIR__ . '/classes/Services/LoadBalancerService.php';
require_once __DIR__ . '/classes/Services/SystemHealthService.php';
require_once __DIR__ . '/classes/Helpers/ConfigHelper.php';
require_once __DIR__ . '/classes/Helpers/LogHelper.php';

/**
 * Define addon configuration
 */
function contabo_addon_config()
{
    return [
        'name' => 'Contabo API Integration',
        'description' => 'Comprehensive Contabo API integration for managing VPS/VDS, Object Storage, Private Networks, and more.',
        'version' => '1.0.0',
        'author' => 'Your Company',
        'language' => 'english',
        'fields' => [
            'client_id' => [
                'FriendlyName' => 'Contabo Client ID',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Your Contabo API Client ID from Customer Control Panel',
            ],
            'client_secret' => [
                'FriendlyName' => 'Contabo Client Secret',
                'Type' => 'password',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Your Contabo API Client Secret',
            ],
            'api_user' => [
                'FriendlyName' => 'API User Email',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Your email address for Contabo API access',
            ],
            'api_password' => [
                'FriendlyName' => 'API Password',
                'Type' => 'password',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Your Contabo API password',
            ],
            'default_datacenter' => [
                'FriendlyName' => 'Default Data Center',
                'Type' => 'dropdown',
                'Options' => [
                    'EU' => 'Europe',
                    'US-WEST' => 'US West',
                    'US-EAST' => 'US East',
                    'ASIA' => 'Asia'
                ],
                'Default' => 'EU',
                'Description' => 'Default data center for new instances',
            ],
            'enable_auto_provisioning' => [
                'FriendlyName' => 'Auto Provisioning',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Automatically provision services on payment',
            ],
            'enable_cloud_init' => [
                'FriendlyName' => 'Enable Cloud-Init',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Allow customers to use cloud-init scripts',
            ],
            'enable_object_storage' => [
                'FriendlyName' => 'Enable Object Storage',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Offer S3-compatible object storage',
            ],
            'enable_private_networks' => [
                'FriendlyName' => 'Enable Private Networks',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Allow private network creation and management',
            ],
            'enable_vip_addresses' => [
                'FriendlyName' => 'Enable VIP Addresses',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Allow VIP address management',
            ],
            'enable_custom_images' => [
                'FriendlyName' => 'Enable Custom Images',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Allow custom image upload and management',
            ],
            'debug_logging' => [
                'FriendlyName' => 'Debug Logging',
                'Type' => 'yesno',
                'Default' => 'no',
                'Description' => 'Enable detailed logging for troubleshooting',
            ],
        ]
    ];
}

/**
 * Addon activation
 */
function contabo_addon_activate()
{
    try {
        // Create custom tables for Contabo resources
        Capsule::schema()->create('mod_contabo_instances', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->string('contabo_instance_id', 50);
            $table->string('name', 100);
            $table->string('status', 20);
            $table->string('image_id', 50);
            $table->string('datacenter', 20);
            $table->json('specs');
            $table->json('network_config')->nullable();
            $table->text('cloud_init_script')->nullable();
            $table->timestamps();
            
            $table->index('service_id');
            $table->index('contabo_instance_id');
        });

        Capsule::schema()->create('mod_contabo_object_storages', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->string('contabo_storage_id', 50);
            $table->string('name', 100);
            $table->string('status', 20);
            $table->string('region', 20);
            $table->bigInteger('size_gb');
            $table->boolean('auto_scaling')->default(false);
            $table->bigInteger('auto_scaling_max_size_gb')->nullable();
            $table->json('access_keys')->nullable();
            $table->timestamps();
            
            $table->index('service_id');
            $table->index('contabo_storage_id');
        });

        Capsule::schema()->create('mod_contabo_private_networks', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->string('contabo_network_id', 50);
            $table->string('name', 100);
            $table->string('cidr', 20);
            $table->string('datacenter', 20);
            $table->json('connected_instances')->nullable();
            $table->timestamps();
            
            $table->index('service_id');
            $table->index('contabo_network_id');
        });

        Capsule::schema()->create('mod_contabo_vips', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->string('ip_address', 50);
            $table->string('contabo_resource_type', 20);
            $table->string('contabo_resource_id', 50);
            $table->string('datacenter', 20);
            $table->timestamps();
            
            $table->index('service_id');
            $table->index('ip_address');
        });

        Capsule::schema()->create('mod_contabo_images', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('contabo_image_id', 50);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('os_type', 50);
            $table->bigInteger('size_mb');
            $table->string('status', 20);
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('contabo_image_id');
        });

        Capsule::schema()->create('mod_contabo_cloud_init_templates', function ($table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->text('template_content');
            $table->json('configurable_vars');
            $table->boolean('is_public')->default(true);
            $table->integer('created_by');
            $table->timestamps();
        });

        Capsule::schema()->create('mod_contabo_api_logs', function ($table) {
            $table->increments('id');
            $table->string('action', 100);
            $table->string('method', 10);
            $table->text('endpoint');
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->integer('response_code')->nullable();
            $table->string('request_id', 50)->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('created_at');
            
            $table->index('action');
            $table->index('user_id');
            $table->index('created_at');
        });

        // Backup management tables
        Capsule::schema()->create('mod_contabo_backups', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50);
            $table->json('config');
            $table->string('status', 50)->default('active');
            $table->timestamps();
            
            $table->unique('instance_id');
            $table->index('status');
        });

        Capsule::schema()->create('mod_contabo_backup_restores', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50);
            $table->json('restore_data');
            $table->string('status', 50)->default('in_progress');
            $table->timestamp('estimated_completion')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('instance_id');
            $table->index('status');
        });

        // Billing integration table
        Capsule::schema()->create('mod_contabo_billing_items', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->string('instance_id', 50);
            $table->string('service_type', 50); // backup, additional_ip, storage_overage, etc.
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('billing_period', 20)->default('monthly');
            $table->string('billing_month', 7); // YYYY-MM format
            $table->json('config_data')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            
            $table->index(['service_id', 'billing_month']);
            $table->index(['instance_id', 'service_type']);
            $table->index('billing_month');
            $table->index('status');
        });

        // Firewall configuration table
        Capsule::schema()->create('mod_contabo_firewall_configs', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50)->unique();
            $table->string('status', 20)->default('active');
            $table->json('rules');
            $table->timestamp('last_sync')->nullable();
            $table->timestamps();
            
            $table->index('instance_id');
            $table->index('status');
        });

        // Server metrics table
        Capsule::schema()->create('mod_contabo_server_metrics', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50);
            $table->timestamp('timestamp');
            $table->string('status', 20);
            $table->decimal('cpu_usage_percent', 5, 2)->nullable();
            $table->integer('memory_total_mb')->nullable();
            $table->integer('memory_used_mb')->nullable();
            $table->decimal('memory_usage_percent', 5, 2)->nullable();
            $table->integer('disk_total_mb')->nullable();
            $table->integer('disk_used_mb')->nullable();
            $table->decimal('disk_usage_percent', 5, 2)->nullable();
            $table->bigInteger('network_bytes_in')->nullable();
            $table->bigInteger('network_bytes_out')->nullable();
            $table->integer('uptime_seconds')->nullable();
            $table->decimal('load_average_1min', 5, 2)->nullable();
            $table->decimal('response_time_ms', 8, 2)->nullable();
            $table->boolean('is_online')->default(false);
            
            $table->index(['instance_id', 'timestamp']);
            $table->index('timestamp');
            $table->index('is_online');
        });

        // Monitoring alerts table
        Capsule::schema()->create('mod_contabo_monitoring_alerts', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50);
            $table->string('alert_type', 30); // cpu, memory, disk, uptime, response_time
            $table->string('metric_name', 100);
            $table->string('condition', 20); // greater_than, less_than, equals
            $table->decimal('threshold_value', 10, 2);
            $table->integer('duration_minutes')->default(5);
            $table->string('notification_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered')->nullable();
            $table->integer('trigger_count')->default(0);
            $table->string('created_by', 50)->default('user');
            $table->timestamps();
            
            $table->index(['instance_id', 'alert_type']);
            $table->index('is_active');
            $table->index('last_triggered');
        });

        // Alert history table
        Capsule::schema()->create('mod_contabo_alert_history', function ($table) {
            $table->increments('id');
            $table->integer('alert_id');
            $table->string('instance_id', 50);
            $table->string('alert_type', 30);
            $table->decimal('metric_value', 10, 2);
            $table->decimal('threshold_value', 10, 2);
            $table->text('message');
            $table->timestamp('triggered_at');
            
            $table->index(['instance_id', 'triggered_at']);
            $table->index('alert_id');
            $table->index('triggered_at');
        });

        // Connectivity tests table
        Capsule::schema()->create('mod_contabo_connectivity_tests', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50);
            $table->string('ip_address', 45);
            $table->json('test_results');
            $table->integer('health_score');
            $table->string('overall_status', 20);
            $table->timestamp('created_at');
            
            $table->index(['instance_id', 'created_at']);
            $table->index('health_score');
            $table->index('overall_status');
        });

        // DNS zones table
        Capsule::schema()->create('mod_contabo_dns_zones', function ($table) {
            $table->increments('id');
            $table->string('domain_name', 255)->unique();
            $table->string('status', 20)->default('active');
            $table->json('nameservers');
            $table->integer('ttl')->default(3600);
            $table->integer('refresh')->default(3600);
            $table->integer('retry')->default(1800);
            $table->integer('expire')->default(1209600);
            $table->integer('minimum')->default(300);
            $table->timestamps();
            
            $table->index('domain_name');
            $table->index('status');
        });

        // DNS records table
        Capsule::schema()->create('mod_contabo_dns_records', function ($table) {
            $table->increments('id');
            $table->integer('zone_id');
            $table->string('name', 255);
            $table->string('type', 10);
            $table->text('content');
            $table->integer('ttl')->default(3600);
            $table->integer('priority')->nullable();
            $table->boolean('disabled')->default(false);
            $table->timestamps();
            
            $table->index(['zone_id', 'type']);
            $table->index(['zone_id', 'name']);
            $table->index('type');
            $table->foreign('zone_id')->references('id')->on('mod_contabo_dns_zones')->onDelete('cascade');
        });

        // Auto-scaling policies table
        Capsule::schema()->create('mod_contabo_scaling_policies', function ($table) {
            $table->increments('id');
            $table->string('instance_id', 50);
            $table->string('policy_name', 255);
            $table->string('policy_type', 20); // scale_up, scale_down
            $table->string('metric_type', 20); // cpu, memory, network, disk
            $table->decimal('threshold_value', 8, 2);
            $table->integer('threshold_duration')->default(300);
            $table->string('scaling_action', 50); // upgrade_plan, add_resources
            $table->json('target_configuration');
            $table->integer('cooldown_period')->default(1800);
            $table->boolean('is_active')->default(true);
            $table->string('notification_email')->nullable();
            $table->integer('max_scale_actions')->default(3);
            $table->timestamp('last_triggered')->nullable();
            $table->integer('trigger_count')->default(0);
            $table->string('created_by', 50)->default('user');
            $table->timestamps();
            
            $table->index(['instance_id', 'is_active']);
            $table->index('policy_type');
            $table->index('metric_type');
        });

        // Auto-scaling history table
        Capsule::schema()->create('mod_contabo_scaling_history', function ($table) {
            $table->increments('id');
            $table->integer('policy_id')->nullable();
            $table->string('action_type', 20); // scale_up, scale_down, manual
            $table->decimal('metric_value', 8, 2)->nullable();
            $table->decimal('threshold_value', 8, 2)->nullable();
            $table->json('old_configuration');
            $table->json('new_configuration');
            $table->string('status', 20); // success, failed, in_progress
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at');
            $table->timestamp('completed_at')->nullable();
            
            $table->index(['policy_id', 'executed_at']);
            $table->index('status');
            $table->index('executed_at');
        });

        // Support ticket rules table
        Capsule::schema()->create('mod_contabo_support_rules', function ($table) {
            $table->increments('id');
            $table->string('rule_name', 255);
            $table->string('instance_id', 50)->nullable(); // null for global rules
            $table->string('trigger_condition', 50); // server_down, high_cpu, etc.
            $table->json('condition_parameters')->nullable();
            $table->integer('ticket_department')->default(1);
            $table->string('ticket_priority', 20)->default('Medium');
            $table->string('ticket_subject_template', 500);
            $table->text('ticket_message_template');
            $table->integer('auto_assign_admin')->nullable();
            $table->integer('escalation_time')->nullable(); // minutes
            $table->integer('max_tickets_per_day')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered')->nullable();
            $table->integer('trigger_count')->default(0);
            $table->string('created_by', 50)->default('admin');
            $table->timestamps();
            
            $table->index(['instance_id', 'is_active']);
            $table->index('trigger_condition');
            $table->index('is_active');
        });

        // Support ticket history table
        Capsule::schema()->create('mod_contabo_support_history', function ($table) {
            $table->increments('id');
            $table->integer('rule_id')->nullable();
            $table->string('instance_id', 50)->nullable();
            $table->integer('ticket_id');
            $table->string('subject', 500);
            $table->string('priority', 20);
            $table->integer('department_id');
            $table->json('trigger_data')->nullable();
            $table->string('status', 20); // created, updated, closed
            $table->timestamps();
            
            $table->index(['rule_id', 'created_at']);
            $table->index(['instance_id', 'created_at']);
            $table->index('ticket_id');
            $table->index('status');
        });

        // Load balancers table
        Capsule::schema()->create('mod_contabo_load_balancers', function ($table) {
            $table->increments('id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('algorithm', 50); // round_robin, least_connections, ip_hash
            $table->string('protocol', 20); // http, https, tcp
            $table->integer('frontend_port');
            $table->integer('backend_port');
            $table->string('public_ip', 45);
            $table->integer('ssl_certificate_id')->nullable();
            $table->boolean('session_persistence')->default(false);
            $table->boolean('health_check_enabled')->default(true);
            $table->string('health_check_path', 255)->default('/');
            $table->integer('health_check_interval')->default(30); // seconds
            $table->integer('health_check_timeout')->default(5); // seconds
            $table->integer('health_check_retries')->default(3);
            $table->boolean('is_active')->default(true);
            $table->json('configuration')->nullable();
            $table->string('created_by', 50)->default('admin');
            $table->timestamps();
            
            $table->index('name');
            $table->index('is_active');
            $table->index('algorithm');
            $table->index('protocol');
        });

        // Load balancer servers table
        Capsule::schema()->create('mod_contabo_load_balancer_servers', function ($table) {
            $table->increments('id');
            $table->integer('load_balancer_id');
            $table->string('instance_id', 50);
            $table->string('private_ip', 45);
            $table->integer('weight')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('health_status', 20)->default('unknown'); // healthy, unhealthy, unknown
            $table->timestamp('last_health_check')->nullable();
            $table->timestamps();
            
            $table->index(['load_balancer_id', 'instance_id']);
            $table->index('health_status');
            $table->index('is_active');
            $table->foreign('load_balancer_id')->references('id')->on('mod_contabo_load_balancers')->onDelete('cascade');
        });

        // Load balancer health checks table
        Capsule::schema()->create('mod_contabo_load_balancer_health_checks', function ($table) {
            $table->increments('id');
            $table->integer('load_balancer_id');
            $table->integer('server_id');
            $table->string('instance_id', 50);
            $table->string('status', 20); // healthy, unhealthy
            $table->integer('response_time_ms')->nullable();
            $table->timestamp('checked_at');
            
            $table->index(['load_balancer_id', 'checked_at']);
            $table->index(['server_id', 'checked_at']);
            $table->index('status');
        });

        // System incidents table
        Capsule::schema()->create('mod_contabo_incidents', function ($table) {
            $table->increments('id');
            $table->string('title', 255);
            $table->text('description');
            $table->string('severity', 20); // low, medium, high, critical
            $table->string('status', 30); // investigating, identified, monitoring, resolved
            $table->json('affected_services')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('created_by', 50);
            
            $table->index('status');
            $table->index('severity');
            $table->index(['created_at', 'status']);
        });

        // System incident updates table
        Capsule::schema()->create('mod_contabo_incident_updates', function ($table) {
            $table->increments('id');
            $table->integer('incident_id');
            $table->string('status', 30);
            $table->text('message');
            $table->timestamp('created_at');
            
            $table->index('incident_id');
            $table->foreign('incident_id')->references('id')->on('mod_contabo_incidents')->onDelete('cascade');
        });

        // Planned maintenance table
        Capsule::schema()->create('mod_contabo_maintenance', function ($table) {
            $table->increments('id');
            $table->string('title', 255);
            $table->text('description');
            $table->string('status', 30); // scheduled, in_progress, completed, cancelled
            $table->json('affected_services')->nullable();
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->string('created_by', 50);
            $table->timestamps();
            
            $table->index('status');
            $table->index(['scheduled_start', 'status']);
        });

        // Insert default cloud-init templates
        $defaultTemplates = [
            [
                'name' => 'Basic Ubuntu Setup',
                'description' => 'Basic Ubuntu server setup with essential packages',
                'template_content' => file_get_contents(__DIR__ . '/templates/cloud-init/basic-ubuntu.yml'),
                'configurable_vars' => json_encode([
                    'timezone' => ['type' => 'text', 'default' => 'UTC', 'description' => 'Server timezone'],
                    'packages' => ['type' => 'textarea', 'default' => "curl\nwget\nufw", 'description' => 'Additional packages to install']
                ]),
                'is_public' => true,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'name' => 'CloudPanel + n8n',
                'description' => 'CloudPanel with n8n automation platform',
                'template_content' => file_get_contents(__DIR__ . '/../cloud-init-contabo-cloudpanel-n8n.yaml'),
                'configurable_vars' => json_encode([
                    'N8N_DOMAIN_OVERRIDE' => ['type' => 'text', 'default' => '', 'description' => 'Custom domain for n8n (optional)'],
                    'DB_ENGINE' => ['type' => 'select', 'options' => ['MYSQL_8.4', 'MYSQL_5.7'], 'default' => 'MYSQL_8.4', 'description' => 'Database engine']
                ]),
                'is_public' => true,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        foreach ($defaultTemplates as $template) {
            Capsule::table('mod_contabo_cloud_init_templates')->insert($template);
        }

        return [
            'status' => 'success',
            'description' => 'Contabo Addon activated successfully. Database tables created and default templates installed.',
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to activate addon: ' . $e->getMessage(),
        ];
    }
}

/**
 * Addon deactivation
 */
function contabo_addon_deactivate()
{
    try {
        // Note: We don't drop tables on deactivation to preserve data
        // Only remove hooks and temporary files
        
        return [
            'status' => 'success',
            'description' => 'Contabo Addon deactivated successfully.',
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to deactivate addon: ' . $e->getMessage(),
        ];
    }
}

/**
 * Admin area output
 */
function contabo_addon_output($vars)
{
    $action = $_GET['action'] ?? 'dashboard';
    
    echo '<div class="contabo-addon-wrapper">';
    echo '<link rel="stylesheet" type="text/css" href="' . $vars['modulelink'] . '&action=css" />';
    echo '<script type="text/javascript" src="' . $vars['modulelink'] . '&action=js"></script>';
    
    switch ($action) {
        case 'css':
            header('Content-Type: text/css');
            echo file_get_contents(__DIR__ . '/assets/css/admin.css');
            exit;
            
        case 'js':
            header('Content-Type: application/javascript');
            echo file_get_contents(__DIR__ . '/assets/js/admin.js');
            exit;
            
        case 'dashboard':
        default:
            include __DIR__ . '/templates/admin/dashboard.php';
            break;
            
        case 'instances':
            include __DIR__ . '/templates/admin/instances.php';
            break;
            
        case 'object-storage':
            include __DIR__ . '/templates/admin/object_storage.php';
            break;
            
        case 'networks':
            include __DIR__ . '/templates/admin/networks.php';
            break;
            
        case 'images':
            include __DIR__ . '/templates/admin/images.php';
            break;
            
        case 'cloud-init':
            include __DIR__ . '/templates/admin/cloud_init.php';
            break;
            
        case 'backups':
            include __DIR__ . '/templates/admin/backups.php';
            break;
            
        case 'vnc':
            include __DIR__ . '/templates/admin/vnc.php';
            break;
            
        case 'addons':
            include __DIR__ . '/templates/admin/addons.php';
            break;
            
        case 'applications':
            include __DIR__ . '/templates/admin/applications.php';
            break;
            
        case 'server-management':
            include __DIR__ . '/templates/admin/server_management.php';
            break;
            
        case 'secrets':
            include __DIR__ . '/templates/admin/secrets.php';
            break;
            
        case 'rebuild':
            include __DIR__ . '/templates/admin/rebuild.php';
            break;
            
        case 'billing':
            include __DIR__ . '/templates/admin/billing.php';
            break;
            
        case 'firewall':
            include __DIR__ . '/templates/admin/firewall.php';
            break;
            
        case 'monitoring':
            include __DIR__ . '/templates/admin/monitoring.php';
            break;
            
        case 'dns':
            include __DIR__ . '/templates/admin/dns.php';
            break;
            
        case 'scaling':
            include __DIR__ . '/templates/admin/scaling.php';
            break;
            
        case 'support':
            include __DIR__ . '/templates/admin/support.php';
            break;
            
        case 'load_balancer':
            include __DIR__ . '/templates/admin/load_balancer.php';
            break;
            
        case 'system_health':
            include __DIR__ . '/templates/admin/system_health.php';
            break;
            
        case 'settings':
            include __DIR__ . '/templates/admin/settings.php';
            break;
            
        case 'logs':
            include __DIR__ . '/templates/admin/logs.php';
            break;
    }
    
    echo '</div>';
}

/**
 * Client area output
 */
function contabo_addon_clientarea($vars)
{
    // This will be handled by hooks for better integration
    return [];
}

/**
 * Ajax handler for admin actions
 */
if ($_POST['action'] ?? false) {
    header('Content-Type: application/json');
    
    try {
        $apiClient = new \ContaboAddon\API\ContaboAPIClient($vars);
        
        switch ($_POST['action']) {
            case 'create_instance':
                $compute = new \ContaboAddon\Services\ComputeService($apiClient);
                $result = $compute->createInstance($_POST);
                break;
                
            case 'manage_instance':
                $compute = new \ContaboAddon\Services\ComputeService($apiClient);
                $result = $compute->manageInstance($_POST['instance_id'], $_POST['operation']);
                break;
                
            case 'create_object_storage':
                $storage = new \ContaboAddon\Services\ObjectStorageService($apiClient);
                $result = $storage->createObjectStorage($_POST);
                break;
                
            case 'create_private_network':
                $network = new \ContaboAddon\Services\NetworkingService($apiClient);
                $result = $network->createPrivateNetwork($_POST);
                break;
                
            case 'upload_image':
                $images = new \ContaboAddon\Services\ImageService($apiClient);
                $result = $images->uploadImage($_POST, $_FILES);
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
        echo json_encode(['success' => true, 'data' => $result]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit;
}
