<?php
/**
 * Auto-scaling Management Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\AutoScalingService;
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

$scalingService = new AutoScalingService($apiClient);

// Handle actions
$action = $_GET['scaling_action'] ?? 'dashboard';
$instanceId = $_GET['instance_id'] ?? null;
$policyId = $_GET['policy_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'create_policy':
            if ($_POST && $instanceId) {
                $result = $scalingService->createScalingPolicy([
                    'instance_id' => $instanceId,
                    'policy_name' => $_POST['policy_name'],
                    'policy_type' => $_POST['policy_type'],
                    'metric_type' => $_POST['metric_type'],
                    'threshold_value' => $_POST['threshold_value'],
                    'threshold_duration' => $_POST['threshold_duration'],
                    'scaling_action' => $_POST['scaling_action'],
                    'target_configuration' => [
                        'cores' => $_POST['target_cores'] ?? null,
                        'ram_mb' => $_POST['target_ram'] ?? null,
                        'disk_mb' => $_POST['target_disk'] ?? null
                    ],
                    'cooldown_period' => $_POST['cooldown_period'],
                    'notification_email' => $_POST['notification_email'],
                    'max_scale_actions' => $_POST['max_scale_actions'],
                    'created_by' => 'admin'
                ]);
                $message = 'Auto-scaling policy created successfully';
            }
            break;
            
        case 'update_policy':
            if ($_POST && $policyId) {
                $result = $scalingService->updateScalingPolicy($policyId, [
                    'policy_name' => $_POST['policy_name'],
                    'threshold_value' => $_POST['threshold_value'],
                    'threshold_duration' => $_POST['threshold_duration'],
                    'target_configuration' => [
                        'cores' => $_POST['target_cores'],
                        'ram_mb' => $_POST['target_ram'],
                        'disk_mb' => $_POST['target_disk']
                    ],
                    'cooldown_period' => $_POST['cooldown_period'],
                    'is_active' => isset($_POST['is_active']),
                    'notification_email' => $_POST['notification_email'],
                    'max_scale_actions' => $_POST['max_scale_actions']
                ]);
                $message = 'Scaling policy updated successfully';
            }
            break;
            
        case 'delete_policy':
            if ($policyId) {
                $result = $scalingService->deleteScalingPolicy($policyId);
                $message = 'Scaling policy deleted successfully';
            }
            break;
            
        case 'manual_scale':
            if ($_POST && $instanceId) {
                $result = $scalingService->triggerManualScaling(
                    $instanceId,
                    $_POST['scaling_type'],
                    [
                        'cores' => $_POST['target_cores'],
                        'ram_mb' => $_POST['target_ram'],
                        'disk_mb' => $_POST['target_disk']
                    ]
                );
                $message = $result['success'] ? 'Manual scaling triggered successfully' : 'Manual scaling failed';
            }
            break;
            
        case 'check_policies':
            if ($_POST) {
                $results = $scalingService->checkAndExecuteScalingPolicies();
                $message = "Policy check completed: {$results['triggered']} policies triggered, {$results['skipped']} skipped";
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get scaling statistics
$stats = [];
try {
    $stats = $scalingService->getScalingStatistics();
} catch (Exception $e) {
    $error = 'Failed to load scaling statistics: ' . $e->getMessage();
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

// Get scaling policies
$policies = [];
try {
    $policies = $scalingService->getScalingPolicies($instanceId);
} catch (Exception $e) {
    // Continue with empty array
}

// Get scaling history
$scalingHistory = [];
try {
    $scalingHistory = $scalingService->getScalingHistory($instanceId, 20);
} catch (Exception $e) {
    // Continue with empty array
}

// Get available configurations for selected instance
$availableConfigs = [];
if ($instanceId) {
    try {
        $availableConfigs = $scalingService->getAvailableConfigurations($instanceId);
    } catch (Exception $e) {
        // Continue with empty array
    }
}
?>

<div class="scaling-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-expand-arrows-alt"></i> Auto-scaling Management</h2>
            <p>Configure automatic resource scaling based on server performance metrics</p>
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

    <!-- Scaling Statistics -->
    <?php if (!empty($stats)): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-cogs"></i> <?= $stats['total_policies'] ?? 0 ?></h4>
                        <p>Scaling Policies</p>
                        <small><?= $stats['active_policies'] ?? 0 ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-arrow-up"></i> <?= $stats['scale_up_policies'] ?? 0 ?></h4>
                        <p>Scale-Up Policies</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-arrow-down"></i> <?= $stats['scale_down_policies'] ?? 0 ?></h4>
                        <p>Scale-Down Policies</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-chart-line"></i> <?= $stats['success_rate'] ?? 0 ?>%</h4>
                        <p>Success Rate</p>
                        <small><?= $stats['actions_24h'] ?? 0 ?> actions (24h)</small>
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
                    <h4><i class="fas fa-bolt"></i> Scaling Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="showCreatePolicy()" <?= !$instanceId ? 'disabled' : '' ?>>
                            <i class="fas fa-plus"></i> Create Policy
                        </button>
                        <button class="btn btn-success" onclick="showManualScale()" <?= !$instanceId ? 'disabled' : '' ?>>
                            <i class="fas fa-hand-point-up"></i> Manual Scale
                        </button>
                        <button class="btn btn-warning" onclick="checkAllPolicies()">
                            <i class="fas fa-search"></i> Check All Policies
                        </button>
                        <button class="btn btn-info" onclick="viewScalingHistory()">
                            <i class="fas fa-history"></i> View History
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Instance Selection -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-server"></i> Select Server</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <input type="hidden" name="module" value="contabo_addon">
                        <input type="hidden" name="action" value="scaling">
                        
                        <select name="instance_id" class="form-control mr-3" onchange="this.form.submit()">
                            <option value="">Select a server...</option>
                            <?php foreach ($instances as $instance): ?>
                                <option value="<?= $instance->contabo_instance_id ?>" 
                                        <?= $instanceId === $instance->contabo_instance_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($instance->name ?: $instance->contabo_instance_id) ?>
                                    (<?= htmlspecialchars($instance->domain ?: 'No Domain') ?>)
                                    - <?= htmlspecialchars(($instance->firstname ?: '') . ' ' . ($instance->lastname ?: '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <!-- Metric Distribution -->
        <div class="col-md-4">
            <?php if (!empty($stats['metric_distribution'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> Metrics Used</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($stats['metric_distribution'] as $metric => $count): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><strong><?= ucfirst($metric) ?></strong></span>
                                <span class="badge badge-primary"><?= $count ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Current Configuration -->
    <?php if (!empty($availableConfigs['current_configuration'])): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-info-circle"></i> Current Server Configuration</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h5><i class="fas fa-microchip"></i> CPU</h5>
                                        <h3><?= $availableConfigs['current_configuration']['cores'] ?></h3>
                                        <p>Cores</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h5><i class="fas fa-memory"></i> Memory</h5>
                                        <h3><?= round($availableConfigs['current_configuration']['ram_mb'] / 1024, 1) ?></h3>
                                        <p>GB RAM</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h5><i class="fas fa-hdd"></i> Storage</h5>
                                        <h3><?= round($availableConfigs['current_configuration']['disk_mb'] / 1024, 0) ?></h3>
                                        <p>GB SSD</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h5><i class="fas fa-cogs"></i> Scaling</h5>
                                        <h3><?= count($policies) ?></h3>
                                        <p>Policies</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Scaling Policies -->
    <?php if ($instanceId): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4><i class="fas fa-list"></i> Scaling Policies</h4>
                        <button class="btn btn-primary btn-sm" onclick="showCreatePolicy()">
                            <i class="fas fa-plus"></i> Create Policy
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($policies)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No scaling policies configured. Create policies to enable automatic scaling.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Policy Name</th>
                                            <th>Type</th>
                                            <th>Metric</th>
                                            <th>Threshold</th>
                                            <th>Action</th>
                                            <th>Status</th>
                                            <th>Last Triggered</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($policies as $policy): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($policy['policy_name']) ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($policy['policy_type'] === 'scale_up'): ?>
                                                        <span class="badge badge-success">Scale Up</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Scale Down</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?= ucfirst($policy['metric_type']) ?></span>
                                                </td>
                                                <td>
                                                    <?= $policy['threshold_value'] ?>%<br>
                                                    <small class="text-muted"><?= $policy['threshold_duration'] ?>s duration</small>
                                                </td>
                                                <td>
                                                    <?= ucwords(str_replace('_', ' ', $policy['scaling_action'])) ?>
                                                </td>
                                                <td>
                                                    <?php if ($policy['is_active']): ?>
                                                        <span class="badge badge-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $policy['last_triggered'] ? date('M j, H:i', strtotime($policy['last_triggered'])) : 'Never' ?><br>
                                                    <small class="text-muted"><?= $policy['trigger_count'] ?> times</small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-warning" onclick="editPolicy(<?= $policy['id'] ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger" onclick="deletePolicy(<?= $policy['id'] ?>, '<?= htmlspecialchars($policy['policy_name']) ?>')">
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
    <?php endif; ?>

    <!-- Scaling History -->
    <?php if (!empty($scalingHistory)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-history"></i> Recent Scaling Activity</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Server</th>
                                        <th>Policy</th>
                                        <th>Action</th>
                                        <th>Configuration</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($scalingHistory as $item): ?>
                                        <tr>
                                            <td>
                                                <?= date('M j, H:i', strtotime($item['executed_at'])) ?><br>
                                                <small class="text-muted"><?= $item['completed_at'] ? 'Completed' : 'In Progress' ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($item['instance_name']) ?><br>
                                                <small class="text-muted"><?= $item['instance_id'] ?></small>
                                            </td>
                                            <td>
                                                <?= $item['policy_name'] ? htmlspecialchars($item['policy_name']) : '<em>Manual</em>' ?>
                                            </td>
                                            <td>
                                                <?php if ($item['action_type'] === 'scale_up'): ?>
                                                    <span class="badge badge-success">Scale Up</span>
                                                <?php elseif ($item['action_type'] === 'scale_down'): ?>
                                                    <span class="badge badge-warning">Scale Down</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info">Manual</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($item['old_configuration']) && !empty($item['new_configuration'])): ?>
                                                    <?= $item['old_configuration']['cores'] ?? '?' ?>→<?= $item['new_configuration']['cores'] ?? '?' ?> cores<br>
                                                    <small><?= round(($item['old_configuration']['ram_mb'] ?? 0) / 1024, 1) ?>→<?= round(($item['new_configuration']['ram_mb'] ?? 0) / 1024, 1) ?>GB RAM</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($item['status'] === 'success'): ?>
                                                    <span class="badge badge-success">Success</span>
                                                <?php elseif ($item['status'] === 'failed'): ?>
                                                    <span class="badge badge-danger">Failed</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">In Progress</span>
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
</div>

<!-- Create Policy Modal -->
<div class="modal fade" id="createPolicyModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="scaling_action" value="create_policy">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create Auto-scaling Policy</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Auto-scaling policies monitor server metrics and automatically adjust resources when thresholds are exceeded.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Policy Name</label>
                                <input type="text" name="policy_name" class="form-control" required
                                       placeholder="e.g., High CPU Scale-Up">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Policy Type</label>
                                <select name="policy_type" class="form-control" required>
                                    <option value="scale_up">Scale Up (Increase Resources)</option>
                                    <option value="scale_down">Scale Down (Decrease Resources)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Monitor Metric</label>
                                <select name="metric_type" class="form-control" required>
                                    <option value="cpu">CPU Usage</option>
                                    <option value="memory">Memory Usage</option>
                                    <option value="disk">Disk Usage</option>
                                    <option value="network">Network Usage</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Threshold (%)</label>
                                <input type="number" name="threshold_value" class="form-control" required
                                       min="1" max="100" placeholder="80">
                                <small class="form-text text-muted">Trigger when metric exceeds this percentage</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Duration (seconds)</label>
                                <input type="number" name="threshold_duration" class="form-control" 
                                       value="300" min="60" max="3600">
                                <small class="form-text text-muted">How long threshold must be exceeded</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Cooldown Period (seconds)</label>
                                <input type="number" name="cooldown_period" class="form-control" 
                                       value="1800" min="300" max="7200">
                                <small class="form-text text-muted">Wait time between scaling actions</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Scaling Action</label>
                        <select name="scaling_action" class="form-control" required>
                            <option value="upgrade_plan">Upgrade Server Plan</option>
                            <option value="add_resources">Add Additional Resources</option>
                        </select>
                    </div>
                    
                    <h6>Target Configuration</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Target CPU Cores</label>
                                <input type="number" name="target_cores" class="form-control" 
                                       min="1" max="16" placeholder="4">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Target RAM (MB)</label>
                                <input type="number" name="target_ram" class="form-control" 
                                       min="1024" step="1024" placeholder="8192">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Target Disk (MB)</label>
                                <input type="number" name="target_disk" class="form-control" 
                                       min="25600" step="1024" placeholder="51200">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Notification Email</label>
                                <input type="email" name="notification_email" class="form-control"
                                       placeholder="admin@example.com">
                                <small class="form-text text-muted">Optional: Get notified when scaling occurs</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Max Actions Per Day</label>
                                <input type="number" name="max_scale_actions" class="form-control" 
                                       value="3" min="1" max="10">
                                <small class="form-text text-muted">Prevent excessive scaling</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Policy</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manual Scaling Modal -->
<div class="modal fade" id="manualScaleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="scaling_action" value="manual_scale">
                
                <div class="modal-header">
                    <h5 class="modal-title">Manual Server Scaling</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Manual scaling will immediately change your server configuration and may affect pricing.
                    </div>
                    
                    <div class="form-group">
                        <label>Scaling Type</label>
                        <select name="scaling_type" class="form-control" required>
                            <option value="scale_up">Scale Up (Increase Resources)</option>
                            <option value="scale_down">Scale Down (Decrease Resources)</option>
                        </select>
                    </div>
                    
                    <h6>New Configuration</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>CPU Cores</label>
                                <input type="number" name="target_cores" class="form-control" required
                                       min="1" max="16" value="<?= $availableConfigs['current_configuration']['cores'] ?? 2 ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>RAM (MB)</label>
                                <input type="number" name="target_ram" class="form-control" required
                                       min="1024" step="1024" value="<?= $availableConfigs['current_configuration']['ram_mb'] ?? 4096 ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Disk (MB)</label>
                                <input type="number" name="target_disk" class="form-control" required
                                       min="25600" step="1024" value="<?= $availableConfigs['current_configuration']['disk_mb'] ?? 51200 ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Apply Scaling</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreatePolicy() {
    if (!<?= json_encode($instanceId !== null) ?>) {
        alert('Please select a server first');
        return;
    }
    $('#createPolicyModal').modal('show');
}

function showManualScale() {
    if (!<?= json_encode($instanceId !== null) ?>) {
        alert('Please select a server first');
        return;
    }
    $('#manualScaleModal').modal('show');
}

function checkAllPolicies() {
    if (confirm('Check and execute all active scaling policies? This may trigger scaling actions.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="scaling_action" value="check_policies">';
        document.body.appendChild(form);
        form.submit();
    }
}

function viewScalingHistory() {
    // Scroll to history section or implement separate page
    if (document.querySelector('.scaling-management .card:last-child')) {
        document.querySelector('.scaling-management .card:last-child').scrollIntoView({ behavior: 'smooth' });
    }
}

function editPolicy(policyId) {
    // In real implementation, would populate form with existing data
    alert('Edit policy functionality - would load existing policy data');
}

function deletePolicy(policyId, policyName) {
    if (confirm('Are you sure you want to delete the scaling policy "' + policyName + '"?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=scaling&scaling_action=delete_policy&policy_id=' + policyId + '&instance_id=<?= $instanceId ?>';
    }
}
</script>
