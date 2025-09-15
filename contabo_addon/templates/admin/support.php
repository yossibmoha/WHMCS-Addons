<?php
/**
 * Support Integration Admin Template
 */

use ContaboAddon\API\ContaboAPIClient;
use ContaboAddon\Services\SupportIntegrationService;
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

$supportService = new SupportIntegrationService($apiClient);

// Handle actions
$action = $_GET['support_action'] ?? 'dashboard';
$instanceId = $_GET['instance_id'] ?? null;
$ruleId = $_GET['rule_id'] ?? null;
$message = '';
$error = '';

try {
    switch ($action) {
        case 'create_rule':
            if ($_POST) {
                $result = $supportService->createTicketRule([
                    'rule_name' => $_POST['rule_name'],
                    'instance_id' => $_POST['instance_id'] ?: null,
                    'trigger_condition' => $_POST['trigger_condition'],
                    'condition_parameters' => [
                        'threshold' => $_POST['threshold'] ?? null,
                        'duration' => $_POST['duration'] ?? null
                    ],
                    'ticket_department' => $_POST['ticket_department'],
                    'ticket_priority' => $_POST['ticket_priority'],
                    'ticket_subject_template' => $_POST['ticket_subject_template'],
                    'ticket_message_template' => $_POST['ticket_message_template'],
                    'auto_assign_admin' => $_POST['auto_assign_admin'] ?: null,
                    'escalation_time' => $_POST['escalation_time'] ?: null,
                    'max_tickets_per_day' => $_POST['max_tickets_per_day'],
                    'created_by' => 'admin'
                ]);
                $message = 'Support ticket rule created successfully';
            }
            break;
            
        case 'update_rule':
            if ($_POST && $ruleId) {
                $result = $supportService->updateTicketRule($ruleId, [
                    'rule_name' => $_POST['rule_name'],
                    'trigger_condition' => $_POST['trigger_condition'],
                    'condition_parameters' => [
                        'threshold' => $_POST['threshold'] ?? null,
                        'duration' => $_POST['duration'] ?? null
                    ],
                    'ticket_department' => $_POST['ticket_department'],
                    'ticket_priority' => $_POST['ticket_priority'],
                    'ticket_subject_template' => $_POST['ticket_subject_template'],
                    'ticket_message_template' => $_POST['ticket_message_template'],
                    'auto_assign_admin' => $_POST['auto_assign_admin'] ?: null,
                    'escalation_time' => $_POST['escalation_time'] ?: null,
                    'max_tickets_per_day' => $_POST['max_tickets_per_day'],
                    'is_active' => isset($_POST['is_active'])
                ]);
                $message = 'Support ticket rule updated successfully';
            }
            break;
            
        case 'delete_rule':
            if ($ruleId) {
                $result = $supportService->deleteTicketRule($ruleId);
                $message = 'Support ticket rule deleted successfully';
            }
            break;
            
        case 'create_manual_ticket':
            if ($_POST) {
                $result = $supportService->createManualTicket([
                    'instance_id' => $_POST['instance_id'] ?: null,
                    'user_id' => $_POST['user_id'] ?: null,
                    'department_id' => $_POST['department_id'],
                    'subject' => $_POST['subject'],
                    'message' => $_POST['message'],
                    'priority' => $_POST['priority'],
                    'admin_id' => $_POST['admin_id'] ?: null,
                    'created_by' => 'admin'
                ]);
                $message = $result['success'] ? 'Support ticket created successfully' : 'Failed to create ticket';
            }
            break;
            
        case 'test_rule':
            if ($ruleId) {
                $testResult = $supportService->testTicketCreation($ruleId);
                $_SESSION['test_result'] = $testResult;
                $message = 'Ticket rule test completed';
            }
            break;
            
        case 'check_rules':
            if ($_POST) {
                $results = $supportService->checkAndCreateTickets();
                $message = "Rule check completed: {$results['triggered']} tickets created, {$results['skipped']} skipped";
            }
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get support statistics
$stats = [];
try {
    $stats = $supportService->getSupportStatistics();
} catch (Exception $e) {
    $error = 'Failed to load support statistics: ' . $e->getMessage();
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

// Get support ticket rules
$ticketRules = [];
try {
    $ticketRules = $supportService->getTicketRules($instanceId);
} catch (Exception $e) {
    // Continue with empty array
}

// Get ticket history
$ticketHistory = [];
try {
    $ticketHistory = $supportService->getTicketHistory($instanceId, 20);
} catch (Exception $e) {
    // Continue with empty array
}

// Get departments and admins
$departments = $supportService->getWHMCSDepartments();
$adminUsers = $supportService->getAdminUsers();

// Get test result from session
$testResult = $_SESSION['test_result'] ?? null;
unset($_SESSION['test_result']);
?>

<div class="support-integration-management">
    <!-- Header -->
    <div class="row">
        <div class="col-md-12">
            <h2><i class="fas fa-life-ring"></i> Support Integration</h2>
            <p>Automated ticket creation for server issues and WHMCS support integration</p>
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

    <!-- Support Statistics -->
    <?php if (!empty($stats)): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-cogs"></i> <?= $stats['total_rules'] ?? 0 ?></h4>
                        <p>Ticket Rules</p>
                        <small><?= $stats['active_rules'] ?? 0 ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-ticket-alt"></i> <?= $stats['tickets_created_24h'] ?? 0 ?></h4>
                        <p>Tickets Today</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-calendar-week"></i> <?= $stats['tickets_created_7d'] ?? 0 ?></h4>
                        <p>Tickets (7 days)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h4><i class="fas fa-exclamation-triangle"></i> Auto</h4>
                        <p>Integration</p>
                        <small>Intelligent alerts</small>
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
                    <h4><i class="fas fa-bolt"></i> Support Actions</h4>
                </div>
                <div class="card-body">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="showCreateRule()">
                            <i class="fas fa-plus"></i> Create Rule
                        </button>
                        <button class="btn btn-success" onclick="showCreateManualTicket()">
                            <i class="fas fa-plus-circle"></i> Manual Ticket
                        </button>
                        <button class="btn btn-warning" onclick="checkAllRules()">
                            <i class="fas fa-search"></i> Check All Rules
                        </button>
                        <button class="btn btn-info" onclick="viewTicketHistory()">
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
                    <h4><i class="fas fa-server"></i> Server Filter</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <input type="hidden" name="module" value="contabo_addon">
                        <input type="hidden" name="action" value="support">
                        
                        <select name="instance_id" class="form-control mr-3" onchange="this.form.submit()">
                            <option value="">All servers and global rules</option>
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

        <!-- Condition Distribution -->
        <div class="col-md-4">
            <?php if (!empty($stats['condition_distribution'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-chart-pie"></i> Trigger Conditions</h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($stats['condition_distribution'] as $condition => $count): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><strong><?= ucwords(str_replace('_', ' ', $condition)) ?></strong></span>
                                <span class="badge badge-primary"><?= $count ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ticket Rules -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-list"></i> Support Ticket Rules</h4>
                    <button class="btn btn-primary btn-sm" onclick="showCreateRule()">
                        <i class="fas fa-plus"></i> Create Rule
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($ticketRules)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No support ticket rules configured. Create rules to enable automatic ticket creation.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Rule Name</th>
                                        <th>Server</th>
                                        <th>Trigger Condition</th>
                                        <th>Department</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Last Triggered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ticketRules as $rule): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($rule['rule_name']) ?></strong><br>
                                                <small class="text-muted">Max: <?= $rule['max_tickets_per_day'] ?>/day</small>
                                            </td>
                                            <td>
                                                <?= $rule['instance_name'] ? htmlspecialchars($rule['instance_name']) : '<em>Global</em>' ?><br>
                                                <?php if ($rule['instance_id']): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($rule['instance_id']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?= ucwords(str_replace('_', ' ', $rule['trigger_condition'])) ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $dept = array_filter($departments, function($d) use ($rule) { 
                                                    return $d['id'] == $rule['ticket_department']; 
                                                });
                                                echo $dept ? htmlspecialchars(array_values($dept)[0]['name']) : 'Unknown';
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $priorityColors = [
                                                    'Low' => 'success',
                                                    'Medium' => 'warning', 
                                                    'High' => 'danger'
                                                ];
                                                $color = $priorityColors[$rule['ticket_priority']] ?? 'secondary';
                                                ?>
                                                <span class="badge badge-<?= $color ?>"><?= $rule['ticket_priority'] ?></span>
                                            </td>
                                            <td>
                                                <?php if ($rule['is_active']): ?>
                                                    <span class="badge badge-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $rule['last_triggered'] ? date('M j, H:i', strtotime($rule['last_triggered'])) : 'Never' ?><br>
                                                <small class="text-muted"><?= $rule['trigger_count'] ?> times</small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-info" onclick="testRule(<?= $rule['id'] ?>)" title="Test Rule">
                                                        <i class="fas fa-flask"></i>
                                                    </button>
                                                    <button class="btn btn-warning" onclick="editRule(<?= $rule['id'] ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger" onclick="deleteRule(<?= $rule['id'] ?>, '<?= htmlspecialchars($rule['rule_name']) ?>')">
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

    <!-- Test Result -->
    <?php if ($testResult): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-flask"></i> Rule Test Result - <?= htmlspecialchars($testResult['rule_name']) ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-<?= $testResult['would_create'] ? 'success' : 'warning' ?>">
                            <strong>Result:</strong> 
                            <?= $testResult['would_create'] ? 'Ticket would be created' : 'Conditions not met - no ticket would be created' ?>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Generated Subject:</h6>
                                <div class="border p-3 mb-3" style="background-color: #f8f9fa;">
                                    <?= htmlspecialchars($testResult['test_subject']) ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Test Data:</h6>
                                <div class="border p-3 mb-3" style="background-color: #f8f9fa;">
                                    <pre><?= json_encode($testResult['test_data'], JSON_PRETTY_PRINT) ?></pre>
                                </div>
                            </div>
                        </div>
                        
                        <h6>Generated Message:</h6>
                        <div class="border p-3" style="background-color: #f8f9fa;">
                            <?= nl2br(htmlspecialchars($testResult['test_message'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Ticket History -->
    <?php if (!empty($ticketHistory)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-history"></i> Recent Ticket Activity</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Created</th>
                                        <th>Server</th>
                                        <th>Rule</th>
                                        <th>Subject</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ticketHistory as $ticket): ?>
                                        <tr>
                                            <td>
                                                <?= date('M j, H:i', strtotime($ticket['created_at'])) ?><br>
                                                <small class="text-muted">ID: #<?= $ticket['ticket_id'] ?></small>
                                            </td>
                                            <td>
                                                <?= $ticket['instance_name'] ? htmlspecialchars($ticket['instance_name']) : '<em>N/A</em>' ?><br>
                                                <?php if ($ticket['instance_id']): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($ticket['instance_id']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($ticket['rule_name']) ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($ticket['subject']) ?></strong>
                                            </td>
                                            <td>
                                                <?php
                                                $priorityColors = [
                                                    'Low' => 'success',
                                                    'Medium' => 'warning',
                                                    'High' => 'danger'
                                                ];
                                                $color = $priorityColors[$ticket['priority']] ?? 'secondary';
                                                ?>
                                                <span class="badge badge-<?= $color ?>"><?= $ticket['priority'] ?></span>
                                            </td>
                                            <td>
                                                <?php if ($ticket['ticket_status']): ?>
                                                    <span class="badge badge-info"><?= ucfirst($ticket['ticket_status']) ?></span><br>
                                                    <?php if ($ticket['last_reply']): ?>
                                                        <small class="text-muted">Reply: <?= date('M j', strtotime($ticket['last_reply'])) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="../supporttickets.php?action=view&id=<?= $ticket['ticket_id'] ?>" 
                                                   class="btn btn-info btn-sm" target="_blank">
                                                    <i class="fas fa-external-link-alt"></i> View
                                                </a>
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

<!-- Create Rule Modal -->
<div class="modal fade" id="createRuleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="support_action" value="create_rule">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create Support Ticket Rule</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Support ticket rules automatically create tickets when server conditions are met.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Rule Name</label>
                                <input type="text" name="rule_name" class="form-control" required
                                       placeholder="e.g., High CPU Alert">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Server (Optional)</label>
                                <select name="instance_id" class="form-control">
                                    <option value="">Global Rule (All Servers)</option>
                                    <?php foreach ($instances as $instance): ?>
                                        <option value="<?= $instance->contabo_instance_id ?>">
                                            <?= htmlspecialchars($instance->name ?: $instance->contabo_instance_id) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Trigger Condition</label>
                                <select name="trigger_condition" class="form-control" required>
                                    <option value="server_down">Server Down/Offline</option>
                                    <option value="high_cpu">High CPU Usage</option>
                                    <option value="high_memory">High Memory Usage</option>
                                    <option value="disk_full">Disk Full</option>
                                    <option value="high_response_time">High Response Time</option>
                                    <option value="backup_failed">Backup Failed</option>
                                    <option value="scaling_failed">Auto-scaling Failed</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Threshold (Optional)</label>
                                <input type="number" name="threshold" class="form-control" 
                                       placeholder="80" min="1" max="100">
                                <small class="form-text text-muted">For percentage-based conditions</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Department</label>
                                <select name="ticket_department" class="form-control" required>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="ticket_priority" class="form-control" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Max Tickets/Day</label>
                                <input type="number" name="max_tickets_per_day" class="form-control" 
                                       value="5" min="1" max="20">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Subject Template</label>
                        <input type="text" name="ticket_subject_template" class="form-control" required
                               value="[ALERT] {condition} detected on {instance_name}"
                               placeholder="Available: {instance_name}, {condition}, {metric_value}, {timestamp}">
                    </div>
                    
                    <div class="form-group">
                        <label>Message Template</label>
                        <textarea name="ticket_message_template" class="form-control" rows="4" required
                                  placeholder="Available: {instance_name}, {instance_id}, {condition}, {metric_value}, {threshold}, {timestamp}, {server_ip}">Alert: {condition} detected on server {instance_name} ({instance_id}).

Current metric value: {metric_value}
Threshold: {threshold}
Server IP: {server_ip}
Timestamp: {timestamp}

Please investigate this issue immediately.</textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Auto-assign Admin (Optional)</label>
                                <select name="auto_assign_admin" class="form-control">
                                    <option value="">No Assignment</option>
                                    <?php foreach ($adminUsers as $admin): ?>
                                        <option value="<?= $admin['id'] ?>">
                                            <?= htmlspecialchars($admin['name']) ?> (<?= $admin['username'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Escalation Time (minutes)</label>
                                <input type="number" name="escalation_time" class="form-control"
                                       placeholder="60" min="15" max="1440">
                                <small class="form-text text-muted">Optional: Auto-escalate if not resolved</small>
                            </div>
                        </div>
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

<!-- Create Manual Ticket Modal -->
<div class="modal fade" id="manualTicketModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="support_action" value="create_manual_ticket">
                
                <div class="modal-header">
                    <h5 class="modal-title">Create Manual Support Ticket</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Related Server (Optional)</label>
                                <select name="instance_id" class="form-control">
                                    <option value="">No specific server</option>
                                    <?php foreach ($instances as $instance): ?>
                                        <option value="<?= $instance->contabo_instance_id ?>">
                                            <?= htmlspecialchars($instance->name ?: $instance->contabo_instance_id) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Client User ID (Optional)</label>
                                <input type="number" name="user_id" class="form-control"
                                       placeholder="Leave empty for system ticket">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department_id" class="form-control" required>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="priority" class="form-control" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" required
                               placeholder="Brief description of the issue">
                    </div>
                    
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" rows="5" required
                                  placeholder="Detailed description of the issue or request"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Assign to Admin (Optional)</label>
                        <select name="admin_id" class="form-control">
                            <option value="">No Assignment</option>
                            <?php foreach ($adminUsers as $admin): ?>
                                <option value="<?= $admin['id'] ?>">
                                    <?= htmlspecialchars($admin['name']) ?> (<?= $admin['username'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showCreateRule() {
    $('#createRuleModal').modal('show');
}

function showCreateManualTicket() {
    $('#manualTicketModal').modal('show');
}

function checkAllRules() {
    if (confirm('Check all active support rules and create tickets where conditions are met?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="support_action" value="check_rules">';
        document.body.appendChild(form);
        form.submit();
    }
}

function viewTicketHistory() {
    if (document.querySelector('.support-integration-management .card:last-child')) {
        document.querySelector('.support-integration-management .card:last-child').scrollIntoView({ behavior: 'smooth' });
    }
}

function testRule(ruleId) {
    if (confirm('Test this support ticket rule with sample data?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=support&support_action=test_rule&rule_id=' + ruleId + 
                              '<?= $instanceId ? "&instance_id=" . urlencode($instanceId) : "" ?>';
    }
}

function editRule(ruleId) {
    // In real implementation, would populate form with existing data
    alert('Edit rule functionality - would load existing rule data');
}

function deleteRule(ruleId, ruleName) {
    if (confirm('Are you sure you want to delete the support rule "' + ruleName + '"?')) {
        window.location.href = '<?= $vars['modulelink'] ?>&action=support&support_action=delete_rule&rule_id=' + ruleId + 
                              '<?= $instanceId ? "&instance_id=" . urlencode($instanceId) : "" ?>';
    }
}
</script>
