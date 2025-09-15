<?php
/**
 * System Status Display Hook
 * 
 * Displays system health status in the client area
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\SystemHealthService;
use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;

add_hook('ClientAreaPrimaryNavbar', 1, function($vars) {
    try {
        // Only show on certain pages to avoid performance impact
        $allowedTemplates = ['homepage', 'clientarea', 'account-summary'];
        if (!in_array($vars['templatename'], $allowedTemplates)) {
            return [];
        }

        // Check if Contabo addon is active
        $addonSettings = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'contabo_addon')
            ->where('setting', 'version')
            ->first();

        if (!$addonSettings) {
            return [];
        }

        // Get basic system health status (cached for performance)
        $cacheKey = 'contabo_system_health_status';
        $cacheTime = 300; // 5 minutes

        $healthStatus = null;
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success) {
                $healthStatus = json_decode($cached, true);
            }
        }

        if (!$healthStatus) {
            // Get minimal health status
            $healthStatus = [
                'overall_status' => 'operational',
                'active_incidents' => 0,
                'planned_maintenance' => 0
            ];

            try {
                $activeIncidents = \WHMCS\Database\Capsule::table('mod_contabo_incidents')
                    ->where('status', '!=', 'resolved')
                    ->count();

                $plannedMaintenance = \WHMCS\Database\Capsule::table('mod_contabo_maintenance')
                    ->where('status', 'in_progress')
                    ->orWhere(function($q) {
                        $q->where('status', 'scheduled')
                          ->where('scheduled_start', '<=', date('Y-m-d H:i:s', strtotime('+24 hours')));
                    })
                    ->count();

                $healthStatus['active_incidents'] = $activeIncidents;
                $healthStatus['planned_maintenance'] = $plannedMaintenance;

                if ($activeIncidents > 0) {
                    $healthStatus['overall_status'] = 'partial_outage';
                }

                // Cache the result
                if (function_exists('apcu_store')) {
                    apcu_store($cacheKey, json_encode($healthStatus), $cacheTime);
                }
            } catch (Exception $e) {
                // Fail silently - don't break the client area
            }
        }

        // Add system status to navbar if there are issues
        if ($healthStatus['active_incidents'] > 0 || $healthStatus['planned_maintenance'] > 0) {
            return [
                'primaryNavbar' => [
                    'system_status' => [
                        'name' => 'System Status',
                        'uri' => 'modules/addons/contabo_addon/public_status.php',
                        'order' => 100,
                        'icon' => 'fa-heartbeat',
                        'badge' => $healthStatus['active_incidents'] > 0 ? $healthStatus['active_incidents'] : null,
                        'badgeColor' => 'danger'
                    ]
                ]
            ];
        }

    } catch (Exception $e) {
        // Fail silently - don't break the client area
    }

    return [];
});

add_hook('ClientAreaHomepage', 1, function($vars) {
    try {
        // Check if Contabo addon is active
        $addonSettings = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'contabo_addon')
            ->where('setting', 'version')
            ->first();

        if (!$addonSettings) {
            return [];
        }

        // Get system health status for display
        $healthData = [
            'show_status' => false,
            'status_color' => 'success',
            'status_message' => 'All systems operational',
            'status_details' => []
        ];

        try {
            $activeIncidents = \WHMCS\Database\Capsule::table('mod_contabo_incidents')
                ->where('status', '!=', 'resolved')
                ->count();

            $plannedMaintenance = \WHMCS\Database\Capsule::table('mod_contabo_maintenance')
                ->where('status', 'in_progress')
                ->orWhere(function($q) {
                    $q->where('status', 'scheduled')
                      ->where('scheduled_start', '<=', date('Y-m-d H:i:s', strtotime('+24 hours')));
                })
                ->count();

            if ($activeIncidents > 0 || $plannedMaintenance > 0) {
                $healthData['show_status'] = true;
                
                if ($activeIncidents > 0) {
                    $healthData['status_color'] = 'warning';
                    $healthData['status_message'] = "{$activeIncidents} active incident(s)";
                    $healthData['status_details'][] = "We are currently investigating {$activeIncidents} service issue(s)";
                }
                
                if ($plannedMaintenance > 0) {
                    $healthData['status_color'] = 'info';
                    if ($activeIncidents == 0) {
                        $healthData['status_message'] = 'Planned maintenance scheduled';
                    }
                    $healthData['status_details'][] = "Scheduled maintenance may affect some services";
                }
            }

        } catch (Exception $e) {
            // Fail silently
        }

        if ($healthData['show_status']) {
            return [
                'systemHealthData' => $healthData
            ];
        }

    } catch (Exception $e) {
        // Fail silently - don't break the client area
    }

    return [];
});

add_hook('ClientAreaHeadOutput', 1, function($vars) {
    // Add system status CSS and JS to client area
    if (isset($vars['systemHealthData']) && $vars['systemHealthData']['show_status']) {
        return '
        <style>
        .system-status-banner {
            background-color: #f8f9fa;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .system-status-banner.warning {
            border-left-color: #ffc107;
            background-color: #fff3cd;
        }
        .system-status-banner.info {
            border-left-color: #17a2b8;
            background-color: #d1ecf1;
        }
        .system-status-banner h5 {
            margin-bottom: 5px;
            color: #495057;
        }
        .system-status-banner p {
            margin-bottom: 0;
            color: #6c757d;
        }
        .status-link {
            color: #007bff;
            text-decoration: none;
            font-weight: 500;
        }
        .status-link:hover {
            text-decoration: underline;
        }
        </style>
        
        <script>
        $(document).ready(function() {
            // Add system status banner to homepage
            if (window.location.pathname.includes("clientarea.php") || window.location.pathname.endsWith("/")) {
                var statusBanner = `
                    <div class="system-status-banner ' . $vars['systemHealthData']['status_color'] . '">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5><i class="fas fa-heartbeat"></i> System Status: ' . $vars['systemHealthData']['status_message'] . '</h5>
                                ' . (!empty($vars['systemHealthData']['status_details']) ? '<p>' . implode('. ', $vars['systemHealthData']['status_details']) . '.</p>' : '') . '
                            </div>
                            <a href="modules/addons/contabo_addon/public_status.php" class="btn btn-sm btn-outline-primary status-link">
                                View Details
                            </a>
                        </div>
                    </div>
                `;
                $(".content-container").prepend(statusBanner);
            }
        });
        </script>
        ';
    }

    return '';
});
