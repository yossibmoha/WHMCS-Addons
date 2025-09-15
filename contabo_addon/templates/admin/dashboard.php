<?php
/**
 * Admin Dashboard Template
 */

use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;
use ContaboAddon\API\ContaboAPIClient;
use WHMCS\Database\Capsule;

// Initialize components
$config = ConfigHelper::getConfig();
$logHelper = new LogHelper();

// Get statistics
$stats = [
    'total_instances' => Capsule::table('mod_contabo_instances')->count(),
    'active_instances' => Capsule::table('mod_contabo_instances')->where('status', 'running')->count(),
    'total_storages' => Capsule::table('mod_contabo_object_storages')->count(),
    'active_storages' => Capsule::table('mod_contabo_object_storages')->where('status', 'ready')->count(),
    'total_networks' => Capsule::table('mod_contabo_private_networks')->count(),
    'total_vips' => Capsule::table('mod_contabo_vips')->count(),
    'total_images' => Capsule::table('mod_contabo_images')->count(),
    'total_backups' => Capsule::table('mod_contabo_backups')->count(),
    'active_backups' => Capsule::table('mod_contabo_backups')->where('status', 'active')->count(),
    'monitoring_alerts' => Capsule::table('mod_contabo_monitoring_alerts')->where('is_active', 1)->count(),
    'alert_triggers_24h' => Capsule::table('mod_contabo_alert_history')
        ->where('triggered_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
        ->count(),
    'online_servers' => Capsule::table('mod_contabo_server_metrics')
        ->where('timestamp', '>=', date('Y-m-d H:i:s', strtotime('-15 minutes')))
        ->where('is_online', 1)
        ->distinct('instance_id')
        ->count(),
    'dns_zones' => Capsule::table('mod_contabo_dns_zones')->count(),
    'dns_records' => Capsule::table('mod_contabo_dns_records')->count(),
    'scaling_policies' => Capsule::table('mod_contabo_scaling_policies')->count(),
    'active_scaling_policies' => Capsule::table('mod_contabo_scaling_policies')->where('is_active', 1)->count(),
    'support_rules' => Capsule::table('mod_contabo_support_rules')->count(),
    'active_support_rules' => Capsule::table('mod_contabo_support_rules')->where('is_active', 1)->count(),
    'tickets_created_today' => Capsule::table('mod_contabo_support_history')
        ->where('created_at', '>=', date('Y-m-d 00:00:00'))
        ->count(),
    'load_balancers' => Capsule::table('mod_contabo_load_balancers')->count(),
    'active_load_balancers' => Capsule::table('mod_contabo_load_balancers')->where('is_active', 1)->count(),
    'backend_servers' => Capsule::table('mod_contabo_load_balancer_servers')->count(),
    'system_incidents' => Capsule::table('mod_contabo_incidents')->where('status', '!=', 'resolved')->count(),
    'planned_maintenance' => Capsule::table('mod_contabo_maintenance')->where('status', '!=', 'completed')->count()
];

// Get recent logs
$recentLogs = $logHelper->getRecentLogs(10);
$errorLogs = $logHelper->getErrorLogs(5);

// Test API connection
$apiClient = new ContaboAPIClient(ConfigHelper::getApiCredentials());
$connectionTest = $apiClient->testConnection();

?>

<div class="contabo-dashboard">
    <div class="row">
        <div class="col-md-12">
            <h1><i class="fas fa-tachometer-alt"></i> Contabo Management Dashboard</h1>
            <p class="text-muted">Comprehensive management of your Contabo infrastructure</p>
        </div>
    </div>

    <!-- Connection Status -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-plug"></i> API Connection Status</h5>
                </div>
                <div class="card-body">
                    <?php if ($connectionTest['success']): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> API connection is working properly
                            <small class="text-muted"><?= htmlspecialchars($connectionTest['message']) ?></small>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> API connection failed
                            <small class="text-muted"><?= htmlspecialchars($connectionTest['message']) ?></small>
                        </div>
                        <a href="<?= $modulelink ?>&action=settings" class="btn btn-primary">
                            <i class="fas fa-cog"></i> Check Configuration
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Health Alert -->
    <?php if ($stats['system_incidents'] > 0 || $stats['planned_maintenance'] > 0): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-<?= $stats['system_incidents'] > 0 ? 'warning' : 'info' ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="alert-heading mb-1">
                                <i class="fas fa-<?= $stats['system_incidents'] > 0 ? 'exclamation-triangle' : 'info-circle' ?>"></i>
                                System Status Update
                            </h5>
                            <p class="mb-0">
                                <?php if ($stats['system_incidents'] > 0): ?>
                                    <?= $stats['system_incidents'] ?> active incident(s) requiring attention
                                <?php endif; ?>
                                <?php if ($stats['planned_maintenance'] > 0): ?>
                                    <?= $stats['planned_maintenance'] ?> planned maintenance window(s) scheduled
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="<?= $modulelink ?>&action=system_health" class="btn btn-<?= $stats['system_incidents'] > 0 ? 'warning' : 'info' ?>">
                            <i class="fas fa-heartbeat"></i> View Status
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['total_instances'] ?></h4>
                            <p>Total Instances</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-server fa-2x"></i>
                        </div>
                    </div>
                    <small><?= $stats['active_instances'] ?> active</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=instances" class="text-white">
                        <small>View Details <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['total_storages'] ?></h4>
                            <p>Object Storages</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-database fa-2x"></i>
                        </div>
                    </div>
                    <small><?= $stats['active_storages'] ?> ready</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=object-storage" class="text-white">
                        <small>View Details <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['total_networks'] ?></h4>
                            <p>Private Networks</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-network-wired fa-2x"></i>
                        </div>
                    </div>
                    <small><?= $stats['total_vips'] ?> VIP addresses</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=networks" class="text-white">
                        <small>View Details <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['total_images'] ?></h4>
                            <p>Custom Images</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-compact-disc fa-2x"></i>
                        </div>
                    </div>
                    <small>Custom OS images</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=images" class="text-white">
                        <small>View Details <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- New Features Row -->
    <div class="row mt-3">
        <div class="col-md-3">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['total_backups'] ?></h4>
                            <p>Backup Configs</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-hdd fa-2x"></i>
                        </div>
                    </div>
                    <small><?= $stats['active_backups'] ?> active</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=backups" class="text-white">
                        <small>Manage Backups <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4>VNC</h4>
                            <p>Remote Access</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-desktop fa-2x"></i>
                        </div>
                    </div>
                    <small>Console access</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=vnc" class="text-white">
                        <small>Manage VNC <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-secondary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4>Apps</h4>
                            <p>One-Click Deploy</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-rocket fa-2x"></i>
                        </div>
                    </div>
                    <small>Easy installations</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=applications" class="text-white">
                        <small>Browse Apps <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-purple" style="background-color: #6f42c1!important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4>Add-ons</h4>
                            <p>Extra Features</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-puzzle-piece fa-2x"></i>
                        </div>
                    </div>
                    <small>Enhance instances</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=addons" class="text-white">
                        <small>Manage Add-ons <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Monitoring & Security Row -->
    <div class="row mt-3">
        <div class="col-md-3">
            <div class="card text-white" style="background-color: #17a2b8!important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['online_servers'] ?></h4>
                            <p>Online Servers</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-chart-line fa-2x"></i>
                        </div>
                    </div>
                    <small>of <?= $stats['total_instances'] ?> monitored</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=monitoring" class="text-white">
                        <small>View Monitoring <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['monitoring_alerts'] ?></h4>
                            <p>Active Alerts</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-bell fa-2x"></i>
                        </div>
                    </div>
                    <small><?= $stats['alert_triggers_24h'] ?> triggered (24h)</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=monitoring" class="text-white">
                        <small>Manage Alerts <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white" style="background-color: #007bff!important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4>Firewall</h4>
                            <p>Security Rules</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-shield-alt fa-2x"></i>
                        </div>
                    </div>
                    <small>Protection & Rules</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=firewall" class="text-white">
                        <small>Manage Security <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white" style="background-color: #28a745!important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['load_balancers'] ?></h4>
                            <p>Load Balancers</p>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-balance-scale fa-2x"></i>
                        </div>
                    </div>
                    <small><?= $stats['backend_servers'] ?> backend servers</small>
                </div>
                <div class="card-footer">
                    <a href="<?= $modulelink ?>&action=load_balancer" class="text-white">
                        <small>High Availability <i class="fas fa-arrow-right"></i></small>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=instances&create=1" class="btn btn-primary btn-block">
                                <i class="fas fa-plus"></i><br>
                                <small>Create Instance</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=object-storage&create=1" class="btn btn-success btn-block">
                                <i class="fas fa-plus"></i><br>
                                <small>Create Storage</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=networks&create=1" class="btn btn-info btn-block">
                                <i class="fas fa-plus"></i><br>
                                <small>Create Network</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=images&upload=1" class="btn btn-warning btn-block">
                                <i class="fas fa-upload"></i><br>
                                <small>Upload Image</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=cloud-init" class="btn btn-secondary btn-block">
                                <i class="fas fa-cloud"></i><br>
                                <small>Cloud-Init</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=settings" class="btn btn-dark btn-block">
                                <i class="fas fa-cog"></i><br>
                                <small>Settings</small>
                            </a>
                        </div>
                    </div>
                    
                    <!-- New Features Row -->
                    <div class="row mt-3">
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=backups" class="btn btn-danger btn-block">
                                <i class="fas fa-hdd"></i><br>
                                <small>Manage Backups</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=vnc" class="btn btn-dark btn-block">
                                <i class="fas fa-desktop"></i><br>
                                <small>VNC Access</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=applications" class="btn btn-secondary btn-block">
                                <i class="fas fa-rocket"></i><br>
                                <small>Deploy Apps</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=addons" class="btn btn-purple btn-block" style="background-color: #6f42c1; border-color: #6f42c1;">
                                <i class="fas fa-puzzle-piece"></i><br>
                                <small>Instance Add-ons</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=instances&filter=backup_needed" class="btn btn-outline-danger btn-block">
                                <i class="fas fa-shield-alt"></i><br>
                                <small>Enable Backups</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=logs&filter=backup" class="btn btn-outline-secondary btn-block">
                                <i class="fas fa-file-alt"></i><br>
                                <small>Backup Logs</small>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Admin Features Row -->
                    <div class="row mt-3">
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=server-management" class="btn btn-info btn-block">
                                <i class="fas fa-server"></i><br>
                                <small>Server Management</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=secrets" class="btn btn-warning btn-block">
                                <i class="fas fa-key"></i><br>
                                <small>Secret Management</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=rebuild" class="btn btn-danger btn-block">
                                <i class="fas fa-hammer"></i><br>
                                <small>Server Rebuild</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=billing" class="btn btn-success btn-block">
                                <i class="fas fa-euro-sign"></i><br>
                                <small>Billing Integration</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=firewall" class="btn btn-primary btn-block">
                                <i class="fas fa-shield-alt"></i><br>
                                <small>Firewall Management</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=monitoring" class="btn btn-info btn-block">
                                <i class="fas fa-chart-line"></i><br>
                                <small>Server Monitoring</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=dns" class="btn btn-secondary btn-block">
                                <i class="fas fa-globe"></i><br>
                                <small>DNS Management</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=scaling" class="btn btn-success btn-block">
                                <i class="fas fa-expand-arrows-alt"></i><br>
                                <small>Auto-scaling</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=support" class="btn btn-danger btn-block">
                                <i class="fas fa-life-ring"></i><br>
                                <small>Support Integration</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=load_balancer" class="btn btn-success btn-block">
                                <i class="fas fa-balance-scale"></i><br>
                                <small>Load Balancers</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=instances&filter=untracked" class="btn btn-outline-info btn-block">
                                <i class="fas fa-unlink"></i><br>
                                <small>Untracked Servers</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=logs&filter=api_errors" class="btn btn-outline-danger btn-block">
                                <i class="fas fa-bug"></i><br>
                                <small>API Errors</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="https://my.contabo.com/" target="_blank" class="btn btn-outline-primary btn-block">
                                <i class="fas fa-external-link-alt"></i><br>
                                <small>Contabo Portal</small>
                            </a>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= $modulelink ?>&action=system_health" class="btn btn-<?= $stats['system_incidents'] > 0 ? 'warning' : 'outline-success' ?> btn-block">
                                <i class="fas fa-heartbeat"></i><br>
                                <small>System Health</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="row mt-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-history"></i> Recent Activity</h5>
                    <a href="<?= $modulelink ?>&action=logs" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentLogs)): ?>
                        <p class="text-muted">No recent activity</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Action</th>
                                        <th>Status</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentLogs as $log): ?>
                                        <tr>
                                            <td><?= date('M j, H:i', strtotime($log->created_at)) ?></td>
                                            <td><?= htmlspecialchars($log->action) ?></td>
                                            <td>
                                                <?php if ($log->response_code >= 200 && $log->response_code < 300): ?>
                                                    <span class="badge badge-success">Success</span>
                                                <?php elseif ($log->response_code >= 400): ?>
                                                    <span class="badge badge-danger">Error</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Info</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $log->user_id ? "User #{$log->user_id}" : 'System' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-exclamation-triangle"></i> Recent Errors</h5>
                    <?php if (!empty($errorLogs)): ?>
                        <span class="badge badge-danger"><?= count($errorLogs) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($errorLogs)): ?>
                        <div class="text-center text-success">
                            <i class="fas fa-check-circle fa-3x mb-3"></i>
                            <p>No recent errors!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($errorLogs as $error): ?>
                            <div class="alert alert-danger py-2">
                                <small>
                                    <strong><?= htmlspecialchars($error->action) ?></strong><br>
                                    <span class="text-muted"><?= date('M j, H:i', strtotime($error->created_at)) ?></span>
                                    <?php if ($error->response_code): ?>
                                        <span class="badge badge-danger ml-2"><?= $error->response_code ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Information -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> System Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Module Version:</strong><br>
                            <span class="text-muted">1.0.0</span>
                        </div>
                        <div class="col-md-3">
                            <strong>PHP Version:</strong><br>
                            <span class="text-muted"><?= PHP_VERSION ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>WHMCS Version:</strong><br>
                            <span class="text-muted"><?= $GLOBALS['CONFIG']['Version'] ?? 'Unknown' ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>Default Region:</strong><br>
                            <span class="text-muted"><?= ConfigHelper::getDefaultDataCenter() ?></span>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <strong>Enabled Features:</strong><br>
                            <div class="mt-2">
                                <?php
                                $features = [
                                    'object_storage' => 'Object Storage',
                                    'private_networks' => 'Private Networks',
                                    'vip_addresses' => 'VIP Addresses',
                                    'custom_images' => 'Custom Images',
                                    'cloud_init' => 'Cloud-Init'
                                ];
                                
                                foreach ($features as $key => $name):
                                    $enabled = ConfigHelper::isFeatureEnabled($key);
                                ?>
                                    <span class="badge badge-<?= $enabled ? 'success' : 'secondary' ?> mr-2">
                                        <?= $name ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh connection status every 60 seconds
setInterval(function() {
    // This would make an AJAX call to check connection status
    // Implementation depends on your specific needs
}, 60000);
</script>
