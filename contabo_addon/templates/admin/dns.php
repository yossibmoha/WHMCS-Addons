<?php
/**
 * DNS Management Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\DNSService;
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

$dnsService = new DNSService($apiClient);

// Handle actions
$action = $_GET['dns_action'] ?? 'dashboard';
$zoneId = $_GET['zone_id'] ?? null;
$recordId = $_GET['record_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'create_zone':
            if ($_POST) {
                $result = $dnsService->createDNSZone($_POST['domain_name'], [
                    'ip_address' => $_POST['ip_address'] ?? null,
                    'nameservers' => !empty($_POST['nameservers']) ? explode(',', $_POST['nameservers']) : null
                ]);
                $message = 'DNS zone created successfully for ' . $_POST['domain_name'];
            }
            break;
            
        case 'create_record':
            if ($_POST && $zoneId) {
                $result = $dnsService->createDNSRecord($zoneId, [
                    'name' => $_POST['name'],
                    'type' => $_POST['type'],
                    'content' => $_POST['content'],
                    'ttl' => $_POST['ttl'],
                    'priority' => $_POST['priority'] ?? null
                ]);
                $message = 'DNS record created successfully';
            }
            break;
            
        case 'update_record':
            if ($_POST && $recordId) {
                $result = $dnsService->updateDNSRecord($recordId, [
                    'name' => $_POST['name'],
                    'type' => $_POST['type'],
                    'content' => $_POST['content'],
                    'ttl' => $_POST['ttl'],
                    'priority' => $_POST['priority'] ?? null
                ]);
                $message = 'DNS record updated successfully';
            }
            break;
            
        case 'delete_record':
            if ($recordId) {
                $result = $dnsService->deleteDNSRecord($recordId);
                $message = 'DNS record deleted successfully';
            }
            break;
            
        case 'delete_zone':
            if ($zoneId && $_POST['confirm'] === 'yes') {
                $result = $dnsService->deleteDNSZone($zoneId);
                $message = 'DNS zone deleted successfully';
                $zoneId = null; // Reset zone selection
            }
            break;
            
        case 'import_zone':
            if ($_POST && !empty($_FILES['zone_file']['tmp_name'])) {
                $zoneContent = file_get_contents($_FILES['zone_file']['tmp_name']);
                $result = $dnsService->importDNSZone($_POST['domain_name'], $zoneContent);
                $message = $result['message'];
            }
            break;
            
        case 'export_zone':
            if ($zoneId) {
                $result = $dnsService->exportDNSZone($zoneId);
                // Set headers for file download
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                echo $result['zone_file'];
                exit;
            }
            break;
            
        case 'check_propagation':
            if ($_POST) {
                $propagationResult = $dnsService->checkDNSPropagation(
                    $_POST['domain_name'], 
                    $_POST['record_type'] ?? 'A'
                );
                // Store result for display
                $_SESSION['propagation_result'] = $propagationResult;
                $message = "DNS propagation check completed for " . $_POST['domain_name'];
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get DNS statistics
$stats = [];
try {
    $stats = $dnsService->getDNSStatistics();
} catch (Exception $e) {
    $error = 'Failed to load DNS statistics: ' . $e->getMessage();
}

// Get all DNS zones
$zones = [];
try {
    $zones = $dnsService->getDNSZones();
} catch (Exception $e) {
    // Continue with empty array
}

// Get DNS records for selected zone
$zoneRecords = [];
$selectedZone = null;
if ($zoneId) {
    try {
        $zoneRecords = $dnsService->getDNSRecords($zoneId);
        $selectedZone = array_filter($zones, function($z) use ($zoneId) { 
            return $z['id'] == $zoneId; 
        });
        $selectedZone = !empty($selectedZone) ? array_values($selectedZone)[0] : null;
    } catch (Exception $e) {
        $error = 'Failed to load DNS records: ' . $e->getMessage();
    }
}

// Get propagation check result from session
$propagationResult = $_SESSION['propagation_result'] ?? null;
unset($_SESSION['propagation_result']);
?>

<div class="dns-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-globe"></i> DNS Management</h2>
            <p>Manage DNS zones, records, and domain configuration</p>
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

    <!-- DNS Statistics -->
    <?php if (!empty($stats)): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-globe"></i> <?= $stats['total_zones'] ?? 0 ?></h4>
                        <p>DNS Zones</p>
                        <small><?= $stats['active_zones'] ?? 0 ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-list"></i> <?= $stats['total_records'] ?? 0 ?></h4>
                        <p>DNS Records</p>
                        <small><?= $stats['disabled_records'] ?? 0 ?> disabled</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-chart-bar"></i> <?= count($stats['record_types'] ?? []) ?></h4>
                        <p>Record Types</p>
                        <small>A, CNAME, MX, etc.</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-tools"></i> Tools</h4>
                        <p>DNS Management</p>
                        <small>Import/Export zones</small>
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
                    <h4><i class="fas fa-bolt"></i> DNS Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="showCreateZone()">
                            <i class="fas fa-plus"></i> Create Zone
                        </button>
                        <button class="btn btn-success" onclick="showCreateRecord()" <?= !$zoneId ? 'disabled' : '' ?>>
                            <i class="fas fa-plus-circle"></i> Add Record
                        </button>
                        <button class="btn btn-info" onclick="showImportZone()">
                            <i class="fas fa-upload"></i> Import Zone
                        </button>
                        <button class="btn btn-warning" onclick="showPropagationCheck()">
                            <i class="fas fa-search"></i> Check Propagation
                        </button>
                        <?php if ($zoneId): ?>
                            <button class="btn btn-secondary" onclick="exportZone(<?= $zoneId ?>)">
                                <i class="fas fa-download"></i> Export Zone
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Zone Selection -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-list"></i> DNS Zones</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($zones)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No DNS zones configured. Create your first DNS zone to get started.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Domain Name</th>
                                        <th>Records</th>
                                        <th>Status</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($zones as $zone): ?>
                                        <tr class="<?= $zoneId == $zone['id'] ? 'table-active' : '' ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($zone['domain_name']) ?></strong><br>
                                                <small class="text-muted">ID: <?= $zone['id'] ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary"><?= $zone['records_count'] ?> records</span>
                                            </td>
                                            <td>
                                                <?php if ($zone['status'] === 'active'): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary"><?= ucfirst($zone['status']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $zone['updated_at'] ? date('M j, Y H:i', strtotime($zone['updated_at'])) : 'Never' ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= $vars['modulelink'] ?>&action=dns&zone_id=<?= $zone['id'] ?>" 
                                                       class="btn btn-info <?= $zoneId == $zone['id'] ? 'active' : '' ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <button class="btn btn-warning" onclick="editZone(<?= $zone['id'] ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger" onclick="deleteZone(<?= $zone['id'] ?>, '<?= htmlspecialchars($zone['domain_name']) ?>')">
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

        <!-- Record Types Distribution -->
        <div class="col-md-4">
            <?php if (!empty($stats['record_types'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> Record Types</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($stats['record_types'] as $type => $count): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><strong><?= $type ?></strong></span>
                                <span class="badge badge-primary"><?= $count ?></span>
                            </div>
                            <div class="progress mb-3" style="height: 8px;">
                                <div class="progress-bar" style="width: <?= ($count / array_sum($stats['record_types'])) * 100 ?>%"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- DNS Records for Selected Zone -->
    <?php if ($zoneId && !empty($zoneRecords)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4><i class="fas fa-list-ul"></i> DNS Records for <?= htmlspecialchars($selectedZone['domain_name']) ?></h4>
                        <button class="btn btn-primary btn-sm" onclick="showCreateRecord()">
                            <i class="fas fa-plus"></i> Add Record
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($zoneRecords['records'])): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No DNS records found. Add some records to manage this domain.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <th>Content</th>
                                            <th>TTL</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($zoneRecords['records'] as $record): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($record['name']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $this->getRecordTypeBadgeColor($record['type']) ?>">
                                                        <?= htmlspecialchars($record['type']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <code><?= htmlspecialchars($record['content']) ?></code>
                                                </td>
                                                <td><?= $record['ttl'] ?></td>
                                                <td><?= $record['priority'] ?: '-' ?></td>
                                                <td>
                                                    <?php if ($record['disabled']): ?>
                                                        <span class="badge badge-secondary">Disabled</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-success">Active</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-warning" onclick="editRecord(<?= $record['id'] ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger" onclick="deleteRecord(<?= $record['id'] ?>, '<?= htmlspecialchars($record['name']) ?>')">
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

    <!-- Propagation Check Results -->
    <?php if ($propagationResult): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-search"></i> DNS Propagation Results - <?= htmlspecialchars($propagationResult['domain_name']) ?></h4>
                        <span class="badge badge-<?= $propagationResult['propagation_percent'] >= 75 ? 'success' : ($propagationResult['propagation_percent'] >= 50 ? 'warning' : 'danger') ?>">
                            <?= $propagationResult['propagation_percent'] ?>% Propagated
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($propagationResult['results'] as $result): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <h6><?= htmlspecialchars($result['provider']) ?></h6>
                                            <p class="mb-1">
                                                <code><?= htmlspecialchars($result['nameserver']) ?></code>
                                            </p>
                                            <?php if ($result['status'] === 'success'): ?>
                                                <span class="badge badge-success">Success</span>
                                                <p class="small mt-2">
                                                    <?php if (!empty($result['records'])): ?>
                                                        <?php foreach ($result['records'] as $record): ?>
                                                            <code><?= htmlspecialchars($record) ?></code><br>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </p>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Failed</span>
                                                <?php if (!empty($result['error'])): ?>
                                                    <p class="small mt-2 text-danger"><?= htmlspecialchars($result['error']) ?></p>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($result['query_time']): ?>
                                                <small class="text-muted"><?= $result['query_time'] ?>ms</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Create Zone Modal -->
<div class="modal fade" id="createZoneModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="dns_action" value="create_zone">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create DNS Zone</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Domain Name</label>
                        <input type="text" name="domain_name" class="form-control" required
                               placeholder="example.com">
                        <small class="form-text text-muted">Enter the domain name without www</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Server IP Address (Optional)</label>
                        <input type="text" name="ip_address" class="form-control"
                               placeholder="192.168.1.100">
                        <small class="form-text text-muted">Will create default A records if provided</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Custom Nameservers (Optional)</label>
                        <input type="text" name="nameservers" class="form-control"
                               placeholder="ns1.example.com, ns2.example.com">
                        <small class="form-text text-muted">Comma-separated list. Leave empty for default Contabo nameservers</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Zone</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create/Edit Record Modal -->
<div class="modal fade" id="recordModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="recordForm">
                <input type="hidden" name="dns_action" id="recordAction" value="create_record">
                <input type="hidden" name="record_id" id="recordId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="recordModalTitle">Add DNS Record</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Record Name</label>
                        <input type="text" name="name" id="recordName" class="form-control" required
                               placeholder="www or @ for root domain">
                    </div>
                    
                    <div class="form-group">
                        <label>Record Type</label>
                        <select name="type" id="recordType" class="form-control" required onchange="updateRecordFields()">
                            <option value="">Select type...</option>
                            <option value="A">A - IPv4 Address</option>
                            <option value="AAAA">AAAA - IPv6 Address</option>
                            <option value="CNAME">CNAME - Canonical Name</option>
                            <option value="MX">MX - Mail Exchange</option>
                            <option value="TXT">TXT - Text Record</option>
                            <option value="NS">NS - Name Server</option>
                            <option value="SRV">SRV - Service Record</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Content</label>
                        <input type="text" name="content" id="recordContent" class="form-control" required
                               placeholder="Record value">
                        <small class="form-text text-muted" id="contentHelp">Enter the value for this record</small>
                    </div>
                    
                    <div class="form-group">
                        <label>TTL (Time To Live)</label>
                        <select name="ttl" id="recordTTL" class="form-control">
                            <option value="300">5 minutes (300)</option>
                            <option value="1800">30 minutes (1800)</option>
                            <option value="3600" selected>1 hour (3600)</option>
                            <option value="14400">4 hours (14400)</option>
                            <option value="86400">1 day (86400)</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="priorityGroup" style="display: none;">
                        <label>Priority</label>
                        <input type="number" name="priority" id="recordPriority" class="form-control" min="0" max="65535">
                        <small class="form-text text-muted">Lower numbers have higher priority</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="recordSubmitBtn">Add Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Zone Modal -->
<div class="modal fade" id="importZoneModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="dns_action" value="import_zone">
                
                <div class="modal-header">
                    <h5 class="modal-title">Import DNS Zone</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Domain Name</label>
                        <input type="text" name="domain_name" class="form-control" required
                               placeholder="example.com">
                    </div>
                    
                    <div class="form-group">
                        <label>Zone File</label>
                        <input type="file" name="zone_file" class="form-control-file" required
                               accept=".zone,.txt,.conf">
                        <small class="form-text text-muted">Upload a standard zone file format</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Zone</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Propagation Check Modal -->
<div class="modal fade" id="propagationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="dns_action" value="check_propagation">
                
                <div class="modal-header">
                    <h5 class="modal-title">Check DNS Propagation</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Domain Name</label>
                        <input type="text" name="domain_name" class="form-control" required
                               placeholder="example.com">
                    </div>
                    
                    <div class="form-group">
                        <label>Record Type</label>
                        <select name="record_type" class="form-control">
                            <option value="A" selected>A - IPv4 Address</option>
                            <option value="AAAA">AAAA - IPv6 Address</option>
                            <option value="CNAME">CNAME - Canonical Name</option>
                            <option value="MX">MX - Mail Exchange</option>
                            <option value="TXT">TXT - Text Record</option>
                            <option value="NS">NS - Name Server</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        This will check DNS propagation across multiple public DNS servers worldwide.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Check Propagation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreateZone() {
    $('#createZoneModal').modal('show');
}

function showCreateRecord() {
    if (!<?= json_encode($zoneId !== null) ?>) {
        alert('Please select a DNS zone first');
        return;
    }
    
    document.getElementById('recordModalTitle').textContent = 'Add DNS Record';
    document.getElementById('recordAction').value = 'create_record';
    document.getElementById('recordSubmitBtn').textContent = 'Add Record';
    document.getElementById('recordForm').reset();
    document.getElementById('recordId').value = '';
    
    $('#recordModal').modal('show');
}

function showImportZone() {
    $('#importZoneModal').modal('show');
}

function showPropagationCheck() {
    $('#propagationModal').modal('show');
}

function editRecord(recordId) {
    // In a real implementation, you would fetch record data via AJAX
    document.getElementById('recordModalTitle').textContent = 'Edit DNS Record';
    document.getElementById('recordAction').value = 'update_record';
    document.getElementById('recordSubmitBtn').textContent = 'Update Record';
    document.getElementById('recordId').value = recordId;
    
    $('#recordModal').modal('show');
}

function deleteRecord(recordId, recordName) {
    if (confirm('Are you sure you want to delete the DNS record "' + recordName + '"?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=dns&dns_action=delete_record&record_id=' + recordId + '&zone_id=<?= $zoneId ?>';
    }
}

function deleteZone(zoneId, domainName) {
    if (confirm('Are you sure you want to delete the DNS zone for "' + domainName + '"?\n\nThis will delete ALL DNS records for this domain and cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="dns_action" value="delete_zone">
            <input type="hidden" name="confirm" value="yes">
        `;
        document.body.appendChild(form);
        form.action = '<?= $vars['modulelink'] ?>&action=dns&zone_id=' + zoneId;
        form.submit();
    }
}

function exportZone(zoneId) {
    window.location.href = '<?= $vars['modulelink'] ?>&action=dns&dns_action=export_zone&zone_id=' + zoneId;
}

function updateRecordFields() {
    const recordType = document.getElementById('recordType').value;
    const contentHelp = document.getElementById('contentHelp');
    const priorityGroup = document.getElementById('priorityGroup');
    
    // Show/hide priority field for MX and SRV records
    if (recordType === 'MX' || recordType === 'SRV') {
        priorityGroup.style.display = 'block';
    } else {
        priorityGroup.style.display = 'none';
    }
    
    // Update help text based on record type
    const helpTexts = {
        'A': 'Enter IPv4 address (e.g., 192.168.1.1)',
        'AAAA': 'Enter IPv6 address (e.g., 2001:db8::1)',
        'CNAME': 'Enter target domain name (e.g., www.example.com)',
        'MX': 'Enter mail server domain name (e.g., mail.example.com)',
        'TXT': 'Enter text content (e.g., verification codes, SPF records)',
        'NS': 'Enter nameserver domain name (e.g., ns1.example.com)',
        'SRV': 'Enter target in format: priority weight port target'
    };
    
    contentHelp.textContent = helpTexts[recordType] || 'Enter the value for this record';
}

<?php 
// PHP function to get badge color for record types
function getRecordTypeBadgeColor($type) {
    $colors = [
        'A' => 'primary',
        'AAAA' => 'primary', 
        'CNAME' => 'success',
        'MX' => 'warning',
        'TXT' => 'info',
        'NS' => 'secondary',
        'SRV' => 'dark',
        'SOA' => 'danger'
    ];
    return $colors[$type] ?? 'secondary';
}
?>
</script>
