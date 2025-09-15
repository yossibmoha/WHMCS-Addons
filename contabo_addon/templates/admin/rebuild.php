<?php
/**
 * Admin Rebuild Management Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\RebuildService;
use ContaboAddon\Services\AdminServerService;
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

$rebuildService = new RebuildService($apiClient);
$serverService = new AdminServerService($apiClient);

// Handle actions
$action = $_GET['rebuild_action'] ?? 'list';
$message = '';
$error = '';

try {
    switch ($action) {
        case 'rebuild':
            if ($_POST && isset($_POST['instance_id'], $_POST['image_id'])) {
                $result = $rebuildService->rebuildInstance(
                    $_POST['instance_id'],
                    [
                        'imageId' => $_POST['image_id'],
                        'imageName' => $_POST['image_name'] ?? 'Unknown',
                        'sshKeys' => $_POST['ssh_keys'] ?? [],
                        'userData' => $_POST['cloud_init'] ?? null
                    ],
                    true // isAdmin = true
                );
                $message = 'Server rebuild initiated successfully for ' . $_POST['instance_id'];
            }
            break;
            
        case 'bulk_rebuild':
            if ($_POST && isset($_POST['instance_ids'], $_POST['image_id'])) {
                $instanceIds = explode(',', $_POST['instance_ids']);
                $results = [];
                
                foreach ($instanceIds as $instanceId) {
                    try {
                        $result = $rebuildService->rebuildInstance(
                            trim($instanceId),
                            [
                                'imageId' => $_POST['image_id'],
                                'imageName' => $_POST['image_name'] ?? 'Unknown'
                            ],
                            true
                        );
                        $results[] = ['instance' => $instanceId, 'status' => 'success'];
                    } catch (Exception $e) {
                        $results[] = ['instance' => $instanceId, 'status' => 'failed', 'error' => $e->getMessage()];
                    }
                }
                
                $successCount = count(array_filter($results, function($r) { return $r['status'] === 'success'; }));
                $message = "Bulk rebuild completed: {$successCount}/" . count($results) . " servers processed successfully";
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get available operating systems and custom images
$operatingSystems = [];
$customImages = [];
$activeRebuilds = [];

try {
    $operatingSystems = $rebuildService->getAvailableOperatingSystems();
    $customImages = $rebuildService->getCustomImages();
    
    // Get active rebuild operations
    $activeRebuilds = Capsule::table('mod_contabo_instances')
        ->leftJoin('tblhosting', 'mod_contabo_instances.service_id', '=', 'tblhosting.id')
        ->leftJoin('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
        ->where('mod_contabo_instances.status', 'provisioning')
        ->select(
            'mod_contabo_instances.*',
            'tblhosting.domain',
            'tblclients.firstname',
            'tblclients.lastname'
        )
        ->get();
        
} catch (Exception $e) {
    $error = 'Failed to load rebuild data: ' . $e->getMessage();
}

// Get rebuild statistics
$rebuildStats = [
    'total_rebuilds_today' => 0,
    'total_rebuilds_week' => 0,
    'active_rebuilds' => count($activeRebuilds),
    'most_popular_os' => 'Ubuntu 22.04 LTS'
];

try {
    $rebuildStats['total_rebuilds_today'] = Capsule::table('mod_contabo_api_logs')
        ->where('action', 'instance_rebuild')
        ->whereDate('created_at', today())
        ->count();
        
    $rebuildStats['total_rebuilds_week'] = Capsule::table('mod_contabo_api_logs')
        ->where('action', 'instance_rebuild')
        ->where('created_at', '>=', date('Y-m-d', strtotime('-7 days')))
        ->count();
} catch (Exception $e) {
    // Continue with default values
}
?>

<div class="server-rebuild-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-hammer"></i> Server Rebuild Management</h2>
            <p>Rebuild servers with fresh operating systems or custom images</p>
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

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h4><i class="fas fa-today"></i> <?= $rebuildStats['total_rebuilds_today'] ?></h4>
                    <p>Rebuilds Today</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h4><i class="fas fa-calendar-week"></i> <?= $rebuildStats['total_rebuilds_week'] ?></h4>
                    <p>Rebuilds This Week</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h4><i class="fas fa-spinner"></i> <?= $rebuildStats['active_rebuilds'] ?></h4>
                    <p>Active Rebuilds</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h4><i class="fab fa-ubuntu"></i></h4>
                    <p>Most Popular OS</p>
                    <small><?= htmlspecialchars($rebuildStats['most_popular_os']) ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-tools"></i> Quick Rebuild Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-warning" onclick="showSingleRebuild()">
                            <i class="fas fa-hammer"></i> Single Server Rebuild
                        </button>
                        <button class="btn btn-danger" onclick="showBulkRebuild()">
                            <i class="fas fa-layer-group"></i> Bulk Rebuild
                        </button>
                        <button class="btn btn-info" onclick="showRebuildPresets()">
                            <i class="fas fa-list"></i> Rebuild Presets
                        </button>
                        <button class="btn btn-success" onclick="refreshRebuildStatus()">
                            <i class="fas fa-sync"></i> Refresh Status
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Rebuilds -->
    <?php if (!empty($activeRebuilds)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-spinner fa-spin"></i> Active Rebuilds</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Server Details</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                        <th>Started</th>
                                        <th>Estimated Completion</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeRebuilds as $rebuild): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($rebuild->name) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($rebuild->contabo_instance_id) ?></small><br>
                                                <code><?= htmlspecialchars($rebuild->domain) ?></code>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($rebuild->firstname . ' ' . $rebuild->lastname) ?><br>
                                                <small class="text-muted">Service #<?= $rebuild->service_id ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-warning">
                                                    <i class="fas fa-spinner fa-spin"></i> Rebuilding
                                                </span>
                                            </td>
                                            <td>
                                                <?= date('M j, H:i', strtotime($rebuild->updated_at)) ?>
                                            </td>
                                            <td>
                                                <span id="eta-<?= $rebuild->contabo_instance_id ?>">
                                                    ~<?= date('H:i', strtotime($rebuild->updated_at . ' +15 minutes')) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-info" onclick="checkRebuildStatus('<?= $rebuild->contabo_instance_id ?>')">
                                                        <i class="fas fa-eye"></i> Check Status
                                                    </button>
                                                    <button class="btn btn-secondary" onclick="viewRebuildHistory('<?= $rebuild->contabo_instance_id ?>')">
                                                        <i class="fas fa-history"></i> History
                                                    </button>
                                                </div>
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

    <!-- Available Operating Systems -->
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fab fa-linux"></i> Available Operating Systems</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($operatingSystems)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> No operating systems found. Check your API configuration.
                        </div>
                    <?php else: ?>
                        <?php
                        $categories = [];
                        foreach ($operatingSystems as $os) {
                            $categories[$os['category']][] = $os;
                        }
                        ?>
                        
                        <?php foreach ($categories as $category => $osList): ?>
                            <div class="os-category mb-4">
                                <h5 class="border-bottom pb-2">
                                    <?php if ($category === 'Ubuntu'): ?>
                                        <i class="fab fa-ubuntu text-orange"></i>
                                    <?php elseif ($category === 'Debian'): ?>
                                        <i class="fab fa-debian text-red"></i>
                                    <?php elseif ($category === 'CentOS'): ?>
                                        <i class="fab fa-centos text-purple"></i>
                                    <?php elseif ($category === 'Windows'): ?>
                                        <i class="fab fa-windows text-blue"></i>
                                    <?php else: ?>
                                        <i class="fab fa-linux"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($category) ?>
                                </h5>
                                <div class="row">
                                    <?php foreach ($osList as $os): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100 <?= $os['recommended'] ? 'border-success' : '' ?>">
                                                <div class="card-body">
                                                    <h6 class="card-title d-flex justify-content-between align-items-center">
                                                        <?= htmlspecialchars($os['name']) ?>
                                                        <?php if ($os['recommended']): ?>
                                                            <span class="badge badge-success">Recommended</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <?php if ($os['description']): ?>
                                                        <p class="card-text small text-muted">
                                                            <?= htmlspecialchars($os['description']) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <button class="btn btn-sm btn-primary" 
                                                            onclick="selectOSForRebuild('<?= $os['id'] ?>', '<?= htmlspecialchars($os['name']) ?>')">
                                                        <i class="fas fa-rocket"></i> Use for Rebuild
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Custom Images -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-images"></i> Custom Images</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($customImages)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No custom images uploaded yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($customImages as $image): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h6 class="card-title"><?= htmlspecialchars($image['name']) ?></h6>
                                    <p class="card-text small">
                                        Size: <?= round($image['size_mb'] / 1024, 1) ?>GB<br>
                                        <?php if ($image['created_date']): ?>
                                            Created: <?= date('M j, Y', strtotime($image['created_date'])) ?>
                                        <?php endif; ?>
                                    </p>
                                    <button class="btn btn-sm btn-warning" 
                                            onclick="selectOSForRebuild('<?= $image['id'] ?>', '<?= htmlspecialchars($image['name']) ?>')">
                                        <i class="fas fa-hammer"></i> Use for Rebuild
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Single Server Rebuild Modal -->
<div class="modal fade" id="singleRebuildModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="rebuild_action" value="rebuild">
                <input type="hidden" name="image_id" id="selected_image_id">
                <input type="hidden" name="image_name" id="selected_image_name">
                
                <div class="modal-header">
                    <h5 class="modal-title">Single Server Rebuild</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This will completely wipe the server and reinstall the operating system. 
                        All data will be permanently lost.
                    </div>
                    
                    <div class="form-group">
                        <label>Server to Rebuild</label>
                        <select name="instance_id" class="form-control" required id="instanceSelect">
                            <option value="">Loading servers...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Selected Operating System / Image</label>
                        <input type="text" id="selected_os_display" class="form-control" readonly 
                               placeholder="Click 'Use for Rebuild' on an OS above">
                    </div>
                    
                    <div class="form-group">
                        <label>Additional Options</label>
                        <div class="form-check">
                            <input type="checkbox" name="keep_ssh_keys" class="form-check-input" id="keepSSHKeys">
                            <label class="form-check-label" for="keepSSHKeys">
                                Keep existing SSH keys (if any)
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-hammer"></i> Rebuild Server
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showSingleRebuild() {
    // Load server list
    loadServerList();
    $('#singleRebuildModal').modal('show');
}

function showBulkRebuild() {
    alert('Bulk rebuild feature coming soon');
}

function showRebuildPresets() {
    alert('Rebuild presets feature coming soon');
}

function refreshRebuildStatus() {
    location.reload();
}

function selectOSForRebuild(imageId, imageName) {
    document.getElementById('selected_image_id').value = imageId;
    document.getElementById('selected_image_name').value = imageName;
    document.getElementById('selected_os_display').value = imageName;
    
    showSingleRebuild();
}

function loadServerList() {
    fetch('<?= $vars['modulelink'] ?>&action=server-management&ajax=1&get_servers=1')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('instanceSelect');
            select.innerHTML = '<option value="">Select a server to rebuild...</option>';
            
            if (data.success && data.servers) {
                data.servers.forEach(server => {
                    const option = document.createElement('option');
                    option.value = server.contabo_id;
                    option.textContent = `${server.name} (${server.ip_address}) - ${server.whmcs_user ? server.whmcs_user.name : 'Unassigned'}`;
                    select.appendChild(option);
                });
            }
        })
        .catch(error => {
            const select = document.getElementById('instanceSelect');
            select.innerHTML = '<option value="">Error loading servers</option>';
        });
}

function checkRebuildStatus(instanceId) {
    // Open status monitoring window
    window.open('<?= $vars['modulelink'] ?>&action=instances&view=' + instanceId, '_blank');
}

function viewRebuildHistory(instanceId) {
    // Open history window
    window.open('<?= $vars['modulelink'] ?>&action=logs&filter=instance&instance_id=' + instanceId, '_blank');
}
</script>
