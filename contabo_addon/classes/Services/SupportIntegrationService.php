<?php
/**
 * Support Integration Service
 * 
 * Handles automatic ticket creation for server issues and WHMCS support system integration
 */

namespace ContaboAddon\Services;

use Exception;

class SupportIntegrationService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Create support ticket rule
     */
    public function createTicketRule($data)
    {
        try {
            // Validate rule data
            $this->validateTicketRule($data);

            $rule = [
                'rule_name' => $data['rule_name'],
                'instance_id' => $data['instance_id'] ?? null, // null for global rules
                'trigger_condition' => $data['trigger_condition'], // server_down, high_cpu, disk_full, etc.
                'condition_parameters' => json_encode($data['condition_parameters'] ?? []),
                'ticket_department' => $data['ticket_department'] ?? 1,
                'ticket_priority' => $data['ticket_priority'] ?? 'Medium',
                'ticket_subject_template' => $data['ticket_subject_template'],
                'ticket_message_template' => $data['ticket_message_template'],
                'auto_assign_admin' => $data['auto_assign_admin'] ?? null,
                'escalation_time' => $data['escalation_time'] ?? null, // minutes
                'max_tickets_per_day' => $data['max_tickets_per_day'] ?? 5,
                'is_active' => $data['is_active'] ?? true,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $data['created_by'] ?? 'admin'
            ];

            $ruleId = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->insertGetId($rule);

            $this->logHelper->log('support_rule_created', [
                'rule_id' => $ruleId,
                'rule_name' => $rule['rule_name'],
                'trigger_condition' => $rule['trigger_condition']
            ]);

            return [
                'success' => true,
                'rule_id' => $ruleId,
                'message' => 'Support ticket rule created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('support_rule_creation_failed', [
                'rule_data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get support ticket rules
     */
    public function getTicketRules($instanceId = null)
    {
        try {
            $query = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->leftJoin('mod_contabo_instances', 
                    'mod_contabo_support_rules.instance_id', '=', 
                    'mod_contabo_instances.contabo_instance_id'
                );

            if ($instanceId) {
                $query->where(function($q) use ($instanceId) {
                    $q->where('mod_contabo_support_rules.instance_id', $instanceId)
                      ->orWhereNull('mod_contabo_support_rules.instance_id'); // Include global rules
                });
            }

            $rules = $query
                ->select(
                    'mod_contabo_support_rules.*',
                    'mod_contabo_instances.name as instance_name'
                )
                ->orderBy('mod_contabo_support_rules.created_at', 'desc')
                ->get();

            $result = [];
            foreach ($rules as $rule) {
                $result[] = [
                    'id' => $rule->id,
                    'rule_name' => $rule->rule_name,
                    'instance_id' => $rule->instance_id,
                    'instance_name' => $rule->instance_name ?: ($rule->instance_id ? 'Unknown' : 'Global'),
                    'trigger_condition' => $rule->trigger_condition,
                    'condition_parameters' => json_decode($rule->condition_parameters, true),
                    'ticket_department' => $rule->ticket_department,
                    'ticket_priority' => $rule->ticket_priority,
                    'ticket_subject_template' => $rule->ticket_subject_template,
                    'ticket_message_template' => $rule->ticket_message_template,
                    'auto_assign_admin' => $rule->auto_assign_admin,
                    'escalation_time' => $rule->escalation_time,
                    'max_tickets_per_day' => $rule->max_tickets_per_day,
                    'is_active' => (bool)$rule->is_active,
                    'last_triggered' => $rule->last_triggered,
                    'trigger_count' => $rule->trigger_count ?? 0,
                    'created_at' => $rule->created_at
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('support_rules_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update support ticket rule
     */
    public function updateTicketRule($ruleId, $data)
    {
        try {
            $existingRule = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('id', $ruleId)
                ->first();

            if (!$existingRule) {
                throw new Exception('Support ticket rule not found');
            }

            $updateData = [
                'rule_name' => $data['rule_name'],
                'trigger_condition' => $data['trigger_condition'],
                'condition_parameters' => json_encode($data['condition_parameters'] ?? []),
                'ticket_department' => $data['ticket_department'],
                'ticket_priority' => $data['ticket_priority'],
                'ticket_subject_template' => $data['ticket_subject_template'],
                'ticket_message_template' => $data['ticket_message_template'],
                'auto_assign_admin' => $data['auto_assign_admin'],
                'escalation_time' => $data['escalation_time'],
                'max_tickets_per_day' => $data['max_tickets_per_day'],
                'is_active' => $data['is_active'] ?? $existingRule->is_active,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('id', $ruleId)
                ->update($updateData);

            $this->logHelper->log('support_rule_updated', [
                'rule_id' => $ruleId,
                'rule_name' => $updateData['rule_name']
            ]);

            return [
                'success' => true,
                'message' => 'Support ticket rule updated successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('support_rule_update_failed', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete support ticket rule
     */
    public function deleteTicketRule($ruleId)
    {
        try {
            $rule = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('id', $ruleId)
                ->first();

            if (!$rule) {
                throw new Exception('Support ticket rule not found');
            }

            \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('id', $ruleId)
                ->delete();

            // Also delete history for this rule
            \WHMCS\Database\Capsule::table('mod_contabo_support_history')
                ->where('rule_id', $ruleId)
                ->delete();

            $this->logHelper->log('support_rule_deleted', [
                'rule_id' => $ruleId,
                'rule_name' => $rule->rule_name
            ]);

            return [
                'success' => true,
                'message' => 'Support ticket rule deleted successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('support_rule_deletion_failed', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check conditions and create tickets automatically
     */
    public function checkAndCreateTickets()
    {
        try {
            $activeRules = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('is_active', 1)
                ->get();

            $results = [
                'checked' => 0,
                'triggered' => 0,
                'skipped' => 0,
                'failed' => 0
            ];

            foreach ($activeRules as $rule) {
                $results['checked']++;

                try {
                    if ($this->shouldCreateTicket($rule)) {
                        if (!$this->hasReachedDailyTicketLimit($rule)) {
                            $this->createAutomaticTicket($rule);
                            $results['triggered']++;
                        } else {
                            $results['skipped']++;
                            $this->logHelper->log('ticket_skipped_daily_limit', [
                                'rule_id' => $rule->id,
                                'rule_name' => $rule->rule_name
                            ]);
                        }
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $this->logHelper->log('automatic_ticket_failed', [
                        'rule_id' => $rule->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('ticket_check_batch_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create ticket manually
     */
    public function createManualTicket($data)
    {
        try {
            // Get service details if instance_id is provided
            $serviceId = null;
            $userId = null;

            if (!empty($data['instance_id'])) {
                $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                    ->leftJoin('tblhosting', 'mod_contabo_instances.service_id', '=', 'tblhosting.id')
                    ->where('mod_contabo_instances.contabo_instance_id', $data['instance_id'])
                    ->select('tblhosting.id as service_id', 'tblhosting.userid')
                    ->first();

                if ($instance) {
                    $serviceId = $instance->service_id;
                    $userId = $instance->userid;
                }
            }

            // Create WHMCS ticket
            $ticketResult = $this->createWHMCSTicket([
                'user_id' => $userId ?? $data['user_id'],
                'department_id' => $data['department_id'] ?? 1,
                'subject' => $data['subject'],
                'message' => $data['message'],
                'priority' => $data['priority'] ?? 'Medium',
                'service_id' => $serviceId,
                'admin_id' => $data['admin_id'] ?? null
            ]);

            // Record in history
            \WHMCS\Database\Capsule::table('mod_contabo_support_history')->insert([
                'rule_id' => null, // Manual ticket
                'instance_id' => $data['instance_id'] ?? null,
                'ticket_id' => $ticketResult['ticket_id'],
                'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'Medium',
                'department_id' => $data['department_id'] ?? 1,
                'trigger_data' => json_encode(['manual' => true, 'created_by' => $data['created_by'] ?? 'admin']),
                'status' => 'created',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $this->logHelper->log('manual_ticket_created', [
                'ticket_id' => $ticketResult['ticket_id'],
                'instance_id' => $data['instance_id'] ?? null,
                'subject' => $data['subject']
            ]);

            return [
                'success' => true,
                'ticket_id' => $ticketResult['ticket_id'],
                'message' => 'Support ticket created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('manual_ticket_creation_failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get support statistics
     */
    public function getSupportStatistics()
    {
        try {
            $stats = [
                'total_rules' => \WHMCS\Database\Capsule::table('mod_contabo_support_rules')->count(),
                'active_rules' => \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                    ->where('is_active', 1)
                    ->count(),
                'tickets_created_24h' => \WHMCS\Database\Capsule::table('mod_contabo_support_history')
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                    ->count(),
                'tickets_created_7d' => \WHMCS\Database\Capsule::table('mod_contabo_support_history')
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                    ->count()
            ];

            // Get trigger condition distribution
            $conditionStats = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->selectRaw('trigger_condition, COUNT(*) as count')
                ->groupBy('trigger_condition')
                ->pluck('count', 'trigger_condition')
                ->toArray();

            $stats['condition_distribution'] = $conditionStats;

            // Get priority distribution from recent tickets
            $priorityStats = \WHMCS\Database\Capsule::table('mod_contabo_support_history')
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->selectRaw('priority, COUNT(*) as count')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            $stats['priority_distribution'] = $priorityStats;

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('support_stats_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get support ticket history
     */
    public function getTicketHistory($instanceId = null, $limit = 50)
    {
        try {
            $query = \WHMCS\Database\Capsule::table('mod_contabo_support_history')
                ->leftJoin('mod_contabo_support_rules', 
                    'mod_contabo_support_history.rule_id', '=', 
                    'mod_contabo_support_rules.id'
                )
                ->leftJoin('mod_contabo_instances', 
                    'mod_contabo_support_history.instance_id', '=', 
                    'mod_contabo_instances.contabo_instance_id'
                )
                ->leftJoin('tbltickets', 
                    'mod_contabo_support_history.ticket_id', '=', 
                    'tbltickets.id'
                );

            if ($instanceId) {
                $query->where('mod_contabo_support_history.instance_id', $instanceId);
            }

            $history = $query
                ->select(
                    'mod_contabo_support_history.*',
                    'mod_contabo_support_rules.rule_name',
                    'mod_contabo_instances.name as instance_name',
                    'tbltickets.status as ticket_status',
                    'tbltickets.lastreply'
                )
                ->orderBy('mod_contabo_support_history.created_at', 'desc')
                ->limit($limit)
                ->get();

            $result = [];
            foreach ($history as $item) {
                $result[] = [
                    'id' => $item->id,
                    'rule_id' => $item->rule_id,
                    'rule_name' => $item->rule_name ?: 'Manual',
                    'instance_id' => $item->instance_id,
                    'instance_name' => $item->instance_name ?: ($item->instance_id ?: 'N/A'),
                    'ticket_id' => $item->ticket_id,
                    'subject' => $item->subject,
                    'priority' => $item->priority,
                    'department_id' => $item->department_id,
                    'trigger_data' => json_decode($item->trigger_data, true),
                    'status' => $item->status,
                    'ticket_status' => $item->ticket_status,
                    'created_at' => $item->created_at,
                    'last_reply' => $item->lastreply
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('ticket_history_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get available departments from WHMCS
     */
    public function getWHMCSDepartments()
    {
        try {
            $departments = \WHMCS\Database\Capsule::table('tblticketdepartments')
                ->select('id', 'name')
                ->orderBy('order', 'asc')
                ->get();

            $result = [];
            foreach ($departments as $dept) {
                $result[] = [
                    'id' => $dept->id,
                    'name' => $dept->name
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('departments_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            return []; // Return empty array if fetch fails
        }
    }

    /**
     * Get available admin users for assignment
     */
    public function getAdminUsers()
    {
        try {
            $admins = \WHMCS\Database\Capsule::table('tbladmins')
                ->select('id', 'username', 'firstname', 'lastname', 'email')
                ->where('disabled', 0)
                ->orderBy('firstname')
                ->get();

            $result = [];
            foreach ($admins as $admin) {
                $result[] = [
                    'id' => $admin->id,
                    'username' => $admin->username,
                    'name' => trim($admin->firstname . ' ' . $admin->lastname),
                    'email' => $admin->email
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('admin_users_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Test ticket creation (without actually creating)
     */
    public function testTicketCreation($ruleId)
    {
        try {
            $rule = \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('id', $ruleId)
                ->first();

            if (!$rule) {
                throw new Exception('Support ticket rule not found');
            }

            // Get test data based on rule
            $testData = $this->generateTestTriggerData($rule);

            // Generate ticket content
            $ticketContent = $this->generateTicketContent($rule, $testData);

            return [
                'success' => true,
                'rule_name' => $rule->rule_name,
                'test_subject' => $ticketContent['subject'],
                'test_message' => $ticketContent['message'],
                'test_data' => $testData,
                'would_create' => $this->shouldCreateTicket($rule, $testData)
            ];

        } catch (Exception $e) {
            $this->logHelper->log('ticket_test_failed', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate ticket rule data
     */
    private function validateTicketRule($data)
    {
        $required = ['rule_name', 'trigger_condition', 'ticket_subject_template', 'ticket_message_template'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        $validConditions = ['server_down', 'high_cpu', 'high_memory', 'disk_full', 'high_response_time', 'backup_failed', 'scaling_failed'];
        if (!in_array($data['trigger_condition'], $validConditions)) {
            throw new Exception('Invalid trigger condition');
        }

        $validPriorities = ['Low', 'Medium', 'High'];
        if (isset($data['ticket_priority']) && !in_array($data['ticket_priority'], $validPriorities)) {
            throw new Exception('Invalid ticket priority');
        }

        if (isset($data['max_tickets_per_day']) && (!is_numeric($data['max_tickets_per_day']) || $data['max_tickets_per_day'] < 1)) {
            throw new Exception('Max tickets per day must be at least 1');
        }
    }

    /**
     * Check if ticket should be created based on rule conditions
     */
    private function shouldCreateTicket($rule, $testData = null)
    {
        try {
            // If test data is provided, use it instead of checking real conditions
            if ($testData) {
                return $testData['condition_met'] ?? false;
            }

            switch ($rule->trigger_condition) {
                case 'server_down':
                    return $this->checkServerDownCondition($rule);
                    
                case 'high_cpu':
                    return $this->checkHighCPUCondition($rule);
                    
                case 'high_memory':
                    return $this->checkHighMemoryCondition($rule);
                    
                case 'disk_full':
                    return $this->checkDiskFullCondition($rule);
                    
                case 'high_response_time':
                    return $this->checkHighResponseTimeCondition($rule);
                    
                case 'backup_failed':
                    return $this->checkBackupFailedCondition($rule);
                    
                case 'scaling_failed':
                    return $this->checkScalingFailedCondition($rule);
                    
                default:
                    return false;
            }

        } catch (Exception $e) {
            $this->logHelper->log('condition_check_failed', [
                'rule_id' => $rule->id,
                'condition' => $rule->trigger_condition,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if rule has reached daily ticket limit
     */
    private function hasReachedDailyTicketLimit($rule)
    {
        $todayTickets = \WHMCS\Database\Capsule::table('mod_contabo_support_history')
            ->where('rule_id', $rule->id)
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count();

        return $todayTickets >= $rule->max_tickets_per_day;
    }

    /**
     * Create automatic ticket based on rule
     */
    private function createAutomaticTicket($rule)
    {
        try {
            // Get trigger data
            $triggerData = $this->getCurrentTriggerData($rule);

            // Generate ticket content
            $ticketContent = $this->generateTicketContent($rule, $triggerData);

            // Get service and user info
            $serviceInfo = $this->getServiceInfo($rule->instance_id);

            // Create WHMCS ticket
            $ticketResult = $this->createWHMCSTicket([
                'user_id' => $serviceInfo['user_id'] ?? null,
                'department_id' => $rule->ticket_department,
                'subject' => $ticketContent['subject'],
                'message' => $ticketContent['message'],
                'priority' => $rule->ticket_priority,
                'service_id' => $serviceInfo['service_id'] ?? null,
                'admin_id' => $rule->auto_assign_admin
            ]);

            // Record in history
            \WHMCS\Database\Capsule::table('mod_contabo_support_history')->insert([
                'rule_id' => $rule->id,
                'instance_id' => $rule->instance_id,
                'ticket_id' => $ticketResult['ticket_id'],
                'subject' => $ticketContent['subject'],
                'priority' => $rule->ticket_priority,
                'department_id' => $rule->ticket_department,
                'trigger_data' => json_encode($triggerData),
                'status' => 'created',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Update rule trigger info
            \WHMCS\Database\Capsule::table('mod_contabo_support_rules')
                ->where('id', $rule->id)
                ->update([
                    'last_triggered' => date('Y-m-d H:i:s'),
                    'trigger_count' => $rule->trigger_count + 1
                ]);

            $this->logHelper->log('automatic_ticket_created', [
                'rule_id' => $rule->id,
                'ticket_id' => $ticketResult['ticket_id'],
                'instance_id' => $rule->instance_id,
                'condition' => $rule->trigger_condition
            ]);

            return $ticketResult;

        } catch (Exception $e) {
            $this->logHelper->log('automatic_ticket_creation_failed', [
                'rule_id' => $rule->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create WHMCS ticket
     */
    private function createWHMCSTicket($data)
    {
        try {
            // Insert ticket into WHMCS
            $ticketId = \WHMCS\Database\Capsule::table('tbltickets')->insertGetId([
                'did' => $data['department_id'],
                'userid' => $data['user_id'] ?? 0,
                'name' => $data['name'] ?? 'VPS Server Alert',
                'email' => $data['email'] ?? 'system@server.local',
                'title' => $data['subject'],
                'urgency' => $data['priority'],
                'status' => 'Open',
                'lastreply' => date('Y-m-d H:i:s'),
                'flag' => $data['admin_id'] ?? 0,
                'service' => $data['service_id'] ? "VPS:{$data['service_id']}" : null,
                'date' => date('Y-m-d H:i:s')
            ]);

            // Insert initial ticket reply (message)
            \WHMCS\Database\Capsule::table('tblticketreplies')->insert([
                'tid' => $ticketId,
                'userid' => $data['user_id'] ?? 0,
                'name' => 'VPS Server Monitor',
                'email' => 'system@server.local',
                'date' => date('Y-m-d H:i:s'),
                'message' => $data['message'],
                'admin' => 1
            ]);

            return [
                'success' => true,
                'ticket_id' => $ticketId
            ];

        } catch (Exception $e) {
            $this->logHelper->log('whmcs_ticket_creation_failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate ticket content from templates
     */
    private function generateTicketContent($rule, $triggerData)
    {
        $subject = $rule->ticket_subject_template;
        $message = $rule->ticket_message_template;

        // Replace template variables
        $replacements = [
            '{instance_id}' => $rule->instance_id ?: 'Global',
            '{instance_name}' => $triggerData['instance_name'] ?? 'Unknown',
            '{condition}' => $rule->trigger_condition,
            '{metric_value}' => $triggerData['metric_value'] ?? 'N/A',
            '{threshold}' => $triggerData['threshold'] ?? 'N/A',
            '{timestamp}' => date('Y-m-d H:i:s'),
            '{server_ip}' => $triggerData['server_ip'] ?? 'N/A'
        ];

        foreach ($replacements as $placeholder => $value) {
            $subject = str_replace($placeholder, $value, $subject);
            $message = str_replace($placeholder, $value, $message);
        }

        return [
            'subject' => $subject,
            'message' => $message
        ];
    }

    /**
     * Get current trigger data for rule
     */
    private function getCurrentTriggerData($rule)
    {
        $data = [
            'rule_id' => $rule->id,
            'condition' => $rule->trigger_condition,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($rule->instance_id) {
            // Get instance info
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $rule->instance_id)
                ->first();

            if ($instance) {
                $data['instance_name'] = $instance->name;
                
                $networkConfig = json_decode($instance->network_config, true);
                $data['server_ip'] = $networkConfig['ipv4'] ?? 'N/A';

                // Get latest metrics if available
                $metrics = \WHMCS\Database\Capsule::table('mod_contabo_server_metrics')
                    ->where('instance_id', $rule->instance_id)
                    ->orderBy('timestamp', 'desc')
                    ->first();

                if ($metrics) {
                    switch ($rule->trigger_condition) {
                        case 'high_cpu':
                            $data['metric_value'] = $metrics->cpu_usage_percent . '%';
                            break;
                        case 'high_memory':
                            $data['metric_value'] = $metrics->memory_usage_percent . '%';
                            break;
                        case 'disk_full':
                            $data['metric_value'] = $metrics->disk_usage_percent . '%';
                            break;
                        case 'high_response_time':
                            $data['metric_value'] = $metrics->response_time_ms . 'ms';
                            break;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Generate test trigger data
     */
    private function generateTestTriggerData($rule)
    {
        return [
            'condition_met' => true,
            'instance_name' => 'Test Server',
            'server_ip' => '192.168.1.100',
            'metric_value' => '85%',
            'threshold' => '80%',
            'test_mode' => true
        ];
    }

    /**
     * Get service info for instance
     */
    private function getServiceInfo($instanceId)
    {
        if (!$instanceId) {
            return [];
        }

        $service = \WHMCS\Database\Capsule::table('mod_contabo_instances')
            ->leftJoin('tblhosting', 'mod_contabo_instances.service_id', '=', 'tblhosting.id')
            ->where('mod_contabo_instances.contabo_instance_id', $instanceId)
            ->select('tblhosting.id as service_id', 'tblhosting.userid as user_id')
            ->first();

        return [
            'service_id' => $service->service_id ?? null,
            'user_id' => $service->user_id ?? null
        ];
    }

    // Condition checking methods (simplified implementations)
    private function checkServerDownCondition($rule) { return false; } // Would check server status
    private function checkHighCPUCondition($rule) { return false; } // Would check CPU metrics
    private function checkHighMemoryCondition($rule) { return false; } // Would check memory metrics
    private function checkDiskFullCondition($rule) { return false; } // Would check disk usage
    private function checkHighResponseTimeCondition($rule) { return false; } // Would check response times
    private function checkBackupFailedCondition($rule) { return false; } // Would check backup status
    private function checkScalingFailedCondition($rule) { return false; } // Would check scaling status
}
