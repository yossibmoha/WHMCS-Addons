<?php
/**
 * Firewall Management Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\FirewallService;
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

$firewallService = new FirewallService($apiClient);

// Handle actions
$action = $_GET['firewall_action'] ?? 'dashboard';
$instanceId = $_GET['instance_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'create_rule':
            if ($_POST && $instanceId) {
                $result = $firewallService->createFirewallRule($instanceId, [
                    'name' => $_POST['name'],
                    'action' => $_POST['action'],
                    'protocol' => $_POST['protocol'],
                    'port_range' => $_POST['port_range'],
                    'source_ip' => $_POST['source_ip'],
                    'direction' => $_POST['direction'],
                    'priority' => $_POST['priority'],
                    'created_by' => 'admin'
                ]);
                $message = 'Firewall rule created successfully';
            }
            break;
            
        case 'apply_template':
            if ($_POST && $instanceId) {
                $result = $firewallService->applySecurityTemplate(
                    $instanceId,
                    $_POST['template_name'],
                    ['replace_existing' => $_POST['replace_existing'] ?? false]
                );
                $message = "Security template applied: {$result['results']['applied']} rules added";
            }
            break;
            
        case 'test_connectivity':
            if ($instanceId) {
                $testPorts = explode(',', $_GET['ports'] ?? '22,80,443');
                $testResults = $firewallService->testFirewallConnectivity($instanceId, $testPorts);
                $message = 'Connectivity test completed';
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get firewall statistics
$globalStats = [];
try {
    $globalStats = $firewallService->getFirewallStatistics();
} catch (Exception $e) {
    $error = 'Failed to load firewall statistics: ' . $e->getMessage();
}

// Get all instances for management
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

// Get security templates
$templates = $firewallService->getSecurityTemplates();

// Get firewall rules for selected instance
$firewallRules = [];
if ($instanceId) {
    try {
        $firewallRules = $firewallService->getFirewallRules($instanceId);
    } catch (Exception $e) {
        $error = 'Failed to load firewall rules: ' . $e->getMessage();
    }
}
?>

<div class="firewall-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-shield-alt"></i> Firewall Management</h2>
            <p>Manage firewall rules, security templates, and server protection</p>
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

    <!-- Global Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h4><i class="fas fa-rules"></i> <?= $globalStats['total_rules'] ?? 0 ?></h4>
                    <p>Total Firewall Rules</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h4><i class="fas fa-check"></i> <?= $globalStats['allow_rules'] ?? 0 ?></h4>
                    <p>Allow Rules</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h4><i class="fas fa-ban"></i> <?= $globalStats['deny_rules'] ?? 0 ?></h4>
                    <p>Deny Rules</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h4><i class="fas fa-download"></i> <?= $globalStats['inbound_rules'] ?? 0 ?></h4>
                    <p>Inbound Rules</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Instance Selection -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-server"></i> Select Server</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <input type="hidden" name="module" value="contabo_addon">
                        <input type="hidden" name="action" value="firewall">
                        
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
                        
                        <?php if ($instanceId): ?>
                            <div class="btn-group ml-3">
                                <button type="button" class="btn btn-success" onclick="testConnectivity()">
                                    <i class="fas fa-plug"></i> Test Connectivity
                                </button>
                                <button type="button" class="btn btn-warning" onclick="showCreateRule()">
                                    <i class="fas fa-plus"></i> Add Rule
                                </button>
                                <button type="button" class="btn btn-info" onclick="showTemplates()">
                                    <i class="fas fa-list"></i> Apply Template
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($instanceId && !empty($firewallRules)): ?>
        <!-- Current Firewall Rules -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4><i class="fas fa-list-ul"></i> Firewall Rules</h4>
                        <span class="badge badge-primary"><?= count($firewallRules['rules']) ?> rules</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($firewallRules['rules'])): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No firewall rules configured. Apply a security template or create custom rules.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Rule Name</th>
                                            <th>Action</th>
                                            <th>Protocol</th>
                                            <th>Port/Range</th>
                                            <th>Source</th>
                                            <th>Direction</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($firewallRules['rules'] as $rule): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($rule['name']) ?></strong>
                                                    <?php if ($rule['system_rule'] ?? false): ?>
                                                        <br><small class="badge badge-secondary">System</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($rule['action'] === 'allow'): ?>
                                                        <span class="badge badge-success">Allow</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Deny</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <code><?= htmlspecialchars(strtoupper($rule['protocol'])) ?></code>
                                                </td>
                                                <td>
                                                    <code><?= htmlspecialchars($rule['port_range'] ?: 'Any') ?></code>
                                                </td>
                                                <td>
                                                    <code><?= htmlspecialchars($rule['source_ip'] ?: 'Any') ?></code>
                                                </td>
                                                <td>
                                                    <?php if ($rule['direction'] === 'inbound'): ?>
                                                        <span class="badge badge-info">Inbound</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Outbound</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $rule['priority'] ?? 100 ?></td>
                                                <td>
                                                    <?php if ($rule['enabled'] ?? true): ?>
                                                        <span class="badge badge-success">Enabled</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Disabled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if (!($rule['system_rule'] ?? false)): ?>
                                                            <button class="btn btn-warning" onclick="editRule('<?= $rule['id'] ?>')">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button class="btn btn-danger" onclick="deleteRule('<?= $rule['id'] ?>', '<?= htmlspecialchars($rule['name']) ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-secondary btn-sm" disabled title="System rule cannot be modified">
                                                                <i class="fas fa-lock"></i>
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
    <?php endif; ?>

    <!-- Security Templates -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-clipboard-list"></i> Security Templates</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($templates as $templateKey => $template): ?>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title"><?= htmlspecialchars($template['name']) ?></h5>
                                        <p class="card-text"><?= htmlspecialchars($template['description']) ?></p>
                                        <div class="mb-3">
                                            <strong>Rules:</strong>
                                            <ul class="small">
                                                <?php foreach ($template['rules'] as $rule): ?>
                                                    <li>
                                                        <?= htmlspecialchars($rule['name']) ?>
                                                        (<?= strtoupper($rule['protocol']) ?>
                                                        <?= $rule['port_range'] ? ':' . $rule['port_range'] : '' ?>)
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php if ($instanceId): ?>
                                            <button class="btn btn-primary btn-sm" 
                                                    onclick="applyTemplate('<?= $templateKey ?>', '<?= htmlspecialchars($template['name']) ?>')">
                                                <i class="fas fa-check"></i> Apply Template
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled>
                                                Select Server First
                                            </button>
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

    <!-- Most Common Ports -->
    <?php if (!empty($globalStats['most_common_ports'])): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-bar"></i> Most Common Ports</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach (array_slice($globalStats['most_common_ports'], 0, 6, true) as $port => $count): ?>
                                <div class="col-md-2 text-center mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <h4><?= htmlspecialchars($port) ?></h4>
                                            <p class="mb-0"><?= $count ?> rules</p>
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

<!-- Create Rule Modal -->
<div class="modal fade" id="createRuleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="firewall_action" value="create_rule">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create Firewall Rule</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Rule Name</label>
                                <input type="text" name="name" class="form-control" required
                                       placeholder="e.g., Allow Web Traffic">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Action</label>
                                <select name="action" class="form-control" required>
                                    <option value="allow">Allow</option>
                                    <option value="deny">Deny</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Protocol</label>
                                <select name="protocol" class="form-control" required>
                                    <option value="tcp">TCP</option>
                                    <option value="udp">UDP</option>
                                    <option value="icmp">ICMP</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Port Range</label>
                                <input type="text" name="port_range" class="form-control"
                                       placeholder="80, 443, 3000-8000, or leave empty for all">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Source IP</label>
                                <input type="text" name="source_ip" class="form-control" 
                                       value="0.0.0.0/0" placeholder="0.0.0.0/0">
                                <small class="form-text text-muted">
                                    IP address or CIDR block (e.g., 192.168.1.0/24)
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Direction</label>
                                <select name="direction" class="form-control" required>
                                    <option value="inbound" selected>Inbound</option>
                                    <option value="outbound">Outbound</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Priority (1-999)</label>
                        <input type="number" name="priority" class="form-control" 
                               value="100" min="1" max="999">
                        <small class="form-text text-muted">
                            Lower numbers have higher priority
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Apply Template Modal -->
<div class="modal fade" id="applyTemplateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="firewall_action" value="apply_template">
                <input type="hidden" name="template_name" id="template_name_input">
                
                <div class="modal-header">
                    <h5 class="modal-title">Apply Security Template</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        You are about to apply the <strong id="template_display_name"></strong> template.
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" name="replace_existing" value="1" class="form-check-input" id="replaceExisting">
                        <label class="form-check-label" for="replaceExisting">
                            Replace all existing rules
                        </label>
                        <small class="form-text text-muted">
                            If unchecked, template rules will be added to existing rules
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreateRule() {
    if (!<?= json_encode($instanceId !== null) ?>) {
        alert('Please select a server first');
        return;
    }
    $('#createRuleModal').modal('show');
}

function showTemplates() {
    if (!<?= json_encode($instanceId !== null) ?>) {
        alert('Please select a server first');
        return;
    }
    // Templates are already shown in the page
    document.querySelector('.card:nth-of-type(4)').scrollIntoView({ behavior: 'smooth' });
}

function applyTemplate(templateKey, templateName) {
    document.getElementById('template_name_input').value = templateKey;
    document.getElementById('template_display_name').textContent = templateName;
    $('#applyTemplateModal').modal('show');
}

function testConnectivity() {
    const ports = prompt('Enter ports to test (comma-separated):', '22,80,443');
    if (ports) {
        window.open('<?= $vars['modulelink'] ?>&action=firewall&firewall_action=test_connectivity&instance_id=<?= urlencode($instanceId ?? '') ?>&ports=' + encodeURIComponent(ports), '_blank');
    }
}

function editRule(ruleId) {
    alert('Edit rule functionality coming soon');
}

function deleteRule(ruleId, ruleName) {
    if (confirm('Are you sure you want to delete the rule "' + ruleName + '"?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=firewall&firewall_action=delete_rule&instance_id=<?= urlencode($instanceId ?? '') ?>&rule_id=' + ruleId;
    }
}
</script>
