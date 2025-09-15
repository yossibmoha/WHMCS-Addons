<?php
/**
 * Public System Health Status Page
 * 
 * This page can be accessed without authentication to show system status
 */

// Include required files
require_once __DIR__ . '/classes/API/ContaboAPIClient.php';
require_once __DIR__ . '/classes/Services/SystemHealthService.php';
require_once __DIR__ . '/classes/Helpers/ConfigHelper.php';
require_once __DIR__ . '/classes/Helpers/LogHelper.php';

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\SystemHealthService;
use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;

// Simple configuration loading (you might want to adjust this)
$config = [
    'client_id' => 'your_client_id',
    'client_secret' => 'your_client_secret',
    'api_user' => 'your_api_user',
    'api_password' => 'your_api_password'
];

// Initialize services
$log = new LogHelper();

try {
    $apiClient = new ContaboAPIClient(
        $config['client_id'],
        $config['client_secret'], 
        $config['api_user'],
        $config['api_password'],
        $log
    );

    $healthService = new SystemHealthService($apiClient);
    $healthStatus = $healthService->getSystemHealthStatus();
} catch (Exception $e) {
    $healthStatus = [
        'overall_status' => 'unknown',
        'last_updated' => date('Y-m-d H:i:s'),
        'services' => [],
        'error' => 'Unable to retrieve system status'
    ];
}

$statusColors = [
    'operational' => '#28a745',
    'degraded_performance' => '#ffc107',
    'partial_outage' => '#fd7e14',
    'major_outage' => '#dc3545',
    'unknown' => '#6c757d'
];

$statusIcons = [
    'operational' => '✓',
    'degraded_performance' => '!',
    'partial_outage' => '⚠',
    'major_outage' => '✗',
    'unknown' => '?'
];

