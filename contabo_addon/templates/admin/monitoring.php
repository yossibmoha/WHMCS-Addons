<?php
/**
 * Server Monitoring Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\MonitoringService;
use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;
use WHMCS\Database\Capsule;

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

$monitoringService = new MonitoringService($apiClient);

// Handle actions
$action = $_GET['monitoring_action'] ?? 'dashboard';
$instanceId = $_GET['instance_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'collect_metrics':
            if ($_POST) {
                $results = $monitoringService->collectAllServerMetrics();
                $message = "Metrics collection completed: {$results['success']} successful, {$results['failed']} failed";
            }
            break;
            
        case 'create_alert':
            if ($_POST && $instanceId) {
                $result = $monitoringService->createMonitoringAlert([
                    'instance_id' => $instanceId,
                    'alert_type' => $_POST['alert_type'],
                    'metric_name' => $_POST['metric_name'],
                    'condition' => $_POST['condition'],
                    'threshold_value' => $_POST['threshold_value'],
                    'duration_minutes' => $_POST['duration_minutes'],
                    'notification_email' => $_POST['notification_email'],
                    'created_by' => 'admin'
                ]);
                $message = 'Monitoring alert created successfully';
            }
            break;
            
        case 'test_connectivity':
            if ($instanceId) {
                $testResults = $monitoringService->testServerConnectivity($instanceId);
                $message = "Connectivity test completed - Health Score: {$testResults['health_score']}%";
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get monitoring dashboard data
$dashboard = [];
try {
    $dashboard = $monitoringService->getMonitoringDashboard();
} catch (Exception $e) {
    $error = 'Failed to load monitoring dashboard: ' . $e->getMessage();
}

// Get all instances for selection
$instances = [];
try {
    $instances = Capsule::table('mod_contabo_instances')
        ->leftJoin('tblhosting', 'mod_contabo_instances.service_id', '=', 'tblhosting.id')
        ->leftJoin('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
        ->select(
            'mod_contabo_instances.*',
            'tblhosting.domain',
            'tblclients.firstname',
            'tblclients.lastname'
        )
        ->get();
} catch (Exception $e) {
    // Continue with empty array
}

// Get server metrics history if instance is selected
$metricsHistory = [];
if ($instanceId) {
    try {
        $timeRange = $_GET['timerange'] ?? '24h';
        $metricsHistory = $monitoringService->getServerMetricsHistory($instanceId, $timeRange);
    } catch (Exception $e) {
        $error = 'Failed to load metrics history: ' . $e->getMessage();
    }
}

// Get monitoring alerts
$alerts = [];
try {
    $alerts = $monitoringService->getMonitoringAlerts($instanceId);
} catch (Exception $e) {
    // Continue with empty array
}
?>

<!-- Include Chart.js for performance charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="monitoring-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-chart-line"></i> Server Monitoring</h2>
            <p>Monitor server performance, set alerts, and track uptime</p>
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

    <!-- Monitoring Overview -->
    <?php if (!empty($dashboard['overview'])): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-server"></i> <?= $dashboard['overview']['total_servers'] ?? 0 ?></h4>
                        <p>Total Servers</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-check-circle"></i> <?= $dashboard['overview']['online_servers'] ?? 0 ?></h4>
                        <p>Online Servers</p>
                        <small><?= $dashboard['overview']['uptime_percentage'] ?? 0 ?>% Uptime</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-exclamation-triangle"></i> <?= $dashboard['overview']['active_alerts'] ?? 0 ?></h4>
                        <p>Active Alerts</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-bell"></i> <?= $dashboard['overview']['critical_alerts'] ?? 0 ?></h4>
                        <p>Alerts (24h)</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-tools"></i> Monitoring Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="collectMetrics()">
                            <i class="fas fa-sync"></i> Collect All Metrics
                        </button>
                        <button class="btn btn-warning" onclick="showCreateAlert()">
                            <i class="fas fa-bell"></i> Create Alert
                        </button>
                        <button class="btn btn-info" onclick="testAllConnectivity()">
                            <i class="fas fa-plug"></i> Test All Connectivity
                        </button>
                        <button class="btn btn-primary" onclick="exportMetrics()">
                            <i class="fas fa-download"></i> Export Metrics
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Server Selection and Time Range -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-server"></i> Server Selection</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <input type="hidden" name="module" value="contabo_addon">
                        <input type="hidden" name="action" value="monitoring">
                        
                        <select name="instance_id" class="form-control mr-3" onchange="this.form.submit()">
                            <option value="">Select a server for detailed metrics...</option>
                            <?php foreach ($instances as $instance): ?>
                                <option value="<?= $instance->contabo_instance_id ?>" 
                                        <?= $instanceId === $instance->contabo_instance_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($instance->name ?: $instance->contabo_instance_id) ?>
                                    (<?= htmlspecialchars($instance->domain ?: 'No Domain') ?>)
                                    - <?= htmlspecialchars(($instance->firstname ?: '') . ' ' . ($instance->lastname ?: '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <?php if ($instanceId): ?>
                            <select name="timerange" class="form-control mr-3" onchange="this.form.submit()">
                                <option value="1h" <?= ($_GET['timerange'] ?? '24h') === '1h' ? 'selected' : '' ?>>Last Hour</option>
                                <option value="6h" <?= ($_GET['timerange'] ?? '24h') === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                                <option value="24h" <?= ($_GET['timerange'] ?? '24h') === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                                <option value="7d" <?= ($_GET['timerange'] ?? '24h') === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="30d" <?= ($_GET['timerange'] ?? '24h') === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
                            </select>

                            <button type="button" class="btn btn-info" onclick="testServerConnectivity()">
                                <i class="fas fa-plug"></i> Test Connectivity
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Performance Summary -->
        <div class="col-md-4">
            <?php if (!empty($dashboard['performance_summary'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-tachometer-alt"></i> 24h Average</h4>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h5><?= round($dashboard['performance_summary']->avg_cpu ?? 0, 1) ?>%</h5>
                                <small>CPU</small>
                            </div>
                            <div class="col-6">
                                <h5><?= round($dashboard['performance_summary']->avg_memory ?? 0, 1) ?>%</h5>
                                <small>Memory</small>
                            </div>
                            <div class="col-6">
                                <h5><?= round($dashboard['performance_summary']->avg_disk ?? 0, 1) ?>%</h5>
                                <small>Disk</small>
                            </div>
                            <div class="col-6">
                                <h5><?= round($dashboard['performance_summary']->avg_response_time ?? 0, 0) ?>ms</h5>
                                <small>Response</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($instanceId && !empty($metricsHistory['metrics'])): ?>
        <!-- Performance Charts -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-line"></i> Performance Charts 
                            <small class="text-muted">(<?= $metricsHistory['data_points'] ?? 0 ?> data points)</small>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="cpuChart" width="400" height="200"></canvas>
                            </div>
                            <div class="col-md-6">
                                <canvas id="memoryChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <canvas id="diskChart" width="400" height="200"></canvas>
                            </div>
                            <div class="col-md-6">
                                <canvas id="responseChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Metrics Summary -->
        <?php if (!empty($metricsHistory['summary'])): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h4><i class="fas fa-info-circle"></i> Metrics Summary (<?= htmlspecialchars($_GET['timerange'] ?? '24h') ?>)</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach (['cpu', 'memory', 'disk', 'response_time'] as $metric): ?>
                                    <?php if (isset($metricsHistory['summary'][$metric])): ?>
                                        <div class="col-md-3">
                                            <div class="card">
                                                <div class="card-body text-center">
                                                    <h5><?= ucfirst($metric === 'response_time' ? 'Response Time' : $metric) ?></h5>
                                                    <div class="row">
                                                        <div class="col-4">
                                                            <strong><?= $metricsHistory['summary'][$metric]['avg'] ?></strong>
                                                            <br><small>Avg</small>
                                                        </div>
                                                        <div class="col-4">
                                                            <strong><?= $metricsHistory['summary'][$metric]['max'] ?></strong>
                                                            <br><small>Max</small>
                                                        </div>
                                                        <div class="col-4">
                                                            <strong><?= $metricsHistory['summary'][$metric]['min'] ?></strong>
                                                            <br><small>Min</small>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?= $metric === 'response_time' ? 'ms' : '%' ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Monitoring Alerts -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-bell"></i> Monitoring Alerts</h4>
                    <button class="btn btn-primary btn-sm" onclick="showCreateAlert()">
                        <i class="fas fa-plus"></i> Create Alert
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($alerts)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No monitoring alerts configured. Create alerts to get notified about server issues.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Server</th>
                                        <th>Alert Type</th>
                                        <th>Condition</th>
                                        <th>Threshold</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Last Triggered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alerts as $alert): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($alert['instance_name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($alert['instance_id']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= htmlspecialchars(ucwords($alert['alert_type'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($alert['metric_name']) ?><br>
                                                <small><?= htmlspecialchars(str_replace('_', ' ', $alert['condition'])) ?></small>
                                            </td>
                                            <td>
                                                <strong><?= $alert['threshold_value'] ?></strong>
                                                <?= $alert['alert_type'] === 'response_time' ? 'ms' : '%' ?>
                                            </td>
                                            <td>
                                                <?= $alert['notification_email'] ? htmlspecialchars($alert['notification_email']) : 'None' ?>
                                            </td>
                                            <td>
                                                <?php if ($alert['is_active']): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $alert['last_triggered'] ? date('M j, H:i', strtotime($alert['last_triggered'])) : 'Never' ?><br>
                                                <small class="text-muted"><?= $alert['trigger_count'] ?> times</small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-warning" onclick="editAlert(<?= $alert['id'] ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger" onclick="deleteAlert(<?= $alert['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Alert History -->
    <?php if (!empty($dashboard['recent_alerts'])): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-history"></i> Recent Alert Activity</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Server</th>
                                        <th>Alert Type</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashboard['recent_alerts'] as $alert): ?>
                                        <tr>
                                            <td><?= date('M j, H:i', strtotime($alert->triggered_at)) ?></td>
                                            <td><?= htmlspecialchars($alert->instance_name ?: $alert->instance_id) ?></td>
                                            <td><span class="badge badge-warning"><?= htmlspecialchars(ucwords($alert->alert_type)) ?></span></td>
                                            <td><?= htmlspecialchars($alert->message) ?></td>
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
</div>

<!-- Create Alert Modal -->
<div class="modal fade" id="createAlertModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="monitoring_action" value="create_alert">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create Monitoring Alert</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Server</label>
                                <select name="instance_id" class="form-control" required id="alertInstanceId">
                                    <option value="">Select server...</option>
                                    <?php foreach ($instances as $instance): ?>
                                        <option value="<?= $instance->contabo_instance_id ?>"
                                                <?= $instanceId === $instance->contabo_instance_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($instance->name ?: $instance->contabo_instance_id) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Alert Type</label>
                                <select name="alert_type" class="form-control" required id="alertType" onchange="updateMetricName()">
                                    <option value="">Select type...</option>
                                    <option value="cpu">CPU Usage</option>
                                    <option value="memory">Memory Usage</option>
                                    <option value="disk">Disk Usage</option>
                                    <option value="response_time">Response Time</option>
                                    <option value="uptime">Uptime</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Condition</label>
                                <select name="condition" class="form-control" required>
                                    <option value="greater_than">Greater Than</option>
                                    <option value="less_than">Less Than</option>
                                    <option value="equals">Equals</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Threshold Value</label>
                                <input type="number" name="threshold_value" class="form-control" required
                                       placeholder="e.g., 80 for 80% CPU">
                                <small class="form-text text-muted" id="thresholdHelp">
                                    Enter percentage (0-100) for CPU/Memory/Disk, milliseconds for response time, hours for uptime
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Metric Name</label>
                        <input type="text" name="metric_name" class="form-control" required id="metricName"
                               placeholder="e.g., High CPU Usage Alert">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Duration (minutes)</label>
                                <input type="number" name="duration_minutes" class="form-control" value="5" min="1">
                                <small class="form-text text-muted">
                                    Alert triggers if condition persists for this duration
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Notification Email</label>
                                <input type="email" name="notification_email" class="form-control"
                                       placeholder="admin@example.com">
                                <small class="form-text text-muted">Optional: Email to send alerts to</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Alert</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Chart data from PHP
const chartData = <?= json_encode($metricsHistory['chart_data'] ?? []) ?>;

// Initialize charts if we have data
if (chartData && chartData.labels && chartData.labels.length > 0) {
    // CPU Chart
    new Chart(document.getElementById('cpuChart'), {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'CPU Usage (%)',
                data: chartData.cpu,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            plugins: { title: { display: true, text: 'CPU Usage' }},
            scales: { y: { beginAtZero: true, max: 100 }}
        }
    });

    // Memory Chart
    new Chart(document.getElementById('memoryChart'), {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Memory Usage (%)',
                data: chartData.memory,
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            plugins: { title: { display: true, text: 'Memory Usage' }},
            scales: { y: { beginAtZero: true, max: 100 }}
        }
    });

    // Disk Chart
    new Chart(document.getElementById('diskChart'), {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Disk Usage (%)',
                data: chartData.disk,
                borderColor: 'rgb(255, 205, 86)',
                backgroundColor: 'rgba(255, 205, 86, 0.1)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            plugins: { title: { display: true, text: 'Disk Usage' }},
            scales: { y: { beginAtZero: true, max: 100 }}
        }
    });

    // Response Time Chart
    new Chart(document.getElementById('responseChart'), {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Response Time (ms)',
                data: chartData.response_time,
                borderColor: 'rgb(153, 102, 255)',
                backgroundColor: 'rgba(153, 102, 255, 0.1)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            plugins: { title: { display: true, text: 'Response Time' }},
            scales: { y: { beginAtZero: true }}
        }
    });
}

function collectMetrics() {
    if (confirm('Collect metrics for all servers? This may take a few minutes.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="monitoring_action" value="collect_metrics">';
        document.body.appendChild(form);
        form.submit();
    }
}

function showCreateAlert() {
    $('#createAlertModal').modal('show');
}

function updateMetricName() {
    const alertType = document.getElementById('alertType').value;
    const metricName = document.getElementById('metricName');
    
    const names = {
        'cpu': 'High CPU Usage Alert',
        'memory': 'High Memory Usage Alert',
        'disk': 'High Disk Usage Alert',
        'response_time': 'High Response Time Alert',
        'uptime': 'Low Uptime Alert'
    };
    
    if (names[alertType]) {
        metricName.value = names[alertType];
    }
}

function testServerConnectivity() {
    const instanceId = '<?= addslashes($instanceId ?? '') ?>';
    if (instanceId) {
        window.open('<?= $vars['modulelink'] ?>&action=monitoring&monitoring_action=test_connectivity&instance_id=' + instanceId, '_blank');
    }
}

function testAllConnectivity() {
    alert('Testing all server connectivity - feature coming soon');
}

function exportMetrics() {
    window.open('<?= $vars['modulelink'] ?>&action=monitoring&export=metrics', '_blank');
}

function editAlert(alertId) {
    alert('Edit alert functionality coming soon');
}

function deleteAlert(alertId) {
    if (confirm('Are you sure you want to delete this alert?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=monitoring&monitoring_action=delete_alert&alert_id=' + alertId;
    }
}
</script>
