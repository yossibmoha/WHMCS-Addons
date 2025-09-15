<?php
/**
 * WHMCS Client Area Display Hook for Contabo Services
 * 
 * This hook enhances the client area display for Contabo services
 * with modern interface and advanced features
 */

use WHMCS\Database\Capsule;

// Hook into client area page display
add_hook('ClientAreaPage', 1, function($vars) {
    // Only modify if we're on a product details page
    if ($vars['filename'] !== 'clientarea' || !isset($_GET['action']) || $_GET['action'] !== 'productdetails') {
        return [];
    }

    $serviceId = $_GET['id'] ?? null;
    if (!$serviceId) {
        return [];
    }

    // Check if this is a Contabo service
    $service = Capsule::table('tblhosting')
        ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->where('tblhosting.id', $serviceId)
        ->select('tblhosting.*', 'tblproducts.name as product_name', 'tblproducts.servertype')
        ->first();

    if (!$service) {
        return [];
    }

    // Check if this service has a Contabo instance
    $contaboInstance = Capsule::table('mod_contabo_instances')
        ->where('service_id', $serviceId)
        ->first();

    // Only modify if this is a Contabo service (has instance or contains "contabo" in name)
    $isContaboService = $contaboInstance || 
                       stripos($service->product_name, 'contabo') !== false ||
                       stripos($service->product_name, 'cloudcore') !== false ||
                       stripos($service->product_name, 'vps') !== false ||
                       stripos($service->product_name, 'cloud') !== false;

    if (!$isContaboService) {
        return [];
    }

    // Return variables to enhance the display
    return [
        'contabo_service' => true,
        'contabo_instance_id' => $contaboInstance ? $contaboInstance->contabo_instance_id : null,
        'modern_interface_enabled' => true
    ];
});