$statusMessages = [
    'operational' => 'All Systems Operational',
    'degraded_performance' => 'Degraded Performance',
    'partial_outage' => 'Partial Service Outage',
    'major_outage' => 'Major Service Outage',
    'unknown' => 'Status Unknown'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VPS Server Status - System Health</title>
    <meta name="description" content="Real-time status of VPS Server hosting services and infrastructure">
    <meta http-equiv="refresh" content="60"> <!-- Auto refresh every 60 seconds -->
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .status-page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .service-status-card {
            transition: all 0.2s ease;
            border-left: 4px solid #e9ecef;
        }
        
        .service-status-card.operational {
            border-left-color: #28a745;
        }
        
        .service-status-card.degraded_performance {
            border-left-color: #ffc107;
        }
        
        .service-status-card.partial_outage {
            border-left-color: #fd7e14;
        }
        
        .service-status-card.major_outage {
            border-left-color: #dc3545;
        }
        
        .service-status-card.unknown {
            border-left-color: #6c757d;
        }
        
        .uptime-bar {
            height: 4px;
            background-color: #e9ecef;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .uptime-fill {
            height: 100%;
            background-color: #28a745;
            transition: width 0.5s ease;
        }
        
        .incident-card {
            border-left: 4px solid #dc3545;
        }
        
        .maintenance-card {
            border-left: 4px solid #17a2b8;
        }
        
        .footer {
            background-color: #343a40;
            color: #adb5bd;
            padding: 2rem 0;
            margin-top: 3rem;
        }
        
        .status-overview {
            font-size: 1.5rem;
            font-weight: 500;
        }
        
        .last-updated {
            opacity: 0.8;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .status-page-header {
                padding: 2rem 0;
            }
            
            .status-overview {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="status-page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-server mr-2"></i>
                        VPS Server Status
                    </h1>
                    <div class="status-overview">
                        <span class="status-indicator" style="background-color: <?= $statusColors[$healthStatus['overall_status']] ?? '#6c757d' ?>"></span>
                        <?= $statusMessages[$healthStatus['overall_status']] ?? 'Status Unknown' ?>
                    </div>
                    <div class="last-updated mt-2">
                        <i class="fas fa-clock mr-1"></i>
                        Last updated: <?= date('M j, Y \a\t H:i:s T', strtotime($healthStatus['last_updated'])) ?>
                    </div>
                </div>
                <div class="col-md-4 text-md-right">
                    <?php if (!empty($healthStatus['uptime_stats'])): ?>
                        <div class="card bg-transparent border-light">
                            <div class="card-body p-3">
                                <h6 class="card-title mb-2">Overall Uptime</h6>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="h4 mb-0"><?= $healthStatus['uptime_stats']['last_24h'] ?>%</div>
                                        <small>24h</small>
                                    </div>
                                    <div class="col-6">
                                        <div class="h4 mb-0"><?= $healthStatus['uptime_stats']['last_30d'] ?>%</div>
                                        <small>30d</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <!-- Services Status -->
        <?php if (!empty($healthStatus['services'])): ?>
            <div class="row">
                <div class="col-12">
                    <h3 class="mb-4">
                        <i class="fas fa-cogs mr-2"></i>
                        Service Status
                    </h3>
                </div>
            </div>
            
            <div class="row">
                <?php foreach ($healthStatus['services'] as $serviceKey => $service): ?>
                    <div class="col-lg-6 col-xl-4 mb-3">
                        <div class="card service-status-card h-100 <?= $service['status'] ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title mb-0">
                                        <?= htmlspecialchars($service['name']) ?>
                                    </h6>
                                    <span class="badge badge-<?= $service['status'] === 'operational' ? 'success' : ($service['status'] === 'major_outage' ? 'danger' : 'warning') ?>">
                                        <?= $statusIcons[$service['status']] ?> <?= ucwords(str_replace('_', ' ', $service['status'])) ?>
                                    </span>
                                </div>
                                
                                <p class="card-text text-muted mb-3">
                                    <?= htmlspecialchars($service['description']) ?>
                                </p>
                                
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Uptime (24h)</small>
                                        <div class="uptime-bar">
                                            <div class="uptime-fill" style="width: <?= $service['uptime_24h'] ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?= $service['uptime_24h'] ?>%</small>
                                    </div>
                                    <?php if ($service['response_time'] > 0): ?>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Response Time</small>
                                            <div class="font-weight-bold"><?= $service['response_time'] ?>ms</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Current Incidents -->
        <?php if (!empty($healthStatus['recent_incidents'])): ?>
            <div class="row mt-5">
                <div class="col-12">
                    <h3 class="mb-4">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Current Incidents
                    </h3>
                </div>
            </div>
            
            <?php
            $activeIncidents = array_filter($healthStatus['recent_incidents'], function($incident) {
                return $incident['status'] !== 'resolved';
            });
            ?>
            
            <?php if (!empty($activeIncidents)): ?>
                <?php foreach ($activeIncidents as $incident): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card incident-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="card-title">
                                                <?= htmlspecialchars($incident['title']) ?>
                                                <span class="badge badge-<?= $incident['severity'] === 'critical' ? 'dark' : ($incident['severity'] === 'high' ? 'danger' : 'warning') ?> ml-2">
                                                    <?= ucfirst($incident['severity']) ?>
                                                </span>
                                            </h5>
                                            <p class="card-text"><?= htmlspecialchars($incident['description']) ?></p>
                                        </div>
                                        <div class="text-right">
                                            <span class="badge badge-info"><?= ucfirst($incident['status']) ?></span>
                                            <div class="text-muted mt-1">
                                                <small><?= date('M j, H:i', strtotime($incident['created_at'])) ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($incident['affected_services'])): ?>
                                        <div class="mt-3">
                                            <small class="text-muted">Affected Services: </small>
                                            <?php foreach ($incident['affected_services'] as $service): ?>
                                                <span class="badge badge-light"><?= htmlspecialchars($service) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle mr-2"></i>
                            No active incidents - all services are operating normally.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Planned Maintenance -->
        <?php if (!empty($healthStatus['planned_maintenance'])): ?>
            <div class="row mt-5">
                <div class="col-12">
                    <h3 class="mb-4">
                        <i class="fas fa-tools mr-2"></i>
                        Planned Maintenance
                    </h3>
                </div>
            </div>
            
            <?php foreach ($healthStatus['planned_maintenance'] as $maintenance): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="card maintenance-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title"><?= htmlspecialchars($maintenance['title']) ?></h5>
                                        <p class="card-text"><?= htmlspecialchars($maintenance['description']) ?></p>
                                    </div>
                                    <span class="badge badge-info"><?= ucfirst($maintenance['status']) ?></span>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <small class="text-muted">
                                            <i class="fas fa-calendar mr-1"></i>
                                            <strong>Scheduled:</strong> 
                                            <?= date('M j, Y H:i', strtotime($maintenance['scheduled_start'])) ?> - 
                                            <?= date('H:i', strtotime($maintenance['scheduled_end'])) ?>
                                        </small>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted">
                                            <i class="fas fa-clock mr-1"></i>
                                            <strong>Duration:</strong> <?= $maintenance['estimated_duration'] ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <?php if (!empty($maintenance['affected_services'])): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Affected Services: </small>
                                        <?php foreach ($maintenance['affected_services'] as $service): ?>
                                            <span class="badge badge-light"><?= htmlspecialchars($service) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Past Incidents Summary -->
        <?php
        $resolvedIncidents = array_filter($healthStatus['recent_incidents'] ?? [], function($incident) {
            return $incident['status'] === 'resolved';
        });
        ?>
        
        <?php if (!empty($resolvedIncidents)): ?>
            <div class="row mt-5">
                <div class="col-12">
                    <h4 class="mb-3">
                        <i class="fas fa-history mr-2"></i>
                        Recent Resolved Incidents
                    </h4>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Incident</th>
                                    <th>Severity</th>
                                    <th>Duration</th>
                                    <th>Resolved</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($resolvedIncidents, 0, 5) as $incident): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($incident['title']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= $incident['severity'] === 'critical' ? 'dark' : ($incident['severity'] === 'high' ? 'danger' : 'warning') ?>">
                                                <?= ucfirst($incident['severity']) ?>
                                            </span>
                                        </td>
                                        <td><?= $incident['duration'] ?></td>
                                        <td><?= date('M j, H:i', strtotime($incident['resolved_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- System Metrics -->
        <?php if (!empty($healthStatus['performance_metrics'])): ?>
            <div class="row mt-5">
                <div class="col-12">
                    <h4 class="mb-3">
                        <i class="fas fa-chart-bar mr-2"></i>
                        System Performance
                    </h4>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-primary"><?= $healthStatus['performance_metrics']['active_servers'] ?></h5>
                            <p class="card-text">Active Servers</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-success"><?= $healthStatus['performance_metrics']['api_response_time'] ?>ms</h5>
                            <p class="card-text">API Response</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-info"><?= $healthStatus['performance_metrics']['overall_uptime'] ?>%</h5>
                            <p class="card-text">Overall Uptime</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-warning"><?= number_format($healthStatus['performance_metrics']['total_requests_24h']) ?></h5>
                            <p class="card-text">Requests (24h)</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h6>VPS Server Status</h6>
                    <p class="mb-0">Real-time monitoring of our hosting infrastructure and services.</p>
                </div>
                <div class="col-md-6 text-md-right">
                    <p class="mb-0">
                        <small>
                            <i class="fas fa-sync-alt mr-1"></i>
                            Auto-refreshes every minute
                        </small>
                    </p>
                    <p class="mb-0">
                        <small>
                            <i class="fas fa-clock mr-1"></i>
                            All times in <?= date('T') ?>
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Show loading indicator when refreshing
        $(document).ready(function() {
            // Add subtle pulse animation to status indicators
            $('.status-indicator').each(function() {
                if ($(this).css('background-color') === 'rgb(40, 167, 69)') { // Green (operational)
                    $(this).css('animation', 'pulse-green 2s ease-in-out infinite');
                }
            });
        });
        
        // Custom CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse-green {
                0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
                70% { box-shadow: 0 0 0 8px rgba(40, 167, 69, 0); }
                100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
