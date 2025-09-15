<?php
/**
 * System Health Status Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\SystemHealthService;
use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;

// Initialize services
$config = new ConfigHelper($vars);
$log = new LogHelper();
$apiClient = new ContaboAPIClient(
    $config->getClientId(),
    $config->getClientSecret(), 
    $config->getApiUser(),
    $config->getApiPassword(),
    $log
);

$healthService = new SystemHealthService($apiClient);

// Handle actions
$action = $_GET['health_action'] ?? 'dashboard';
$incidentId = $_GET['incident_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'create_incident':
            if ($_POST) {
                $incidentId = $healthService->createIncident([
                    'title' => $_POST['title'],
                    'description' => $_POST['description'],
                    'severity' => $_POST['severity'],
                    'affected_services' => $_POST['affected_services'] ?? [],
                    'created_by' => 'admin'
                ]);
                $message = 'Incident created successfully';
            }
            break;
            
        case 'update_incident':
            if ($_POST && $incidentId) {
                $healthService->updateIncidentStatus(
                    $incidentId, 
                    $_POST['status'],
                    $_POST['update_message'] ?? null
                );
                $message = 'Incident status updated successfully';
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get system health status
$healthStatus = [];
try {
    $healthStatus = $healthService->getSystemHealthStatus();
} catch (Exception $e) {
    $error = 'Failed to load system health status: ' . $e->getMessage();
}

$statusColors = [
    'operational' => 'success',
    'degraded_performance' => 'warning',
    'partial_outage' => 'warning',
    'major_outage' => 'danger',
    'unknown' => 'secondary'
];

$statusIcons = [
    'operational' => 'check-circle',
    'degraded_performance' => 'exclamation-triangle',
    'partial_outage' => 'exclamation-circle',
    'major_outage' => 'times-circle',
    'unknown' => 'question-circle'
];
?>

<div class="system-health-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-heartbeat"></i> System Health Status</h2>
            <p>Real-time monitoring and incident management for all services</p>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Overall System Status -->
    <?php if (!empty($healthStatus)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4>
                            <i class="fas fa-<?= $statusIcons[$healthStatus['overall_status']] ?? 'question-circle' ?>"></i>
                            Overall System Status
                        </h4>
                        <div class="btn-group">
                            <button class="btn btn-primary btn-sm" onclick="showCreateIncident()">
                                <i class="fas fa-plus"></i> Report Incident
                            </button>
                            <a href="<?= $vars['modulelink'] ?>&action=system_health&view=public" 
                               class="btn btn-info btn-sm" target="_blank">
                                <i class="fas fa-external-link-alt"></i> Public Status Page
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="alert alert-<?= $statusColors[$healthStatus['overall_status']] ?? 'secondary' ?> mb-0">
                                    <h4 class="alert-heading">
                                        <?= ucwords(str_replace('_', ' ', $healthStatus['overall_status'])) ?>
                                    </h4>
                                    <p class="mb-2">
                                        All systems are being monitored continuously. 
                                        Last updated: <?= date('M j, Y H:i:s', strtotime($healthStatus['last_updated'])) ?>
                                    </p>
                                    <?php if (!empty($healthStatus['performance_metrics'])): ?>
                                        <hr>
                                        <div class="row">
                                            <div class="col-md-3">
                                                <small><strong>Overall Uptime:</strong><br>
                                                <?= $healthStatus['performance_metrics']['overall_uptime'] ?? 'N/A' ?>%</small>
                                            </div>
                                            <div class="col-md-3">
                                                <small><strong>Active Servers:</strong><br>
                                                <?= $healthStatus['performance_metrics']['active_servers'] ?? 'N/A' ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <small><strong>API Response:</strong><br>
                                                <?= $healthStatus['performance_metrics']['api_response_time'] ?? 'N/A' ?>ms</small>
                                            </div>
                                            <div class="col-md-3">
                                                <small><strong>Requests (24h):</strong><br>
                                                <?= number_format($healthStatus['performance_metrics']['total_requests_24h'] ?? 0) ?></small>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <?php if (!empty($healthStatus['uptime_stats'])): ?>
                                    <h6>Uptime Statistics</h6>
                                    <table class="table table-sm">
                                        <tr>
                                            <td>Last 24 hours:</td>
                                            <td class="text-right"><strong><?= $healthStatus['uptime_stats']['last_24h'] ?>%</strong></td>
                                        </tr>
                                        <tr>
                                            <td>Last 7 days:</td>
                                            <td class="text-right"><strong><?= $healthStatus['uptime_stats']['last_7d'] ?>%</strong></td>
                                        </tr>
                                        <tr>
                                            <td>Last 30 days:</td>
                                            <td class="text-right"><strong><?= $healthStatus['uptime_stats']['last_30d'] ?>%</strong></td>
                                        </tr>
                                        <tr>
                                            <td>Last 90 days:</td>
                                            <td class="text-right"><strong><?= $healthStatus['uptime_stats']['last_90d'] ?>%</strong></td>
                                        </tr>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Status -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-cogs"></i> Service Status</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($healthStatus['services'])): ?>
                            <div class="row">
                                <?php foreach ($healthStatus['services'] as $serviceKey => $service): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card h-100">
                                            <div class="card-body p-3">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="card-title mb-1">
                                                            <i class="fas fa-<?= $statusIcons[$service['status']] ?> text-<?= $statusColors[$service['status']] ?>"></i>
                                                            <?= htmlspecialchars($service['name']) ?>
                                                        </h6>
                                                        <p class="card-text text-muted mb-2">
                                                            <?= htmlspecialchars($service['description']) ?>
                                                        </p>
                                                    </div>
                                                    <span class="badge badge-<?= $statusColors[$service['status']] ?>">
                                                        <?= ucwords(str_replace('_', ' ', $service['status'])) ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="row mt-2">
                                                    <?php if ($service['response_time'] > 0): ?>
                                                        <div class="col-6">
                                                            <small class="text-muted">
                                                                <strong>Response:</strong> <?= $service['response_time'] ?>ms
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="col-6">
                                                        <small class="text-muted">
                                                            <strong>Uptime:</strong> <?= $service['uptime_24h'] ?>%
                                                        </small>
                                                    </div>
                                                </div>
                                                
                                                <small class="text-muted">
                                                    Last check: <?= date('H:i:s', strtotime($service['last_check'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No service status data available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Incidents -->
        <?php if (!empty($healthStatus['recent_incidents'])): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h4><i class="fas fa-exclamation-triangle"></i> Recent Incidents</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Incident</th>
                                            <th>Severity</th>
                                            <th>Status</th>
                                            <th>Duration</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($healthStatus['recent_incidents'] as $incident): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($incident['title']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars(substr($incident['description'], 0, 100)) ?>...</small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $severityColors = [
                                                        'low' => 'info',
                                                        'medium' => 'warning',
                                                        'high' => 'danger',
                                                        'critical' => 'dark'
                                                    ];
                                                    $severityColor = $severityColors[$incident['severity']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge badge-<?= $severityColor ?>"><?= ucfirst($incident['severity']) ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $incidentStatusColors = [
                                                        'investigating' => 'warning',
                                                        'identified' => 'info',
                                                        'monitoring' => 'primary',
                                                        'resolved' => 'success'
                                                    ];
                                                    $incidentColor = $incidentStatusColors[$incident['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge badge-<?= $incidentColor ?>"><?= ucfirst($incident['status']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($incident['duration']) ?></td>
                                                <td><?= date('M j, H:i', strtotime($incident['created_at'])) ?></td>
                                                <td>
                                                    <?php if ($incident['status'] !== 'resolved'): ?>
                                                        <button class="btn btn-primary btn-sm" onclick="updateIncident(<?= $incident['id'] ?>)">
                                                            <i class="fas fa-edit"></i> Update
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Planned Maintenance -->
        <?php if (!empty($healthStatus['planned_maintenance'])): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h4><i class="fas fa-tools"></i> Planned Maintenance</h4>
                        </div>
                        <div class="card-body">
                            <?php foreach ($healthStatus['planned_maintenance'] as $maintenance): ?>
                                <div class="alert alert-info">
                                    <h5 class="alert-heading"><?= htmlspecialchars($maintenance['title']) ?></h5>
                                    <p><?= htmlspecialchars($maintenance['description']) ?></p>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <small><strong>Scheduled:</strong> 
                                            <?= date('M j, Y H:i', strtotime($maintenance['scheduled_start'])) ?> - 
                                            <?= date('H:i', strtotime($maintenance['scheduled_end'])) ?></small>
                                        </div>
                                        <div class="col-md-6">
                                            <small><strong>Duration:</strong> <?= $maintenance['estimated_duration'] ?></small>
                                        </div>
                                    </div>
                                    <?php if (!empty($maintenance['affected_services'])): ?>
                                        <small><strong>Affected Services:</strong> 
                                        <?= implode(', ', $maintenance['affected_services']) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Create Incident Modal -->
<div class="modal fade" id="createIncidentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="health_action" value="create_incident">
                
                <div class="modal-header">
                    <h5 class="modal-title">Report System Incident</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Incident Title</label>
                        <input type="text" name="title" class="form-control" required
                               placeholder="Brief description of the incident">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="4" required
                                  placeholder="Detailed description of the issue and its impact"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Severity</label>
                                <select name="severity" class="form-control" required>
                                    <option value="low">Low - Minor issue</option>
                                    <option value="medium">Medium - Some impact</option>
                                    <option value="high" selected>High - Major impact</option>
                                    <option value="critical">Critical - Service down</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Affected Services</label>
                                <select name="affected_services[]" class="form-control" multiple>
                                    <option value="vps_management">VPS Management</option>
                                    <option value="contabo_api">Contabo API</option>
                                    <option value="load_balancers">Load Balancers</option>
                                    <option value="dns_service">DNS Service</option>
                                    <option value="backup_service">Backup Service</option>
                                    <option value="monitoring_system">Monitoring</option>
                                    <option value="support_system">Support System</option>
                                    <option value="billing_system">Billing System</option>
                                </select>
                                <small class="form-text text-muted">Hold Ctrl to select multiple services</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Create Incident</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Incident Modal -->
<div class="modal fade" id="updateIncidentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="updateIncidentForm">
                <input type="hidden" name="health_action" value="update_incident">
                <input type="hidden" name="incident_id" id="update_incident_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Update Incident Status</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control" required>
                            <option value="investigating">Investigating</option>
                            <option value="identified">Identified</option>
                            <option value="monitoring">Monitoring</option>
                            <option value="resolved">Resolved</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Status Update Message</label>
                        <textarea name="update_message" class="form-control" rows="3"
                                  placeholder="Provide an update on the incident status"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreateIncident() {
    $('#createIncidentModal').modal('show');
}

function updateIncident(incidentId) {
    $('#update_incident_id').val(incidentId);
    $('#updateIncidentModal').modal('show');
}

// Auto-refresh page every 30 seconds
setTimeout(function() {
    location.reload();
}, 30000);
</script>

<style>
.system-health-management .card {
    margin-bottom: 1rem;
}

.system-health-management .alert-heading {
    margin-bottom: 1rem;
}

.system-health-management .table-responsive {
    border-radius: 0.25rem;
}

.service-status-card {
    transition: all 0.2s ease-in-out;
}

.service-status-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.12);
}
</style>
