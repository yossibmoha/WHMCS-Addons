<?php
/**
 * WHMCS Provisioning Hooks for Contabo Addon
 * 
 * Handles automatic provisioning, suspension, termination, and other service lifecycle events
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\ComputeService;
use ContaboAddon\Services\ObjectStorageService;
use ContaboAddon\Services\NetworkingService;
use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;

/**
 * After invoice paid hook
 * Triggers automatic provisioning when enabled
 */
add_hook('InvoicePaid', 1, function($vars) {
    $logHelper = new LogHelper();
    
    try {
        // Only proceed if auto provisioning is enabled
        if (!ConfigHelper::isFeatureEnabled('auto_provisioning')) {
            return;
        }

        $invoiceId = $vars['invoiceid'];
        
        // Get invoice items related to Contabo services
        $services = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.productid', '=', 'tblproducts.id')
            ->join('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
            ->where('tblhosting.invoiceid', $invoiceId)
            ->where('tblproducts.servertype', 'contabo_addon')
            ->where('tblhosting.domainstatus', 'Pending')
            ->select('tblhosting.*', 'tblproducts.name as product_name')
            ->get();

        foreach ($services as $service) {
            provision_contabo_service($service);
        }

        $logHelper->log('auto_provisioning_completed', [
            'invoice_id' => $invoiceId,
            'services_provisioned' => count($services)
        ]);

    } catch (Exception $e) {
        $logHelper->log('auto_provisioning_failed', [
            'invoice_id' => $invoiceId ?? null,
            'error' => $e->getMessage()
        ]);
    }
});

/**
 * Service creation hook
 * Handles manual provisioning requests
 */
add_hook('AfterModuleCreate', 1, function($vars) {
    if ($vars['producttype'] != 'hostingaccount' || !isset($vars['servertype'])) {
        return;
    }

    if ($vars['servertype'] !== 'contabo_addon') {
        return;
    }

    try {
        $service = Capsule::table('tblhosting')->where('id', $vars['serviceid'])->first();
        provision_contabo_service($service);

    } catch (Exception $e) {
        $logHelper = new LogHelper();
        $logHelper->log('manual_provisioning_failed', [
            'service_id' => $vars['serviceid'],
            'error' => $e->getMessage()
        ]);
        
        // Update service with error
        Capsule::table('tblhosting')
            ->where('id', $vars['serviceid'])
            ->update([
                'domainstatus' => 'Failed',
                'notes' => 'Provisioning failed: ' . $e->getMessage()
            ]);
    }
});

/**
 * Service suspension hook
 */
add_hook('AfterModuleSuspend', 1, function($vars) {
    if ($vars['producttype'] != 'hostingaccount' || $vars['servertype'] !== 'contabo_addon') {
        return;
    }

    try {
        suspend_contabo_service($vars['serviceid']);

    } catch (Exception $e) {
        $logHelper = new LogHelper();
        $logHelper->log('service_suspension_failed', [
            'service_id' => $vars['serviceid'],
            'error' => $e->getMessage()
        ]);
    }
});

/**
 * Service unsuspension hook
 */
add_hook('AfterModuleUnsuspend', 1, function($vars) {
    if ($vars['producttype'] != 'hostingaccount' || $vars['servertype'] !== 'contabo_addon') {
        return;
    }

    try {
        unsuspend_contabo_service($vars['serviceid']);

    } catch (Exception $e) {
        $logHelper = new LogHelper();
        $logHelper->log('service_unsuspension_failed', [
            'service_id' => $vars['serviceid'],
            'error' => $e->getMessage()
        ]);
    }
});

/**
 * Service termination hook
 */
add_hook('AfterModuleTerminate', 1, function($vars) {
    if ($vars['producttype'] != 'hostingaccount' || $vars['servertype'] !== 'contabo_addon') {
        return;
    }

    try {
        terminate_contabo_service($vars['serviceid']);

    } catch (Exception $e) {
        $logHelper = new LogHelper();
        $logHelper->log('service_termination_failed', [
            'service_id' => $vars['serviceid'],
            'error' => $e->getMessage()
        ]);
    }
});

/**
 * Daily maintenance hook
 */
add_hook('DailyCronJob', 1, function($vars) {
    try {
        // Sync service statuses
        sync_service_statuses();
        
        // Clean old logs
        $logHelper = new LogHelper();
        $logHelper->cleanOldLogs(90); // Keep 90 days of logs
        
        // Update usage statistics
        update_usage_statistics();

    } catch (Exception $e) {
        error_log('Contabo daily maintenance failed: ' . $e->getMessage());
    }
});

/**
 * Provision a Contabo service
 */
function provision_contabo_service($service) {
    $logHelper = new LogHelper();
    $apiClient = new ContaboAPIClient(ConfigHelper::getApiCredentials());
    
    try {
        // Get service configurable options
        $configOptions = get_service_config_options($service->id);
        
        // Determine service type based on product configuration
        $serviceType = determine_service_type($service, $configOptions);
        
        switch ($serviceType) {
            case 'compute':
                provision_compute_instance($service, $configOptions, $apiClient);
                break;
                
            case 'object_storage':
                provision_object_storage($service, $configOptions, $apiClient);
                break;
                
            case 'private_network':
                provision_private_network($service, $configOptions, $apiClient);
                break;
                
            default:
                throw new Exception("Unknown service type: {$serviceType}");
        }

        // Update service status
        Capsule::table('tblhosting')
            ->where('id', $service->id)
            ->update([
                'domainstatus' => 'Active',
                'notes' => 'Provisioned successfully on ' . date('Y-m-d H:i:s')
            ]);

        // Send welcome email
        send_welcome_email($service->id);

        $logHelper->log('service_provisioned', [
            'service_id' => $service->id,
            'service_type' => $serviceType
        ]);

    } catch (Exception $e) {
        // Update service with error status
        Capsule::table('tblhosting')
            ->where('id', $service->id)
            ->update([
                'domainstatus' => 'Failed',
                'notes' => 'Provisioning failed: ' . $e->getMessage()
            ]);

        $logHelper->log('service_provisioning_failed', [
            'service_id' => $service->id,
            'error' => $e->getMessage()
        ]);

        throw $e;
    }
}

/**
 * Provision compute instance
 */
function provision_compute_instance($service, $configOptions, $apiClient) {
    $computeService = new ComputeService($apiClient);
    
    // Prepare instance data
    $instanceData = [
        'service_id' => $service->id,
        'imageId' => $configOptions['operating_system'] ?? 'ubuntu-22.04',
        'productId' => $configOptions['instance_type'] ?? 'vps-s',
        'region' => $configOptions['datacenter'] ?? ConfigHelper::getDefaultDataCenter(),
        'displayName' => $service->domain ?: "WHMCS Service #{$service->id}",
        'privateNetworking' => $configOptions['private_networking'] ?? 'no'
    ];

    // Add SSH keys if provided
    if (!empty($configOptions['ssh_keys'])) {
        $instanceData['sshKeys'] = array_filter(explode("\n", $configOptions['ssh_keys']));
    }

    // Process cloud-init script
    if (!empty($configOptions['cloud_init_script'])) {
        $instanceData['userData'] = $configOptions['cloud_init_script'];
    }

    // Create the instance
    $result = $computeService->createInstance($instanceData);
    
    if (!$result['success']) {
        throw new Exception('Failed to create instance: ' . ($result['error'] ?? 'Unknown error'));
    }

    return $result;
}

/**
 * Provision object storage
 */
function provision_object_storage($service, $configOptions, $apiClient) {
    $storageService = new ObjectStorageService($apiClient);
    
    $storageData = [
        'service_id' => $service->id,
        'region' => $configOptions['region'] ?? ConfigHelper::getDefaultDataCenter(),
        'totalPurchasedSpaceInGb' => (int)($configOptions['storage_size'] ?? 250),
        'displayName' => $service->domain ?: "WHMCS Storage #{$service->id}"
    ];

    // Add auto-scaling if enabled
    if (($configOptions['auto_scaling'] ?? 'no') === 'yes') {
        $storageData['autoScaling'] = [
            'state' => 'enabled',
            'sizeLimitTb' => (int)($configOptions['auto_scaling_limit'] ?? 10000) / 1024
        ];
    }

    $result = $storageService->createObjectStorage($storageData);
    
    if (!$result['success']) {
        throw new Exception('Failed to create object storage: ' . ($result['error'] ?? 'Unknown error'));
    }

    return $result;
}

/**
 * Provision private network
 */
function provision_private_network($service, $configOptions, $apiClient) {
    $networkService = new NetworkingService($apiClient);
    
    $networkData = [
        'service_id' => $service->id,
        'name' => $service->domain ?: "WHMCS Network #{$service->id}",
        'cidr' => $configOptions['network_cidr'] ?? '10.0.0.0/24',
        'region' => $configOptions['datacenter'] ?? ConfigHelper::getDefaultDataCenter()
    ];

    $result = $networkService->createPrivateNetwork($networkData);
    
    if (!$result['success']) {
        throw new Exception('Failed to create private network: ' . ($result['error'] ?? 'Unknown error'));
    }

    return $result;
}

/**
 * Suspend Contabo service
 */
function suspend_contabo_service($serviceId) {
    $apiClient = new ContaboAPIClient(ConfigHelper::getApiCredentials());
    $computeService = new ComputeService($apiClient);
    
    // Get service instances
    $instances = $computeService->getServiceInstances($serviceId);
    
    foreach ($instances as $instance) {
        try {
            $computeService->manageInstance($instance->contabo_instance_id, 'stop');
        } catch (Exception $e) {
            // Log but continue with other instances
            $logHelper = new LogHelper();
            $logHelper->log('instance_suspension_failed', [
                'service_id' => $serviceId,
                'instance_id' => $instance->contabo_instance_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}

/**
 * Unsuspend Contabo service
 */
function unsuspend_contabo_service($serviceId) {
    $apiClient = new ContaboAPIClient(ConfigHelper::getApiCredentials());
    $computeService = new ComputeService($apiClient);
    
    // Get service instances
    $instances = $computeService->getServiceInstances($serviceId);
    
    foreach ($instances as $instance) {
        try {
            $computeService->manageInstance($instance->contabo_instance_id, 'start');
        } catch (Exception $e) {
            $logHelper = new LogHelper();
            $logHelper->log('instance_unsuspension_failed', [
                'service_id' => $serviceId,
                'instance_id' => $instance->contabo_instance_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}

/**
 * Terminate Contabo service
 */
function terminate_contabo_service($serviceId) {
    $apiClient = new ContaboAPIClient(ConfigHelper::getApiCredentials());
    $computeService = new ComputeService($apiClient);
    $storageService = new ObjectStorageService($apiClient);
    $networkService = new NetworkingService($apiClient);
    
    // Terminate instances
    $instances = $computeService->getServiceInstances($serviceId);
    foreach ($instances as $instance) {
        try {
            $computeService->cancelInstance($instance->contabo_instance_id, true);
        } catch (Exception $e) {
            // Log error but continue
            error_log("Failed to terminate instance {$instance->contabo_instance_id}: " . $e->getMessage());
        }
    }

    // Cancel object storages
    $storages = $storageService->getServiceObjectStorages($serviceId);
    foreach ($storages as $storage) {
        try {
            $storageService->cancelObjectStorage($storage->contabo_storage_id);
        } catch (Exception $e) {
            error_log("Failed to cancel storage {$storage->contabo_storage_id}: " . $e->getMessage());
        }
    }

    // Delete private networks
    $networks = $networkService->getServiceNetworks($serviceId);
    foreach ($networks as $network) {
        try {
            $networkService->deletePrivateNetwork($network->contabo_network_id);
        } catch (Exception $e) {
            error_log("Failed to delete network {$network->contabo_network_id}: " . $e->getMessage());
        }
    }
}

/**
 * Get service configurable options
 */
function get_service_config_options($serviceId) {
    $options = [];
    
    $configOptions = Capsule::table('tblhostingconfigoptions')
        ->join('tblproductconfigoptions', 'tblhostingconfigoptions.configid', '=', 'tblproductconfigoptions.id')
        ->where('tblhostingconfigoptions.relid', $serviceId)
        ->select('tblproductconfigoptions.optionname', 'tblhostingconfigoptions.qty', 'tblhostingconfigoptions.optionvalue')
        ->get();

    foreach ($configOptions as $option) {
        $key = strtolower(str_replace(' ', '_', $option->optionname));
        $options[$key] = $option->optionvalue ?: $option->qty;
    }

    return $options;
}

/**
 * Determine service type from product configuration
 */
function determine_service_type($service, $configOptions) {
    // Check product name or custom fields to determine type
    $productName = strtolower($service->product_name ?? '');
    
    if (strpos($productName, 'storage') !== false) {
        return 'object_storage';
    } elseif (strpos($productName, 'network') !== false) {
        return 'private_network';
    } else {
        return 'compute'; // Default to compute instance
    }
}

/**
 * Send welcome email to client
 */
function send_welcome_email($serviceId) {
    try {
        $service = Capsule::table('tblhosting')
            ->join('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
            ->where('tblhosting.id', $serviceId)
            ->select('tblhosting.*', 'tblclients.email', 'tblclients.firstname', 'tblclients.lastname')
            ->first();

        if (!$service) {
            return;
        }

        // Get service details for email
        $instances = Capsule::table('mod_contabo_instances')
            ->where('service_id', $serviceId)
            ->get();

        $emailData = [
            'service_id' => $serviceId,
            'client_name' => $service->firstname . ' ' . $service->lastname,
            'instances' => $instances
        ];

        // Use WHMCS email system
        sendMessage('Contabo Service Welcome', $serviceId, $emailData);

    } catch (Exception $e) {
        error_log('Failed to send welcome email: ' . $e->getMessage());
    }
}

/**
 * Sync service statuses with Contabo API
 */
function sync_service_statuses() {
    $apiClient = new ContaboAPIClient(ConfigHelper::getApiCredentials());
    $computeService = new ComputeService($apiClient);
    
    // Get all active instances
    $instances = Capsule::table('mod_contabo_instances')
        ->whereIn('status', ['running', 'stopped', 'provisioning'])
        ->get();

    foreach ($instances as $instance) {
        try {
            $apiInstance = $computeService->getInstance($instance->contabo_instance_id, true);
            // Status gets updated automatically by the getInstance method
        } catch (Exception $e) {
            // Log error and continue
            error_log("Failed to sync instance {$instance->contabo_instance_id}: " . $e->getMessage());
        }
    }
}

/**
 * Update usage statistics
 */
function update_usage_statistics() {
    $stats = [
        'total_instances' => Capsule::table('mod_contabo_instances')->count(),
        'active_instances' => Capsule::table('mod_contabo_instances')->where('status', 'running')->count(),
        'total_storages' => Capsule::table('mod_contabo_object_storages')->count(),
        'total_networks' => Capsule::table('mod_contabo_private_networks')->count(),
        'last_updated' => date('Y-m-d H:i:s')
    ];

    // Store stats in addon configuration or custom table
    foreach ($stats as $key => $value) {
        Capsule::table('tbladdonmodules')
            ->updateOrInsert(
                ['module' => 'contabo_addon', 'setting' => "stat_{$key}"],
                ['value' => $value]
            );
    }
}
