<?php
/**
 * Load Balancer Management Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\LoadBalancerService;
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

$loadBalancerService = new LoadBalancerService($apiClient);

// Handle actions
$action = $_GET['lb_action'] ?? 'dashboard';
$loadBalancerId = $_GET['lb_id'] ?? null;
$serverId = $_GET['server_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'create':
            if ($_POST) {
                $result = $loadBalancerService->createLoadBalancer([
                    'name' => $_POST['name'],
                    'description' => $_POST['description'],
                    'algorithm' => $_POST['algorithm'],
                    'protocol' => $_POST['protocol'],
                    'frontend_port' => $_POST['frontend_port'],
                    'backend_port' => $_POST['backend_port'],
                    'ssl_certificate_id' => $_POST['ssl_certificate_id'] ?: null,
                    'session_persistence' => isset($_POST['session_persistence']),
                    'health_check_enabled' => isset($_POST['health_check_enabled']),
                    'health_check_path' => $_POST['health_check_path'],
                    'health_check_interval' => $_POST['health_check_interval'],
                    'health_check_timeout' => $_POST['health_check_timeout'],
                    'health_check_retries' => $_POST['health_check_retries'],
                    'created_by' => 'admin'
                ]);
                $message = 'Load balancer created successfully - IP: ' . $result['public_ip'];
            }
            break;
            
        case 'update':
            if ($_POST && $loadBalancerId) {
                $result = $loadBalancerService->updateLoadBalancer($loadBalancerId, [
                    'name' => $_POST['name'],
                    'description' => $_POST['description'],
                    'algorithm' => $_POST['algorithm'],
                    'protocol' => $_POST['protocol'],
                    'frontend_port' => $_POST['frontend_port'],
                    'backend_port' => $_POST['backend_port'],
                    'ssl_certificate_id' => $_POST['ssl_certificate_id'] ?: null,
                    'session_persistence' => isset($_POST['session_persistence']),
                    'health_check_enabled' => isset($_POST['health_check_enabled']),
                    'health_check_path' => $_POST['health_check_path'],
                    'health_check_interval' => $_POST['health_check_interval'],
                    'health_check_timeout' => $_POST['health_check_timeout'],
                    'health_check_retries' => $_POST['health_check_retries'],
                    'is_active' => isset($_POST['is_active'])
                ]);
                $message = 'Load balancer updated successfully';
            }
            break;
            
        case 'delete':
            if ($loadBalancerId && $_POST['confirm'] === 'yes') {
                $result = $loadBalancerService->deleteLoadBalancer($loadBalancerId);
                $message = 'Load balancer deleted successfully';
                $loadBalancerId = null; // Reset selection
            }
            break;
            
        case 'add_server':
            if ($_POST && $loadBalancerId) {
                $result = $loadBalancerService->addServerToLoadBalancer($loadBalancerId, $_POST['instance_id'], [
                    'weight' => $_POST['weight'] ?? 100,
                    'is_active' => isset($_POST['is_active'])
                ]);
                $message = 'Server added to load balancer successfully';
            }
            break;
            
        case 'remove_server':
            if ($serverId && $loadBalancerId) {
                $result = $loadBalancerService->removeServerFromLoadBalancer($loadBalancerId, $serverId);
                $message = 'Server removed from load balancer successfully';
            }
            break;
            
        case 'health_check':
            if ($_POST) {
                $results = $loadBalancerService->performHealthChecks();
                $message = "Health checks completed: {$results['servers_checked']} servers checked, {$results['healthy_servers']} healthy";
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get load balancer statistics
$stats = [];
try {
    $stats = $loadBalancerService->getLoadBalancerStatistics();
} catch (Exception $e) {
    $error = 'Failed to load statistics: ' . $e->getMessage();
}

// Get all load balancers
$loadBalancers = [];
try {
    $loadBalancers = $loadBalancerService->getLoadBalancers();
} catch (Exception $e) {
    // Continue with empty array
}

// Get load balancer details if selected
$loadBalancerDetails = [];
$healthStatus = [];
if ($loadBalancerId) {
    try {
        $loadBalancerDetails = $loadBalancerService->getLoadBalancerDetails($loadBalancerId);
        $healthStatus = $loadBalancerService->getLoadBalancerHealthStatus($loadBalancerId);
    } catch (Exception $e) {
        $error = 'Failed to load load balancer details: ' . $e->getMessage();
    }
}

// Get available instances
$availableInstances = [];
if ($loadBalancerId) {
    try {
        $availableInstances = $loadBalancerService->getAvailableInstances($loadBalancerId);
    } catch (Exception $e) {
        // Continue with empty array
    }
}
?>

<div class="load-balancer-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-balance-scale"></i> Load Balancer Management</h2>
            <p>High availability and traffic distribution for your VPS servers</p>
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

    <!-- Load Balancer Statistics -->
    <?php if (!empty($stats)): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-balance-scale"></i> <?= $stats['total_load_balancers'] ?? 0 ?></h4>
                        <p>Load Balancers</p>
                        <small><?= $stats['active_load_balancers'] ?? 0 ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-server"></i> <?= $stats['total_backend_servers'] ?? 0 ?></h4>
                        <p>Backend Servers</p>
                        <small><?= $stats['healthy_servers'] ?? 0 ?> healthy</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-heartbeat"></i> <?= $stats['health_check_success_rate'] ?? 0 ?>%</h4>
                        <p>Health Check Success</p>
                        <small>Last 24 hours</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-shield-alt"></i> HA</h4>
                        <p>High Availability</p>
                        <small>Traffic distribution</small>
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
                    <h4><i class="fas fa-bolt"></i> Load Balancer Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="showCreateLoadBalancer()">
                            <i class="fas fa-plus"></i> Create Load Balancer
                        </button>
                        <button class="btn btn-success" onclick="showAddServer()" <?= !$loadBalancerId ? 'disabled' : '' ?>>
                            <i class="fas fa-plus-circle"></i> Add Server
                        </button>
                        <button class="btn btn-warning" onclick="runHealthChecks()">
                            <i class="fas fa-heartbeat"></i> Health Check All
                        </button>
                        <button class="btn btn-info" onclick="viewHealthStatus()" <?= !$loadBalancerId ? 'disabled' : '' ?>>
                            <i class="fas fa-chart-line"></i> Health Status
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Load Balancer Selection -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-list"></i> Load Balancers</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($loadBalancers)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No load balancers configured. Create your first load balancer to get started.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Public IP</th>
                                        <th>Algorithm</th>
                                        <th>Protocol</th>
                                        <th>Servers</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loadBalancers as $lb): ?>
                                        <tr class="<?= $loadBalancerId == $lb['id'] ? 'table-active' : '' ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($lb['name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($lb['description']) ?></small>
                                            </td>
                                            <td>
                                                <code><?= htmlspecialchars($lb['public_ip']) ?></code><br>
                                                <small class="text-muted">:<?= $lb['frontend_port'] ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary"><?= ucwords(str_replace('_', ' ', $lb['algorithm'])) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?= strtoupper($lb['protocol']) ?></span><br>
                                                <small class="text-muted"><?= $lb['frontend_port'] ?>→<?= $lb['backend_port'] ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary"><?= $lb['server_count'] ?> servers</span>
                                            </td>
                                            <td>
                                                <?php if ($lb['is_active']): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                                <?php if ($lb['health_check_enabled']): ?>
                                                    <br><span class="badge badge-info">Health Check</span>
                                                <?php endif; ?>
                                                <?php if ($lb['session_persistence']): ?>
                                                    <br><span class="badge badge-warning">Sticky</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= $vars['modulelink'] ?>&action=load_balancer&lb_id=<?= $lb['id'] ?>" 
                                                       class="btn btn-info <?= $loadBalancerId == $lb['id'] ? 'active' : '' ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button class="btn btn-warning" onclick="editLoadBalancer(<?= $lb['id'] ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger" onclick="deleteLoadBalancer(<?= $lb['id'] ?>, '<?= htmlspecialchars($lb['name']) ?>')">
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

        <!-- Algorithm Distribution -->
        <div class="col-md-4">
            <?php if (!empty($stats['algorithm_distribution'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> Algorithms</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($stats['algorithm_distribution'] as $algorithm => $count): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><strong><?= ucwords(str_replace('_', ' ', $algorithm)) ?></strong></span>
                                <span class="badge badge-primary"><?= $count ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($stats['protocol_distribution'])): ?>
                    <div class="card mt-3">
                        <div class="card-header">
                            <h4><i class="fas fa-network-wired"></i> Protocols</h4>
                        </div>
                        <div class="card-body">
                            <?php foreach ($stats['protocol_distribution'] as $protocol => $count): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><strong><?= strtoupper($protocol) ?></strong></span>
                                    <span class="badge badge-info"><?= $count ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Load Balancer Details -->
    <?php if ($loadBalancerId && !empty($loadBalancerDetails)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4><i class="fas fa-info-circle"></i> <?= htmlspecialchars($loadBalancerDetails['load_balancer']['name']) ?> Details</h4>
                        <div class="btn-group">
                            <button class="btn btn-primary btn-sm" onclick="showAddServer()">
                                <i class="fas fa-plus"></i> Add Server
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="editLoadBalancer(<?= $loadBalancerId ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th>Public IP:</th>
                                        <td><code><?= htmlspecialchars($loadBalancerDetails['load_balancer']['public_ip']) ?></code></td>
                                    </tr>
                                    <tr>
                                        <th>Algorithm:</th>
                                        <td><?= ucwords(str_replace('_', ' ', $loadBalancerDetails['load_balancer']['algorithm'])) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Protocol:</th>
                                        <td><?= strtoupper($loadBalancerDetails['load_balancer']['protocol']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Ports:</th>
                                        <td><?= $loadBalancerDetails['load_balancer']['frontend_port'] ?> → <?= $loadBalancerDetails['load_balancer']['backend_port'] ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th>Health Checks:</th>
                                        <td><?= $loadBalancerDetails['load_balancer']['health_check_enabled'] ? 'Enabled' : 'Disabled' ?></td>
                                    </tr>
                                    <?php if ($loadBalancerDetails['load_balancer']['health_check_enabled']): ?>
                                        <tr>
                                            <th>Check Path:</th>
                                            <td><?= htmlspecialchars($loadBalancerDetails['load_balancer']['health_check_path']) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Check Interval:</th>
                                            <td><?= $loadBalancerDetails['load_balancer']['health_check_interval'] ?>s</td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Session Persistence:</th>
                                        <td><?= $loadBalancerDetails['load_balancer']['session_persistence'] ? 'Enabled' : 'Disabled' ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backend Servers -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-server"></i> Backend Servers 
                            <?php if (!empty($healthStatus)): ?>
                                <span class="badge badge-<?= $healthStatus['overall_health'] === 'healthy' ? 'success' : ($healthStatus['overall_health'] === 'degraded' ? 'warning' : 'danger') ?>">
                                    <?= ucfirst($healthStatus['overall_health']) ?> (<?= $healthStatus['health_percentage'] ?>%)
                                </span>
                            <?php endif; ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (empty($loadBalancerDetails['servers'])): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No servers added to this load balancer. Add servers to enable load balancing.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Server</th>
                                            <th>IPs</th>
                                            <th>Weight</th>
                                            <th>Health Status</th>
                                            <th>Last Check</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($loadBalancerDetails['servers'] as $server): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($server['instance_name']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($server['instance_id']) ?></small>
                                                </td>
                                                <td>
                                                    <strong>Private:</strong> <code><?= htmlspecialchars($server['private_ip']) ?></code><br>
                                                    <strong>Public:</strong> <code><?= htmlspecialchars($server['public_ip']) ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge badge-secondary"><?= $server['weight'] ?></span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $healthBadge = [
                                                        'healthy' => 'success',
                                                        'unhealthy' => 'danger',
                                                        'unknown' => 'secondary'
                                                    ];
                                                    $badgeColor = $healthBadge[$server['health_status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge badge-<?= $badgeColor ?>">
                                                        <?= ucfirst($server['health_status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= $server['last_health_check'] ? date('M j, H:i', strtotime($server['last_health_check'])) : 'Never' ?>
                                                </td>
                                                <td>
                                                    <?php if ($server['is_active']): ?>
                                                        <span class="badge badge-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-info" onclick="testServerHealth(<?= $server['id'] ?>)" title="Test Health">
                                                            <i class="fas fa-heartbeat"></i>
                                                        </button>
                                                        <button class="btn btn-warning" onclick="editServerConfig(<?= $server['id'] ?>)" title="Edit Config">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger" onclick="removeServer(<?= $server['id'] ?>, '<?= htmlspecialchars($server['instance_name']) ?>')" title="Remove">
                                                            <i class="fas fa-times"></i>
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
</div>

<!-- Create Load Balancer Modal -->
<div class="modal fade" id="createLoadBalancerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="lb_action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create Load Balancer</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Load balancers distribute traffic across multiple servers for high availability and performance.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Name</label>
                                <input type="text" name="name" class="form-control" required
                                       placeholder="e.g., Web App Load Balancer">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Description (Optional)</label>
                                <input type="text" name="description" class="form-control"
                                       placeholder="Brief description">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Load Balancing Algorithm</label>
                                <select name="algorithm" class="form-control" required>
                                    <option value="round_robin">Round Robin</option>
                                    <option value="least_connections">Least Connections</option>
                                    <option value="ip_hash">IP Hash (Session Affinity)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Protocol</label>
                                <select name="protocol" class="form-control" required>
                                    <option value="http">HTTP</option>
                                    <option value="https">HTTPS</option>
                                    <option value="tcp">TCP</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Frontend Port (Client-facing)</label>
                                <input type="number" name="frontend_port" class="form-control" required
                                       min="1" max="65535" value="80">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Backend Port (Server-facing)</label>
                                <input type="number" name="backend_port" class="form-control" required
                                       min="1" max="65535" value="80">
                            </div>
                        </div>
                    </div>
                    
                    <h6>Health Check Configuration</h6>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="health_check_enabled" 
                               id="health_check_enabled" checked>
                        <label class="form-check-label" for="health_check_enabled">
                            Enable Health Checks
                        </label>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Health Check Path</label>
                                <input type="text" name="health_check_path" class="form-control" 
                                       value="/" placeholder="/">
                                <small class="form-text text-muted">For HTTP/HTTPS only</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Check Interval (seconds)</label>
                                <input type="number" name="health_check_interval" class="form-control" 
                                       value="30" min="10" max="300">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Timeout (seconds)</label>
                                <input type="number" name="health_check_timeout" class="form-control" 
                                       value="5" min="1" max="60">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Retries</label>
                                <input type="number" name="health_check_retries" class="form-control" 
                                       value="3" min="1" max="10">
                            </div>
                        </div>
                    </div>
                    
                    <h6>Advanced Options</h6>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="session_persistence" 
                               id="session_persistence">
                        <label class="form-check-label" for="session_persistence">
                            Enable Session Persistence (Sticky Sessions)
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label>SSL Certificate ID (Optional)</label>
                        <input type="number" name="ssl_certificate_id" class="form-control"
                               placeholder="For HTTPS load balancers">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Load Balancer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Server Modal -->
<div class="modal fade" id="addServerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="lb_action" value="add_server">
                
                <div class="modal-header">
                    <h5 class="modal-title">Add Server to Load Balancer</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Server</label>
                        <select name="instance_id" class="form-control" required>
                            <option value="">Select server...</option>
                            <?php foreach ($availableInstances as $instance): ?>
                                <option value="<?= $instance['instance_id'] ?>">
                                    <?= htmlspecialchars($instance['name']) ?>
                                    (<?= htmlspecialchars($instance['public_ip']) ?>)
                                    <?= $instance['client_name'] ? ' - ' . htmlspecialchars($instance['client_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Weight</label>
                        <input type="number" name="weight" class="form-control" value="100" 
                               min="1" max="1000">
                        <small class="form-text text-muted">
                            Higher weight = more traffic. Default is 100.
                        </small>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" 
                               id="server_is_active" checked>
                        <label class="form-check-label" for="server_is_active">
                            Server is active
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Server</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreateLoadBalancer() {
    $('#createLoadBalancerModal').modal('show');
}

function showAddServer() {
    if (!<?= json_encode($loadBalancerId !== null) ?>) {
        alert('Please select a load balancer first');
        return;
    }
    
    <?php if (empty($availableInstances)): ?>
        alert('No available servers to add. All running servers are already in this load balancer or no servers exist.');
        return;
    <?php endif; ?>
    
    $('#addServerModal').modal('show');
}

function runHealthChecks() {
    if (confirm('Run health checks on all load balancers? This may take a few minutes.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="lb_action" value="health_check">';
        document.body.appendChild(form);
        form.submit();
    }
}

function viewHealthStatus() {
    if (!<?= json_encode($loadBalancerId !== null) ?>) {
        alert('Please select a load balancer first');
        return;
    }
    
    // Scroll to health status section
    if (document.querySelector('.load-balancer-management .card:last-child')) {
        document.querySelector('.load-balancer-management .card:last-child').scrollIntoView({ behavior: 'smooth' });
    }
}

function editLoadBalancer(loadBalancerId) {
    // In real implementation, would populate form with existing data
    alert('Edit load balancer functionality - would load existing configuration');
}

function deleteLoadBalancer(loadBalancerId, name) {
    if (confirm('Are you sure you want to delete the load balancer "' + name + '"?\n\nThis will remove all servers and cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="lb_action" value="delete">
            <input type="hidden" name="confirm" value="yes">
        `;
        document.body.appendChild(form);
        form.action = '<?= $vars['modulelink'] ?>&action=load_balancer&lb_id=' + loadBalancerId;
        form.submit();
    }
}

function testServerHealth(serverId) {
    alert('Testing server health - feature would perform immediate health check');
}

function editServerConfig(serverId) {
    alert('Edit server configuration - would allow changing weight, status, etc.');
}

function removeServer(serverId, serverName) {
    if (confirm('Remove server "' + serverName + '" from this load balancer?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=load_balancer&lb_action=remove_server&lb_id=<?= $loadBalancerId ?>&server_id=' + serverId;
    }
}
</script>