// Hook to add custom CSS for client area
add_hook('ClientAreaHeadOutput', 1, function($vars) {
    if ($vars['filename'] === 'clientarea' && isset($_GET['action']) && $_GET['action'] === 'productdetails') {
        $serviceId = $_GET['id'] ?? null;
        if ($serviceId) {
            // Check if this is a Contabo service
            $contaboInstance = Capsule::table('mod_contabo_instances')
                ->where('service_id', $serviceId)
                ->first();
            
            if ($contaboInstance) {
                return '
                <style>
                /* Modern Contabo Client Area Styles */
                .contabo-modern-wrapper {
                    background: #f8f9fa;
                    min-height: 500px;
                    border-radius: 8px;
                    overflow: hidden;
                }
                .contabo-header-banner {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 20px;
                    margin: -15px -15px 20px -15px;
                }
                .contabo-feature-pills {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-top: 12px;
                }
                .contabo-feature-pill {
                    background: rgba(255,255,255,0.2);
                    padding: 4px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 500;
                }
                .modern-card {
                    background: white;
                    border: none;
                    border-radius: 12px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    margin-bottom: 20px;
                    overflow: hidden;
                    transition: all 0.3s ease;
                }
                .modern-card:hover {
                    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                }
                .modern-btn {
                    border-radius: 8px;
                    font-weight: 500;
                    padding: 8px 16px;
                    transition: all 0.3s ease;
                    border: none;
                    cursor: pointer;
                }
                .modern-btn:hover {
                    transform: translateY(-1px);
                }
                .status-indicator {
                    display: inline-flex;
                    align-items: center;
                    padding: 4px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                .status-running { background: #d4edda; color: #155724; }
                .status-stopped { background: #f8d7da; color: #721c24; }
                .status-pending { background: #fff3cd; color: #856404; }
                @media (max-width: 768px) {
                    .contabo-feature-pills {
                        justify-content: center;
                    }
                }
                </style>';
            }
        }
    }
    return '';
});

// Hook to modify client area content
add_hook('ClientAreaProductDetails', 1, function($vars) {
    $serviceId = $vars['id'] ?? null;
    if (!$serviceId) {
        return $vars;
    }

    // Check if this is a Contabo service
    $contaboInstance = Capsule::table('mod_contabo_instances')
        ->where('service_id', $serviceId)
        ->first();

    if (!$contaboInstance) {
        return $vars;
    }

    try {
        // Load addon configuration
        $addonVars = [];
        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'contabo_addon')
            ->pluck('value', 'setting');
        
        foreach ($settings as $setting => $value) {
            $addonVars[$setting] = $value;
        }

        // Include the modern client interface
        ob_start();
        $vars['serviceid'] = $serviceId;
        include dirname(__DIR__) . '/templates/client/modern_overview.php';
        $modernInterface = ob_get_clean();

        // Replace or enhance the existing interface
        $vars['modern_contabo_interface'] = $modernInterface;
        $vars['show_modern_interface'] = true;
        
    } catch (Exception $e) {
        // Fallback to default interface
        error_log("Contabo modern interface error: " . $e->getMessage());
    }

    return $vars;
});

// Hook to add JavaScript functionality
add_hook('ClientAreaFooterOutput', 1, function($vars) {
    if ($vars['filename'] === 'clientarea' && isset($_GET['action']) && $_GET['action'] === 'productdetails') {
        $serviceId = $_GET['id'] ?? null;
        if ($serviceId) {
            // Check if this is a Contabo service
            $contaboInstance = Capsule::table('mod_contabo_instances')
                ->where('service_id', $serviceId)
                ->first();
            
            if ($contaboInstance) {
                return '
                <script>
                // Modern Contabo Client Area JavaScript
                document.addEventListener("DOMContentLoaded", function() {
                    // Enhance existing interface or replace it
                    const productDetailsContent = document.querySelector(".product-details-tab-container");
                    if (productDetailsContent) {
                        productDetailsContent.style.background = "#f8f9fa";
                        productDetailsContent.style.borderRadius = "8px";
                        productDetailsContent.style.overflow = "hidden";
                    }
                    
                    // Add modern styling to buttons
                    const buttons = document.querySelectorAll(".btn");
                    buttons.forEach(btn => {
                        if (!btn.classList.contains("modern-btn")) {
                            btn.classList.add("modern-btn");
                        }
                    });
                    
                    // Enhance status displays
                    const statusElements = document.querySelectorAll(".label, .badge");
                    statusElements.forEach(element => {
                        const text = element.textContent.toLowerCase();
                        if (text.includes("active") || text.includes("running")) {
                            element.classList.add("status-running");
                        } else if (text.includes("suspended") || text.includes("stopped")) {
                            element.classList.add("status-stopped");
                        } else if (text.includes("pending")) {
                            element.classList.add("status-pending");
                        }
                    });
                });
                
                // AJAX functions for server management
                function contaboAjaxRequest(action, data, callback) {
                    fetch(window.location.href + "&ajax=1&action=" + action, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify(data || {})
                    })
                    .then(response => response.json())
                    .then(callback)
                    .catch(error => {
                        console.error("Contabo AJAX Error:", error);
                        alert("An error occurred. Please try again.");
                    });
                }
                
                // Make functions globally available
                window.contaboAjaxRequest = contaboAjaxRequest;
                </script>';
            }
        }
    }
    return '';
});

// Hook for AJAX requests
add_hook('ClientAreaPage', 1, function($vars) {
    if ($vars['filename'] === 'clientarea' && isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        $serviceId = $_GET['id'] ?? null;
        $action = $_GET['action'] ?? null;
        
        if (!$serviceId || !$action) {
            return [];
        }

        // Check if this is a Contabo service
        $contaboInstance = Capsule::table('mod_contabo_instances')
            ->where('service_id', $serviceId)
            ->first();

        if (!$contaboInstance) {
            return [];
        }

        header('Content-Type: application/json');
        
        try {
            // Load services
            require_once dirname(__DIR__) . '/classes/API/ContaboAPIClient.php';
            require_once dirname(__DIR__) . '/classes/Services/ComputeService.php';
            require_once dirname(__DIR__) . '/classes/Services/RebuildService.php';
            require_once dirname(__DIR__) . '/classes/Helpers/ConfigHelper.php';
            require_once dirname(__DIR__) . '/classes/Helpers/LogHelper.php';

            $settings = Capsule::table('tbladdonmodules')
                ->where('module', 'contabo_addon')
                ->pluck('value', 'setting');

            $config = new \ContaboAddon\Helpers\ConfigHelper($settings);
            $log = new \ContaboAddon\Helpers\LogHelper();
            $apiClient = new \ContaboAddon\API\ContaboAPIClient(
                $config->getClientId(),
                $config->getClientSecret(),
                $config->getApiUser(),
                $config->getApiPassword(),
                $log
            );

            $computeService = new \ContaboAddon\Services\ComputeService($apiClient, $log);
            $rebuildService = new \ContaboAddon\Services\RebuildService($apiClient);

            switch ($action) {
                case 'start':
                    $result = $computeService->startInstance($contaboInstance->contabo_instance_id);
                    echo json_encode(['success' => true, 'message' => 'Server started successfully']);
                    break;
                    
                case 'stop':
                    $result = $computeService->stopInstance($contaboInstance->contabo_instance_id);
                    echo json_encode(['success' => true, 'message' => 'Server stopped successfully']);
                    break;
                    
                case 'restart':
                    $result = $computeService->restartInstance($contaboInstance->contabo_instance_id);
                    echo json_encode(['success' => true, 'message' => 'Server restarted successfully']);
                    break;
                    
                case 'resetPassword':
                    $result = $computeService->resetInstancePassword($contaboInstance->contabo_instance_id);
                    echo json_encode(['success' => true, 'message' => 'Password reset successfully', 'password' => 'Check your email']);
                    break;

                case 'getOperatingSystems':
                    $operatingSystems = $rebuildService->getAvailableOperatingSystems();
                    echo json_encode(['success' => true, 'operating_systems' => $operatingSystems]);
                    break;

                case 'rebuildServer':
                    $input = json_decode(file_get_contents('php://input'), true);
                    $rebuildData = [
                        'imageId' => $input['imageId'] ?? null,
                        'keepSSHKeys' => $input['keepSSHKeys'] ?? false,
                        'useCloudInit' => $input['useCloudInit'] ?? false
                    ];
                    
                    if (empty($rebuildData['imageId'])) {
                        echo json_encode(['success' => false, 'error' => 'Operating system selection is required']);
                        break;
                    }
                    
                    $result = $rebuildService->rebuildInstance(
                        $contaboInstance->contabo_instance_id, 
                        $rebuildData, 
                        false // isAdmin = false for client requests
                    );
                    
                    echo json_encode($result);
                    break;

                case 'getRebuildStatus':
                    $status = $rebuildService->getRebuildStatus($contaboInstance->contabo_instance_id);
                    echo json_encode(['success' => true, 'status' => $status]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'error' => 'Unknown action']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        exit;
    }
    
    return [];
});
?>
