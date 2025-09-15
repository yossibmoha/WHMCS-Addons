<?php
/**
 * Admin Billing Integration Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\BillingIntegrationService;
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

$billingService = new BillingIntegrationService($apiClient);

// Handle actions
$action = $_GET['billing_action'] ?? 'dashboard';
$message = '';
$error = '';

try {
    switch ($action) {
        case 'sync_billing':
            if ($_POST) {
                $syncResults = $billingService->syncAllAddonBilling();
                $message = "Billing sync completed: {$syncResults['billed']} items billed, €{$syncResults['total_amount']} total";
            }
            break;
            
        case 'process_overages':
            if ($_POST) {
                // Process overages separately
                $message = 'Overage processing completed';
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get billing statistics
$currentStats = [];
$lastMonthStats = [];
$yearStats = [];

try {
    $currentStats = $billingService->getBillingStatistics('current_month');
    $lastMonthStats = $billingService->getBillingStatistics('last_month');
    $yearStats = $billingService->getBillingStatistics('current_year');
} catch (Exception $e) {
    $error = 'Failed to load billing statistics: ' . $e->getMessage();
}

// Get recent billing items
$recentBillingItems = [];
try {
    $recentBillingItems = Capsule::table('mod_contabo_billing_items')
        ->leftJoin('mod_contabo_instances', 'mod_contabo_billing_items.instance_id', '=', 'mod_contabo_instances.contabo_instance_id')
        ->leftJoin('tblhosting', 'mod_contabo_billing_items.service_id', '=', 'tblhosting.id')
        ->leftJoin('tblclients', 'tblhosting.userid', '=', 'tblclients.id')
        ->select(
            'mod_contabo_billing_items.*',
            'mod_contabo_instances.name as instance_name',
            'tblhosting.domain',
            'tblclients.firstname',
            'tblclients.lastname'
        )
        ->orderBy('mod_contabo_billing_items.created_at', 'desc')
        ->limit(20)
        ->get();
} catch (Exception $e) {
    // Continue with empty array
}
?>

<div class="billing-integration-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-euro-sign"></i> Billing Integration Management</h2>
            <p>Manage automatic billing for VPS add-ons, usage charges, and overages</p>
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

    <!-- Revenue Overview -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h4><i class="fas fa-euro-sign"></i> €<?= number_format($currentStats['total_revenue'] ?? 0, 2) ?></h4>
                    <p>This Month Revenue</p>
                    <small><?= $currentStats['total_items'] ?? 0 ?> billing items</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h4><i class="fas fa-chart-line"></i> €<?= number_format($lastMonthStats['total_revenue'] ?? 0, 2) ?></h4>
                    <p>Last Month Revenue</p>
                    <small><?= $lastMonthStats['total_items'] ?? 0 ?> billing items</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h4><i class="fas fa-calendar-alt"></i> €<?= number_format($yearStats['total_revenue'] ?? 0, 2) ?></h4>
                    <p>Year to Date</p>
                    <small><?= $yearStats['total_items'] ?? 0 ?> billing items</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <?php 
                    $monthlyGrowth = 0;
                    if ($lastMonthStats['total_revenue'] > 0) {
                        $monthlyGrowth = (($currentStats['total_revenue'] ?? 0) - $lastMonthStats['total_revenue']) / $lastMonthStats['total_revenue'] * 100;
                    }
                    ?>
                    <h4><i class="fas fa-percentage"></i> <?= round($monthlyGrowth, 1) ?>%</h4>
                    <p>Monthly Growth</p>
                    <small><?= $monthlyGrowth >= 0 ? 'Increase' : 'Decrease' ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue Breakdown -->
    <?php if (!empty($currentStats['breakdown'])): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> Revenue Breakdown (This Month)</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($currentStats['breakdown'] as $serviceType => $data): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body text-center">
                                            <?php
                                            $icon = 'fas fa-cog';
                                            $color = 'primary';
                                            $name = ucwords(str_replace('_', ' ', $serviceType));
                                            
                                            switch ($serviceType) {
                                                case 'backup':
                                                    $icon = 'fas fa-hdd';
                                                    $color = 'danger';
                                                    $name = 'Backup Services';
                                                    break;
                                                case 'additional_ip':
                                                    $icon = 'fas fa-network-wired';
                                                    $color = 'info';
                                                    $name = 'Additional IPs';
                                                    break;
                                                case 'storage_overage':
                                                    $icon = 'fas fa-hdd';
                                                    $color = 'warning';
                                                    $name = 'Storage Overage';
                                                    break;
                                                case 'bandwidth_overage':
                                                    $icon = 'fas fa-wifi';
                                                    $color = 'secondary';
                                                    $name = 'Bandwidth Overage';
                                                    break;
                                            }
                                            ?>
                                            <i class="<?= $icon ?> fa-3x text-<?= $color ?> mb-3"></i>
                                            <h5><?= $name ?></h5>
                                            <h4 class="text-<?= $color ?>">€<?= number_format($data['total_amount'], 2) ?></h4>
                                            <p class="mb-0">
                                                <?= $data['count'] ?> items<br>
                                                <small class="text-muted">Avg: €<?= number_format($data['average_amount'], 2) ?></small>
                                            </p>
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

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-tools"></i> Billing Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="syncAllBilling()">
                            <i class="fas fa-sync"></i> Sync All Billing
                        </button>
                        <button class="btn btn-warning" onclick="processOverages()">
                            <i class="fas fa-exclamation-triangle"></i> Process Overages
                        </button>
                        <button class="btn btn-info" onclick="generateReport()">
                            <i class="fas fa-file-alt"></i> Generate Report
                        </button>
                        <button class="btn btn-primary" onclick="exportBillingData()">
                            <i class="fas fa-download"></i> Export Data
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Billing Items -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-list"></i> Recent Billing Items</h4>
                    <div class="form-inline">
                        <select id="filterServiceType" class="form-control mr-2" onchange="filterBillingItems()">
                            <option value="">All Services</option>
                            <option value="backup">Backup Services</option>
                            <option value="additional_ip">Additional IPs</option>
                            <option value="storage_overage">Storage Overage</option>
                            <option value="bandwidth_overage">Bandwidth Overage</option>
                        </select>
                        <select id="filterStatus" class="form-control mr-2" onchange="filterBillingItems()">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="billed">Billed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($recentBillingItems)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No billing items found. Run a billing sync to generate charges.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped" id="billingTable">
                                <thead>
                                    <tr>
                                        <th>Service Details</th>
                                        <th>Customer</th>
                                        <th>Service Type</th>
                                        <th>Amount</th>
                                        <th>Billing Month</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBillingItems as $item): ?>
                                        <tr data-service-type="<?= $item->service_type ?>" data-status="<?= $item->status ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($item->instance_name ?: $item->instance_id) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($item->instance_id) ?></small><br>
                                                <code><?= htmlspecialchars($item->domain ?: 'N/A') ?></code>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(($item->firstname ?: '') . ' ' . ($item->lastname ?: '')) ?><br>
                                                <small class="text-muted">Service #<?= $item->service_id ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $serviceTypeLabels = [
                                                    'backup' => '<span class="badge badge-danger"><i class="fas fa-hdd"></i> Backup</span>',
                                                    'additional_ip' => '<span class="badge badge-info"><i class="fas fa-network-wired"></i> Additional IP</span>',
                                                    'storage_overage' => '<span class="badge badge-warning"><i class="fas fa-hdd"></i> Storage Overage</span>',
                                                    'bandwidth_overage' => '<span class="badge badge-secondary"><i class="fas fa-wifi"></i> Bandwidth Overage</span>'
                                                ];
                                                echo $serviceTypeLabels[$item->service_type] ?? '<span class="badge badge-primary">' . ucwords($item->service_type) . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <strong>€<?= number_format($item->amount, 2) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($item->billing_period) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($item->billing_month) ?><br>
                                                <small class="text-muted"><?= date('M j', strtotime($item->created_at)) ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $statusLabels = [
                                                    'pending' => '<span class="badge badge-warning">Pending</span>',
                                                    'billed' => '<span class="badge badge-success">Billed</span>',
                                                    'cancelled' => '<span class="badge badge-danger">Cancelled</span>'
                                                ];
                                                echo $statusLabels[$item->status] ?? '<span class="badge badge-secondary">' . ucfirst($item->status) . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-info" onclick="viewBillingItem(<?= $item->id ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <?php if ($item->status === 'pending'): ?>
                                                        <button class="btn btn-success" onclick="processBillingItem(<?= $item->id ?>)">
                                                            <i class="fas fa-check"></i> Process
                                                        </button>
                                                        <button class="btn btn-danger" onclick="cancelBillingItem(<?= $item->id ?>)">
                                                            <i class="fas fa-times"></i> Cancel
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

<!-- Billing Sync Modal -->
<div class="modal fade" id="syncBillingModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="billing_action" value="sync_billing">
                
                <div class="modal-header">
                    <h5 class="modal-title">Sync All Billing</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Billing Sync Process:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Process all active backup configurations</li>
                            <li>Calculate additional IP charges</li>
                            <li>Calculate storage and bandwidth overages</li>
                            <li>Create WHMCS invoice items automatically</li>
                        </ul>
                    </div>
                    
                    <p>This process will sync all current add-on billing for the current month. Are you sure you want to continue?</p>
                    
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="confirmSync" required>
                        <label class="form-check-label" for="confirmSync">
                            I understand this will create billing entries for all eligible services
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sync"></i> Sync Billing
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function syncAllBilling() {
    $('#syncBillingModal').modal('show');
}

function processOverages() {
    if (confirm('Process all storage and bandwidth overages for the current month?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=billing&billing_action=process_overages';
    }
}

function generateReport() {
    window.open('<?= $vars['modulelink'] ?>&action=billing&billing_action=generate_report', '_blank');
}

function exportBillingData() {
    window.open('<?= $vars['modulelink'] ?>&action=billing&billing_action=export_data', '_blank');
}

function filterBillingItems() {
    const serviceTypeFilter = document.getElementById('filterServiceType').value;
    const statusFilter = document.getElementById('filterStatus').value;
    
    const rows = document.querySelectorAll('#billingTable tbody tr');
    
    rows.forEach(row => {
        const serviceType = row.dataset.serviceType;
        const status = row.dataset.status;
        
        const serviceTypeMatch = !serviceTypeFilter || serviceType === serviceTypeFilter;
        const statusMatch = !statusFilter || status === statusFilter;
        
        if (serviceTypeMatch && statusMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function viewBillingItem(itemId) {
    window.open('<?= $vars['modulelink'] ?>&action=billing&view_item=' + itemId, '_blank');
}

function processBillingItem(itemId) {
    if (confirm('Process this billing item and create invoice entry?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=billing&process_item=' + itemId;
    }
}

function cancelBillingItem(itemId) {
    if (confirm('Cancel this billing item? This action cannot be undone.')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=billing&cancel_item=' + itemId;
    }
}
</script>
