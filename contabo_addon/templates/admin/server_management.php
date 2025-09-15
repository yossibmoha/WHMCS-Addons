<?php
/**
 * Admin Server Management Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\AdminServerService;
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

$serverService = new AdminServerService($apiClient);

// Handle actions
$action = $_GET['server_action'] ?? 'list';
$message = '';
$error = '';

try {
    switch ($action) {
        case 'attach':
            if ($_POST && isset($_POST['contabo_instance_id'], $_POST['service_id'])) {
                $result = $serverService->attachServerToService(
                    $_POST['contabo_instance_id'], 
                    $_POST['service_id'],
                    ['update_service_status' => $_POST['update_status'] ?? false]
                );
                $message = 'Server successfully attached to WHMCS service';
            }
            break;
            
        case 'detach':
            if (isset($_GET['instance_id'])) {
                $serverService->detachServerFromService($_GET['instance_id']);
                $message = 'Server detached from WHMCS service';
            }
            break;
            
        case 'bulk_import':
            if ($_POST) {
                $result = $serverService->bulkImportUntrackedServers([
                    'auto_create_services' => $_POST['auto_create_services'] ?? false
                ]);
                $message = "Bulk import completed. Imported: {$result['imported']}, Skipped: {$result['skipped']}";
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get all servers
$allServers = [];
$stats = [];
$availableServices = [];

try {
    $serversResponse = $serverService->getAllContaboServers();
    $allServers = $serversResponse['servers'] ?? [];
    $stats = $serverService->getServerManagementStats();
    $availableServices = $serverService->getAvailableServicesForAttachment();
} catch (Exception $e) {
    $error = 'Failed to load server data: ' . $e->getMessage();
}
?>

<div class="contabo-server-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-server"></i> Server Management</h2>
            <p>View all Contabo servers and attach them to WHMCS services</p>
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
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h4><i class="fas fa-server"></i> <?= $stats['total_contabo_servers'] ?? 0 ?></h4>
                    <p>Total Contabo Servers</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h4><i class="fas fa-link"></i> <?= $stats['tracked_servers'] ?? 0 ?></h4>
                    <p>Tracked in WHMCS</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h4><i class="fas fa-unlink"></i> <?= $stats['untracked_servers'] ?? 0 ?></h4>
                    <p>Not Tracked</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h4><i class="fas fa-percentage"></i> <?= $stats['tracking_percentage'] ?? 0 ?>%</h4>
                    <p>Tracking Rate</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-tools"></i> Quick Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="refreshServerList()">
                            <i class="fas fa-sync"></i> Refresh Server List
                        </button>
                        <button class="btn btn-warning" onclick="bulkImport()">
                            <i class="fas fa-download"></i> Bulk Import Untracked
                        </button>
                        <button class="btn btn-info" onclick="exportServerList()">
                            <i class="fas fa-file-export"></i> Export Server List
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Server List -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-list"></i> All Contabo Servers</h4>
                    <div class="form-inline">
                        <select id="filterStatus" class="form-control mr-2" onchange="filterServers()">
                            <option value="">All Status</option>
                            <option value="running">Running</option>
                            <option value="stopped">Stopped</option>
                            <option value="provisioning">Provisioning</option>
                        </select>
                        <select id="filterTracking" class="form-control mr-2" onchange="filterServers()">
                            <option value="">All Servers</option>
                            <option value="tracked">Tracked Only</option>
                            <option value="untracked">Untracked Only</option>
                        </select>
                        <input type="text" id="searchServers" class="form-control" placeholder="Search servers..." onkeyup="filterServers()">
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($allServers)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No Contabo servers found. Check your API configuration.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped" id="serversTable">
                                <thead>
                                    <tr>
                                        <th>Server Details</th>
                                        <th>Status</th>
                                        <th>Specifications</th>
                                        <th>Location</th>
                                        <th>WHMCS Integration</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allServers as $server): ?>
                                        <tr data-status="<?= $server['status'] ?>" data-tracking="<?= $server['is_tracked'] ? 'tracked' : 'untracked' ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($server['name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($server['contabo_id']) ?></small><br>
                                                <code><?= htmlspecialchars($server['ip_address']) ?></code>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= $server['status'] === 'running' ? 'success' : ($server['status'] === 'stopped' ? 'danger' : 'warning') ?>">
                                                    <?= htmlspecialchars(ucfirst($server['status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <i class="fas fa-microchip"></i> <?= $server['specs']['cpu'] ?> vCPU<br>
                                                    <i class="fas fa-memory"></i> <?= round($server['specs']['ram'] / 1024, 1) ?>GB RAM<br>
                                                    <i class="fas fa-hdd"></i> <?= round($server['specs']['disk'] / 1024, 1) ?>GB Disk
                                                </small>
                                            </td>
                                            <td>
                                                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($server['region']) ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($server['product_id']) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($server['is_tracked']): ?>
                                                    <span class="badge badge-success">
                                                        <i class="fas fa-link"></i> Tracked
                                                    </span><br>
                                                    <?php if ($server['whmcs_user']): ?>
                                                        <small>
                                                            <strong>Client:</strong> <?= htmlspecialchars($server['whmcs_user']['name']) ?><br>
                                                            <strong>Email:</strong> <?= htmlspecialchars($server['whmcs_user']['email']) ?><br>
                                                            <strong>Service ID:</strong> #<?= $server['whmcs_user']['service_id'] ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">
                                                        <i class="fas fa-unlink"></i> Not Tracked
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if ($server['is_tracked']): ?>
                                                        <button class="btn btn-info" onclick="viewServerDetails('<?= $server['contabo_id'] ?>')">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                        <button class="btn btn-warning" onclick="detachServer('<?= $server['contabo_id'] ?>')">
                                                            <i class="fas fa-unlink"></i> Detach
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-success" onclick="attachServer('<?= $server['contabo_id'] ?>', '<?= htmlspecialchars($server['name']) ?>')">
                                                            <i class="fas fa-link"></i> Attach
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <div class="dropdown-menu">
                                                        <a class="dropdown-item" onclick="viewContaboConsole('<?= $server['contabo_id'] ?>')">
                                                            <i class="fas fa-external-link-alt"></i> Contabo Console
                                                        </a>
                                                        <a class="dropdown-item" onclick="showServerLogs('<?= $server['contabo_id'] ?>')">
                                                            <i class="fas fa-file-alt"></i> View Logs
                                                        </a>
                                                    </div>
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

<!-- Attach Server Modal -->
<div class="modal fade" id="attachServerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="server_action" value="attach">
                <input type="hidden" name="contabo_instance_id" id="attach_instance_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Attach Server to WHMCS Service</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Server</label>
                        <input type="text" id="attach_server_name" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>WHMCS Service</label>
                        <select name="service_id" class="form-control" required>
                            <option value="">Select a service...</option>
                            <?php foreach ($availableServices as $service): ?>
                                <option value="<?= $service['service_id'] ?>">
                                    #<?= $service['service_id'] ?> - <?= htmlspecialchars($service['domain']) ?> 
                                    (<?= htmlspecialchars($service['client']['name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            Only services without existing server attachments are shown.
                        </small>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" name="update_status" value="1" class="form-check-input" id="updateStatus">
                        <label class="form-check-label" for="updateStatus">
                            Update WHMCS service status to match server status
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Attach Server</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Import Modal -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="server_action" value="bulk_import">
                
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Import Untracked Servers</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This will attempt to import all untracked Contabo servers into WHMCS.
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" name="auto_create_services" value="1" class="form-check-input" id="autoCreateServices">
                        <label class="form-check-label" for="autoCreateServices">
                            Automatically create WHMCS services for servers without existing services
                        </label>
                        <small class="form-text text-muted">
                            If unchecked, only servers that can be matched to existing services will be imported.
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Start Bulk Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function attachServer(instanceId, serverName) {
    document.getElementById('attach_instance_id').value = instanceId;
    document.getElementById('attach_server_name').value = serverName;
    $('#attachServerModal').modal('show');
}

function detachServer(instanceId) {
    if (confirm('Are you sure you want to detach this server from WHMCS? This will remove the connection but not delete the server.')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=server-management&server_action=detach&instance_id=' + instanceId;
    }
}

function bulkImport() {
    $('#bulkImportModal').modal('show');
}

function refreshServerList() {
    location.reload();
}

function exportServerList() {
    // Implement export functionality
    alert('Export functionality coming soon');
}

function filterServers() {
    const statusFilter = document.getElementById('filterStatus').value;
    const trackingFilter = document.getElementById('filterTracking').value;
    const searchTerm = document.getElementById('searchServers').value.toLowerCase();
    
    const rows = document.querySelectorAll('#serversTable tbody tr');
    
    rows.forEach(row => {
        const status = row.dataset.status;
        const tracking = row.dataset.tracking;
        const text = row.textContent.toLowerCase();
        
        const statusMatch = !statusFilter || status === statusFilter;
        const trackingMatch = !trackingFilter || tracking === trackingFilter;
        const searchMatch = !searchTerm || text.includes(searchTerm);
        
        if (statusMatch && trackingMatch && searchMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function viewServerDetails(instanceId) {
    window.open('<?= $vars['modulelink'] ?>&action=instances&view=' + instanceId, '_blank');
}

function viewContaboConsole(instanceId) {
    window.open('https://my.contabo.com/server/' + instanceId, '_blank');
}

function showServerLogs(instanceId) {
    window.open('<?= $vars['modulelink'] ?>&action=logs&filter=instance&instance_id=' + instanceId, '_blank');
}
</script>
