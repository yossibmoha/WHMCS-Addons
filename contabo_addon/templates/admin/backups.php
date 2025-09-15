<?php
/**
 * Backup Management Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\BackupService;
use ContaboAddon\Services\ComputeService;
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

$backupService = new BackupService($apiClient);
$computeService = new ComputeService($apiClient, $log);

// Handle actions
$action = $_GET['backup_action'] ?? 'list';
$instanceId = $_GET['instance_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'enable':
            if ($instanceId && $_POST) {
                $config = [
                    'retention_days' => (int)($_POST['retention_days'] ?? 7),
                    'schedule' => $_POST['schedule'] ?? 'daily'
                ];
                $result = $backupService->enableBackup($instanceId, $config);
                $message = 'Backup enabled successfully for instance ' . $instanceId;
            }
            break;
            
        case 'disable':
            if ($instanceId) {
                $backupService->disableBackup($instanceId);
                $message = 'Backup disabled for instance ' . $instanceId;
            }
            break;
            
        case 'restore':
            if ($instanceId && $_POST) {
                $backupId = $_POST['backup_id'];
                $result = $backupService->restoreFromBackup($instanceId, $backupId);
                $message = 'Restore operation initiated. ID: ' . $result['restore_id'];
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get all instances for backup management
$instances = [];
try {
    $instancesResponse = $computeService->listInstances();
    $instances = $instancesResponse['data'] ?? [];
} catch (Exception $e) {
    $error = 'Failed to load instances: ' . $e->getMessage();
}

// Get backup pricing
$pricingTiers = $backupService->getBackupPricingTiers();
$usageStats = $backupService->getBackupStorageUsage();
?>

<div class="contabo-backup-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-hdd"></i> Backup Management</h2>
            <p>Manage automated backups for your Contabo instances</p>
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

    <!-- Backup Overview Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5><i class="fas fa-server"></i> <?= $usageStats['total_instances_with_backup'] ?? 0 ?></h5>
                    <p>Instances with Backup</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5><i class="fas fa-archive"></i> <?= $usageStats['total_backups'] ?? 0 ?></h5>
                    <p>Total Backups</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5><i class="fas fa-database"></i> <?= $usageStats['total_size_gb'] ?? 0 ?> GB</h5>
                    <p>Storage Used</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5><i class="fas fa-euro-sign"></i> €<?= $usageStats['estimated_monthly_cost'] ?? 0 ?></h5>
                    <p>Monthly Cost</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup Pricing Tiers -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-tags"></i> Backup Pricing Tiers</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($pricingTiers as $tier): ?>
                            <div class="col-md-3">
                                <div class="card border-secondary">
                                    <div class="card-header bg-secondary text-white text-center">
                                        <h5><?= htmlspecialchars($tier['name']) ?></h5>
                                        <h3>€<?= $tier['monthly_price'] ?>/mo</h3>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-unstyled">
                                            <?php foreach ($tier['features'] as $feature): ?>
                                                <li><i class="fas fa-check text-success"></i> <?= htmlspecialchars($feature) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Instance Backup Management -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-list"></i> Instance Backup Status</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($instances)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No instances found. Create some instances first to enable backup management.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Instance</th>
                                        <th>Status</th>
                                        <th>Backup Status</th>
                                        <th>Retention</th>
                                        <th>Last Backup</th>
                                        <th>Storage Used</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($instances as $instance): ?>
                                        <?php
                                        $backupConfig = [];
                                        $backupHistory = [];
                                        try {
                                            $backupConfig = $backupService->getBackupConfig($instance['instanceId']);
                                            if ($backupConfig['enabled']) {
                                                $backupHistory = $backupService->getBackupHistory($instance['instanceId'], 1);
                                                $usage = $backupService->getBackupStorageUsage($instance['instanceId']);
                                            }
                                        } catch (Exception $e) {
                                            // Instance doesn't have backup configured
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($instance['name'] ?? $instance['displayName']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($instance['instanceId']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= $instance['status'] === 'running' ? 'success' : 'secondary' ?>">
                                                    <?= htmlspecialchars(ucfirst($instance['status'] ?? 'unknown')) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($backupConfig['enabled'] ?? false): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> Enabled</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary"><i class="fas fa-times"></i> Disabled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $backupConfig['enabled'] ? ($backupConfig['retention_days'] . ' days') : '-' ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($backupHistory['backups'])): ?>
                                                    <?= date('M j, H:i', strtotime($backupHistory['backups'][0]['created_at'])) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($usage)): ?>
                                                    <?= $usage['total_size_gb'] ?? 0 ?> GB
                                                <?php else: ?>
                                                    <span class="text-muted">0 GB</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if ($backupConfig['enabled'] ?? false): ?>
                                                        <button class="btn btn-info" onclick="viewBackups('<?= $instance['instanceId'] ?>')">
                                                            <i class="fas fa-list"></i> View
                                                        </button>
                                                        <button class="btn btn-warning" onclick="configureBackup('<?= $instance['instanceId'] ?>')">
                                                            <i class="fas fa-cog"></i> Config
                                                        </button>
                                                        <button class="btn btn-danger" onclick="disableBackup('<?= $instance['instanceId'] ?>')">
                                                            <i class="fas fa-times"></i> Disable
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-success" onclick="enableBackup('<?= $instance['instanceId'] ?>')">
                                                            <i class="fas fa-plus"></i> Enable
                                                        </button>
                                                    <?php endif; ?>
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
</div>

<!-- Enable Backup Modal -->
<div class="modal fade" id="enableBackupModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="backup_action" value="enable">
                <input type="hidden" name="instance_id" id="enable_instance_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Enable Backup</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Retention Period</label>
                        <select name="retention_days" class="form-control" required>
                            <option value="7">7 days (€4.99/month)</option>
                            <option value="14">14 days (€7.99/month)</option>
                            <option value="30" selected>30 days (€12.99/month)</option>
                            <option value="60">60 days (€19.99/month)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Backup Schedule</label>
                        <select name="schedule" class="form-control">
                            <option value="daily" selected>Daily (2:00 AM)</option>
                            <option value="hourly">Hourly</option>
                            <option value="weekly">Weekly (Sunday 2:00 AM)</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> Backup will be automatically enabled and charged to your Contabo account.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Enable Backup</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Backups Modal -->
<div class="modal fade" id="viewBackupsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Backup History</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" id="backupHistoryContent">
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function enableBackup(instanceId) {
    document.getElementById('enable_instance_id').value = instanceId;
    $('#enableBackupModal').modal('show');
}

function disableBackup(instanceId) {
    if (confirm('Are you sure you want to disable backup for this instance? This will stop all future backups but existing backups will remain.')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=backups&backup_action=disable&instance_id=' + instanceId;
    }
}

function viewBackups(instanceId) {
    $('#viewBackupsModal').modal('show');
    
    // Load backup history via AJAX (would need to implement AJAX endpoint)
    document.getElementById('backupHistoryContent').innerHTML = 
        '<div class="alert alert-info">AJAX endpoint needed to load backup history for instance: ' + instanceId + '</div>';
}

function configureBackup(instanceId) {
    // Open configuration modal (would need to implement)
    alert('Backup configuration for instance: ' + instanceId + ' (to be implemented)');
}
</script>
