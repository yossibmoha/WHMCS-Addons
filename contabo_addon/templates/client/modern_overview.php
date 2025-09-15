<?php
/**
 * Modern Client Area Overview - VPS Server Management Interface
 * Compatible with shufyTheme
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\ComputeService;
use ContaboAddon\Services\BackupService;
use ContaboAddon\Services\VNCService;
use ContaboAddon\Services\AddonService;
use ContaboAddon\Services\RebuildService;
use ContaboAddon\Helpers\ConfigHelper;
use ContaboAddon\Helpers\LogHelper;
use WHMCS\Database\Capsule;

// Get service details
$serviceId = $vars['serviceid'];
$clientsDetails = $vars['clientsdetails'];
$customfields = $vars['customfields'];
$productname = $vars['productname'];
$domain = $vars['domain'];

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

$computeService = new ComputeService($apiClient, $log);
$backupService = new BackupService($apiClient);
$vncService = new VNCService($apiClient);
$addonService = new AddonService($apiClient);
$rebuildService = new RebuildService($apiClient);

// Get instance details
$instance = null;
$instanceStatus = 'unknown';
$backupConfig = null;
$vncCredentials = null;
$instanceAddons = null;

try {
    $localInstance = Capsule::table('mod_contabo_instances')
        ->where('service_id', $serviceId)
        ->first();

    if ($localInstance) {
        $instanceResponse = $computeService->getInstance($localInstance->contabo_instance_id);
        $instance = $instanceResponse['data'] ?? null;
        $instanceStatus = $instance['status'] ?? 'unknown';

        // Get backup configuration
        try {
            $backupConfig = $backupService->getBackupConfig($localInstance->contabo_instance_id);
        } catch (Exception $e) {
            // Backup not configured
        }

        // Get VNC credentials
        try {
            $vncCredentials = $vncService->getVNCCredentials($localInstance->contabo_instance_id);
        } catch (Exception $e) {
            // VNC not available
        }

        // Get instance add-ons
        try {
            $instanceAddons = $addonService->getInstanceAddons($localInstance->contabo_instance_id);
        } catch (Exception $e) {
            // No add-ons
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!-- VPS Server Management Interface Styles -->
<style>
.vps-modern {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    line-height: 1.6;
}
.vps-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    margin-bottom: 24px;
    overflow: hidden;
    transition: all 0.3s ease;
}
.vps-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}
.vps-card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 24px;
    font-weight: 600;
    font-size: 18px;
}
.vps-card-body {
    padding: 24px;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.status-running { background: #d4edda; color: #155724; }
.status-stopped { background: #f8d7da; color: #721c24; }
.status-pending { background: #fff3cd; color: #856404; }
.status-provisioning { background: #cce7ff; color: #004085; }
.vps-btn {
    display: inline-flex;
    align-items: center;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    font-size: 14px;
}
.vps-btn-primary { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    color: white;
}
.vps-btn-success { background: #28a745; color: white; }
.vps-btn-warning { background: #ffc107; color: #212529; }
.vps-btn-danger { background: #dc3545; color: white; }
.vps-btn-info { background: #17a2b8; color: white; }
.vps-btn-secondary { background: #6c757d; color: white; }
.vps-btn:hover { transform: translateY(-1px); opacity: 0.9; }
.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.feature-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
}
.feature-card:hover { background: #e9ecef; }
.feature-icon {
    font-size: 32px;
    margin-bottom: 12px;
    display: block;
}
.specs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 16px;
    margin: 20px 0;
}
.spec-item {
    text-align: center;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 8px;
}
.spec-value {
    font-size: 24px;
    font-weight: 700;
    color: #667eea;
}
.spec-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    margin-top: 4px;
}
</style>

<div class="vps-modern">
    <!-- Service Header -->
    <div class="vps-card">
        <div class="vps-card-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <i class="fas fa-server" style="margin-right: 8px;"></i>
                    <?= htmlspecialchars($productname) ?>
                </div>
                <span class="status-badge status-<?= strtolower($instanceStatus) ?>">
                    <i class="fas fa-circle" style="margin-right: 6px; font-size: 8px;"></i>
                    <?= ucfirst($instanceStatus) ?>
                </span>
            </div>
        </div>
        
        <div class="vps-card-body">
            <?php if ($instance): ?>
                <!-- Server Specifications -->
                <div class="specs-grid">
                    <div class="spec-item">
                        <div class="spec-value"><?= $instance['cpuCores'] ?? 0 ?></div>
                        <div class="spec-label">CPU Cores</div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-value"><?= round(($instance['ramMb'] ?? 0) / 1024, 1) ?>GB</div>
                        <div class="spec-label">RAM</div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-value"><?= round(($instance['diskMb'] ?? 0) / 1024, 1) ?>GB</div>
                        <div class="spec-label">Storage</div>
                    </div>
                    <div class="spec-item">
                        <div class="spec-value"><?= $instance['region'] ?? 'N/A' ?></div>
                        <div class="spec-label">Location</div>
                    </div>
                </div>

                <!-- Network Information -->
                <div style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 20px 0;">
                    <h6 style="margin: 0 0 12px 0; color: #495057;">
                        <i class="fas fa-network-wired" style="margin-right: 8px;"></i>
                        Network Information
                    </h6>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <?php if (isset($instance['ipConfig']['v4']['ip'])): ?>
                            <div>
                                <strong>IPv4:</strong> 
                                <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">
                                    <?= $instance['ipConfig']['v4']['ip'] ?>
                                </code>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($instance['ipConfig']['v6']['ip'])): ?>
                            <div>
                                <strong>IPv6:</strong> 
                                <code style="background: #e9ecef; padding: 4px 8px; border-radius: 4px;">
                                    <?= $instance['ipConfig']['v6']['ip'] ?>
                                </code>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Server information is being loaded. Please refresh the page in a moment.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($instance): ?>
        <!-- Server Controls -->
        <div class="vps-card">
            <div class="vps-card-header">
                <i class="fas fa-bolt" style="margin-right: 8px;"></i>
                Server Controls
            </div>
            <div class="vps-card-body">
                <div style="display: flex; flex-wrap: wrap; gap: 12px;">
                    <?php if ($instanceStatus === 'running'): ?>
                        <button class="vps-btn vps-btn-warning" onclick="performAction('restart')">
                            <i class="fas fa-redo" style="margin-right: 8px;"></i>
                            Restart
                        </button>
                        <button class="vps-btn vps-btn-danger" onclick="performAction('stop')">
                            <i class="fas fa-stop" style="margin-right: 8px;"></i>
                            Stop
                        </button>
                    <?php elseif ($instanceStatus === 'stopped'): ?>
                        <button class="vps-btn vps-btn-success" onclick="performAction('start')">
                            <i class="fas fa-play" style="margin-right: 8px;"></i>
                            Start
                        </button>
                    <?php elseif ($instanceStatus === 'provisioning'): ?>
                        <button class="vps-btn vps-btn-secondary" disabled>
                            <i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>
                            Rebuilding...
                        </button>
                    <?php endif; ?>
                    
                    <button class="vps-btn vps-btn-info" onclick="resetPassword()" 
                            <?= ($instanceStatus === 'provisioning') ? 'disabled' : '' ?>>
                        <i class="fas fa-key" style="margin-right: 8px;"></i>
                        Reset Password
                    </button>
                    
                    <?php if ($vncCredentials && $vncCredentials['enabled']): ?>
                        <button class="vps-btn vps-btn-secondary" onclick="openVNC()"
                                <?= ($instanceStatus !== 'running') ? 'disabled' : '' ?>>
                            <i class="fas fa-desktop" style="margin-right: 8px;"></i>
                            Remote Console
                        </button>
                    <?php endif; ?>
                    
                    <button class="vps-btn vps-btn-primary" onclick="createSnapshot()"
                            <?= ($instanceStatus !== 'running') ? 'disabled' : '' ?>>
                        <i class="fas fa-camera" style="margin-right: 8px;"></i>
                        Create Snapshot
                    </button>

                    <button class="vps-btn vps-btn-warning" onclick="rebuildServer()" 
                            <?= ($instanceStatus === 'provisioning') ? 'disabled' : '' ?>>
                        <i class="fas fa-hammer" style="margin-right: 8px;"></i>
                        Rebuild Server
                    </button>
                </div>
            </div>
        </div>

        <!-- Features Grid -->
        <div class="feature-grid">
            <!-- Backup Management -->
            <div class="feature-card">
                <i class="fas fa-hdd feature-icon" style="color: #dc3545;"></i>
                <h5 style="margin: 0 0 8px 0;">Backup Management</h5>
                <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 14px;">
                    <?php if ($backupConfig && $backupConfig['enabled']): ?>
                        Automated backups enabled<br>
                        <strong>Retention:</strong> <?= $backupConfig['retention_days'] ?> days
                    <?php else: ?>
                        Automated backups not enabled
                    <?php endif; ?>
                </p>
                <button class="vps-btn vps-btn-primary" onclick="manageBackups()">
                    <i class="fas fa-cog" style="margin-right: 8px;"></i>
                    Manage Backups
                </button>
            </div>

            <!-- Add-ons Management -->
            <div class="feature-card">
                <i class="fas fa-puzzle-piece feature-icon" style="color: #6f42c1;"></i>
                <h5 style="margin: 0 0 8px 0;">Server Add-ons</h5>
                <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 14px;">
                    <?php if ($instanceAddons && count($instanceAddons) > 0): ?>
                        <?= count($instanceAddons) ?> add-on(s) active
                    <?php else: ?>
                        No add-ons installed
                    <?php endif; ?>
                </p>
                <button class="vps-btn vps-btn-primary" onclick="manageAddons()"></button>
                    <i class="fas fa-plus" style="margin-right: 8px;"></i>
                    Manage Add-ons
                </button>
            </div>

            <!-- Application Marketplace -->
            <div class="feature-card">
                <i class="fas fa-rocket feature-icon" style="color: #28a745;"></i>
                <h5 style="margin: 0 0 8px 0;">One-Click Apps</h5>
                <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 14px;">
                    Deploy popular applications instantly
                </p>
                <button class="vps-btn vps-btn-success" onclick="browseApps()">
                    <i class="fas fa-store" style="margin-right: 8px;"></i>
                    Browse Apps
                </button>
            </div>

            <!-- Server Rebuild -->
            <div class="feature-card">
                <i class="fas fa-hammer feature-icon" style="color: #ffc107;"></i>
                <h5 style="margin: 0 0 8px 0;">Server Rebuild</h5>
                <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 14px;">
                    Fresh OS installation or custom images
                </p>
                <button class="vps-btn vps-btn-warning" onclick="rebuildServer()" 
                        <?= ($instanceStatus === 'provisioning') ? 'disabled' : '' ?>>
                    <i class="fas fa-tools" style="margin-right: 8px;"></i>
                    Rebuild Server
                </button>
            </div>

            <!-- Snapshots -->
            <div class="feature-card">
                <i class="fas fa-camera feature-icon" style="color: #17a2b8;"></i>
                <h5 style="margin: 0 0 8px 0;">Snapshots</h5>
                <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 14px;">
                    Create and manage server snapshots
                </p>
                <button class="vps-btn vps-btn-info" onclick="manageSnapshots()">
                    <i class="fas fa-list" style="margin-right: 8px;"></i>
                    View Snapshots
                </button>
            </div>

            <!-- Network Settings -->
            <div class="feature-card">
                <i class="fas fa-network-wired feature-icon" style="color: #6c757d;"></i>
                <h5 style="margin: 0 0 8px 0;">Networking</h5>
                <p style="margin: 0 0 16px 0; color: #6c757d; font-size: 14px;">
                    Manage private networks and additional IPs
                </p>
                <button class="vps-btn vps-btn-secondary" onclick="manageNetwork()">
                    <i class="fas fa-cog" style="margin-right: 8px;"></i>
                    Network Settings
                </button>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="vps-card">
            <div class="vps-card-header">
                <i class="fas fa-history" style="margin-right: 8px;"></i>
                Recent Activity
            </div>
            <div class="vps-card-body">
                <div id="activityLog">
                    <div style="text-align: center; color: #6c757d; padding: 20px;">
                        <i class="fas fa-spinner fa-spin"></i>
                        Loading recent activities...
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Action Modals and JavaScript -->
<script>
function performAction(action) {
    if (confirm(`Are you sure you want to ${action} this server?`)) {
        // Show loading state
        showLoading(`${action.charAt(0).toUpperCase() + action.slice(1)}ing server...`);
        
        // Make AJAX request to perform action
        fetch(`${window.location.href}&ajax=1&action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showSuccess(data.message);
                setTimeout(() => location.reload(), 2000);
            } else {
                showError(data.error);
            }
        })
        .catch(error => {
            hideLoading();
            showError('An error occurred. Please try again.');
        });
    }
}

function resetPassword() {
    if (confirm('This will reset the root password for your server. Continue?')) {
        showLoading('Resetting password...');
        
        fetch(`${window.location.href}&ajax=1&action=resetPassword`, {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showSuccess('Password reset successfully! New password: ' + data.password);
            } else {
                showError(data.error);
            }
        });
    }
}

function openVNC() {
    window.open('<?= $vncCredentials['url'] ?? '#' ?>', '_blank', 'width=1024,height=768');
}

function createSnapshot() {
    const name = prompt('Enter snapshot name:', `snapshot-${Date.now()}`);
    if (name) {
        showLoading('Creating snapshot...');
        
        fetch(`${window.location.href}&ajax=1&action=createSnapshot`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ name: name })
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showSuccess('Snapshot created successfully!');
            } else {
                showError(data.error);
            }
        });
    }
}

function manageBackups() {
    showModal('backup-management');
}

function manageAddons() {
    showModal('addon-management');
}

function browseApps() {
    showModal('application-marketplace');
}

function rebuildServer() {
    if (confirm('⚠️ WARNING: This will completely wipe your server and reinstall the operating system.\n\nAll data will be permanently lost unless you have backups.\n\nDo you want to continue?')) {
        // Show rebuild options modal
        showRebuildModal();
    }
}

function manageSnapshots() {
    showModal('snapshot-management');
}

function manageNetwork() {
    showModal('network-management');
}

// Utility functions
function showLoading(message) {
    // Implement loading indicator
}

function hideLoading() {
    // Hide loading indicator
}

function showSuccess(message) {
    // Show success notification
    alert('Success: ' + message);
}

function showError(message) {
    // Show error notification
    alert('Error: ' + message);
}

function showModal(modalType) {
    // Implement modal system
    alert('Feature coming soon: ' + modalType);
}

function showRebuildModal() {
    // Create and show rebuild modal
    const modalHtml = `
        <div id="rebuildModal" class="modal fade" tabindex="-1" role="dialog" 
             style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                    background: rgba(0,0,0,0.5); z-index: 9999; display: flex; 
                    align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; max-width: 600px; 
                        width: 90%; max-height: 80vh; overflow-y: auto; 
                        box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                           color: white; padding: 20px; border-radius: 12px 12px 0 0;">
                    <h4 style="margin: 0; display: flex; align-items: center;">
                        <i class="fas fa-hammer" style="margin-right: 10px;"></i>
                        Rebuild Server
                    </h4>
                </div>
                <div style="padding: 24px;">
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; 
                               border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                        <h6 style="color: #856404; margin: 0 0 8px 0;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                            Important Warning
                        </h6>
                        <p style="margin: 0; color: #856404; font-size: 14px;">
                            This action will permanently delete all data on your server. 
                            Make sure you have backups before proceeding.
                        </p>
                    </div>
                    
                    <form id="rebuildForm">
                        <div style="margin-bottom: 20px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">
                                Choose Operating System:
                            </label>
                            <select id="osSelect" style="width: 100%; padding: 10px; 
                                                         border: 2px solid #e9ecef; border-radius: 6px;">
                                <option value="">Loading operating systems...</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 20px;">
                            <label style="font-weight: 600; margin-bottom: 8px; display: block;">
                                Rebuild Options:
                            </label>
                            <div style="background: #f8f9fa; padding: 16px; border-radius: 6px;">
                                <label style="display: flex; align-items: center; margin-bottom: 12px;">
                                    <input type="checkbox" id="keepSSHKeys" style="margin-right: 8px;">
                                    Keep existing SSH keys (if any)
                                </label>
                                <label style="display: flex; align-items: center;">
                                    <input type="checkbox" id="useCloudInit" style="margin-right: 8px;">
                                    Apply automatic server setup (recommended)
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div style="padding: 20px; border-top: 1px solid #e9ecef; 
                           display: flex; justify-content: space-between; align-items: center;">
                    <button onclick="closeRebuildModal()" 
                            style="padding: 10px 20px; border: 2px solid #6c757d; 
                                   background: white; color: #6c757d; border-radius: 6px; 
                                   cursor: pointer; font-weight: 500;">
                        Cancel
                    </button>
                    <button onclick="executeRebuild()" 
                            style="padding: 10px 20px; border: none; 
                                   background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); 
                                   color: white; border-radius: 6px; cursor: pointer; 
                                   font-weight: 500;">
                        <i class="fas fa-hammer" style="margin-right: 8px;"></i>
                        Rebuild Server
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    loadOperatingSystems();
}

function closeRebuildModal() {
    const modal = document.getElementById('rebuildModal');
    if (modal) {
        modal.remove();
    }
}

function loadOperatingSystems() {
    fetch('${window.location.href}&ajax=1&action=getOperatingSystems')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('osSelect');
            if (data.success) {
                select.innerHTML = '<option value="">Select an operating system...</option>';
                
                let currentCategory = '';
                data.operating_systems.forEach(os => {
                    if (os.category !== currentCategory) {
                        const optgroup = document.createElement('optgroup');
                        optgroup.label = os.category;
                        select.appendChild(optgroup);
                        currentCategory = os.category;
                    }
                    
                    const option = document.createElement('option');
                    option.value = os.id;
                    option.textContent = os.name + (os.recommended ? ' (Recommended)' : '');
                    option.style.fontWeight = os.recommended ? 'bold' : 'normal';
                    select.lastElementChild.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">Failed to load operating systems</option>';
            }
        })
        .catch(error => {
            const select = document.getElementById('osSelect');
            select.innerHTML = '<option value="">Error loading operating systems</option>';
        });
}

function executeRebuild() {
    const osSelect = document.getElementById('osSelect');
    const imageId = osSelect.value;
    const keepSSHKeys = document.getElementById('keepSSHKeys').checked;
    const useCloudInit = document.getElementById('useCloudInit').checked;
    
    if (!imageId) {
        alert('Please select an operating system');
        return;
    }
    
    const osName = osSelect.options[osSelect.selectedIndex].text;
    
    if (!confirm(`Final confirmation: Rebuild server with ${osName}?\n\nThis action cannot be undone!`)) {
        return;
    }
    
    closeRebuildModal();
    showLoading('Initiating server rebuild... This may take 10-15 minutes.');
    
    fetch('${window.location.href}&ajax=1&action=rebuildServer', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            imageId: imageId,
            keepSSHKeys: keepSSHKeys,
            useCloudInit: useCloudInit
        })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showSuccess('Server rebuild initiated successfully! \\n\\nEstimated completion time: ' + 
                       (data.estimated_completion || '15 minutes'));
            setTimeout(() => location.reload(), 3000);
        } else {
            showError('Rebuild failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        hideLoading();
        showError('An error occurred during rebuild. Please try again.');
    });
}
</script>
