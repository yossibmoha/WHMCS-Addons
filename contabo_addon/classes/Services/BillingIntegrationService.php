<?php
/**
 * WHMCS Billing Integration Service
 * 
 * Handles automatic billing for Contabo add-ons, resource usage, and overage charges
 */

namespace ContaboAddon\Services;

use Exception;

class BillingIntegrationService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Sync all active add-ons with WHMCS billing
     */
    public function syncAllAddonBilling()
    {
        try {
            $results = [
                'processed' => 0,
                'billed' => 0,
                'errors' => 0,
                'total_amount' => 0.00
            ];

            // Process backup billing
            $backupResults = $this->processBackupBilling();
            $results['processed'] += $backupResults['processed'];
            $results['billed'] += $backupResults['billed'];
            $results['total_amount'] += $backupResults['total_amount'];

            // Process additional IP billing
            $ipResults = $this->processAdditionalIPBilling();
            $results['processed'] += $ipResults['processed'];
            $results['billed'] += $ipResults['billed'];
            $results['total_amount'] += $ipResults['total_amount'];

            // Process storage overage billing
            $storageResults = $this->processStorageOverageBilling();
            $results['processed'] += $storageResults['processed'];
            $results['billed'] += $storageResults['billed'];
            $results['total_amount'] += $storageResults['total_amount'];

            // Process network bandwidth overage
            $bandwidthResults = $this->processBandwidthOverageBilling();
            $results['processed'] += $bandwidthResults['processed'];
            $results['billed'] += $bandwidthResults['billed'];
            $results['total_amount'] += $bandwidthResults['total_amount'];

            $this->logHelper->log('billing_sync_completed', [
                'results' => $results,
                'sync_date' => date('Y-m-d H:i:s')
            ]);

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('billing_sync_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process backup billing for all instances
     */
    private function processBackupBilling()
    {
        $results = ['processed' => 0, 'billed' => 0, 'total_amount' => 0.00];

        try {
            // Get all active backup configurations
            $backupConfigs = \WHMCS\Database\Capsule::table('mod_contabo_backups')
                ->where('status', 'active')
                ->get();

            foreach ($backupConfigs as $config) {
                $results['processed']++;

                // Get backup pricing
                $pricing = $this->getBackupPricing($config);
                
                if ($pricing['monthly_cost'] > 0) {
                    // Create WHMCS billing item
                    $billingResult = $this->createWHMCSBillingItem([
                        'instance_id' => $config->instance_id,
                        'service_type' => 'backup',
                        'description' => "Automated Backups - {$config->instance_id}",
                        'amount' => $pricing['monthly_cost'],
                        'billing_period' => 'monthly',
                        'config_data' => json_decode($config->config, true)
                    ]);

                    if ($billingResult['success']) {
                        $results['billed']++;
                        $results['total_amount'] += $pricing['monthly_cost'];
                    }
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('backup_billing_failed', [
                'error' => $e->getMessage()
            ]);
            return $results;
        }
    }

    /**
     * Process additional IP address billing
     */
    private function processAdditionalIPBilling()
    {
        $results = ['processed' => 0, 'billed' => 0, 'total_amount' => 0.00];

        try {
            // Get all instances with additional IPs
            $instances = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->whereNotNull('network_config')
                ->get();

            foreach ($instances as $instance) {
                $networkConfig = json_decode($instance->network_config, true);
                $additionalIPs = $networkConfig['additional_ipv4'] ?? [];

                if (!empty($additionalIPs)) {
                    $results['processed']++;

                    $ipCount = count($additionalIPs);
                    $pricing = $this->getAdditionalIPPricing($ipCount);

                    if ($pricing['monthly_cost'] > 0) {
                        $billingResult = $this->createWHMCSBillingItem([
                            'instance_id' => $instance->contabo_instance_id,
                            'service_type' => 'additional_ip',
                            'description' => "Additional IPv4 Addresses ({$ipCount} IPs)",
                            'amount' => $pricing['monthly_cost'],
                            'billing_period' => 'monthly',
                            'config_data' => ['ip_count' => $ipCount, 'ips' => $additionalIPs]
                        ]);

                        if ($billingResult['success']) {
                            $results['billed']++;
                            $results['total_amount'] += $pricing['monthly_cost'];
                        }
                    }
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('ip_billing_failed', [
                'error' => $e->getMessage()
            ]);
            return $results;
        }
    }

    /**
     * Process storage overage billing
     */
    private function processStorageOverageBilling()
    {
        $results = ['processed' => 0, 'billed' => 0, 'total_amount' => 0.00];

        try {
            // Get current usage for all instances
            $instances = \WHMCS\Database\Capsule::table('mod_contabo_instances')->get();

            foreach ($instances as $instance) {
                $results['processed']++;

                // Get current storage usage from Contabo API
                $usageData = $this->getInstanceUsage($instance->contabo_instance_id);
                
                if ($usageData && isset($usageData['storage'])) {
                    $allocatedGB = ($instance->specs['diskMb'] ?? 0) / 1024;
                    $usedGB = $usageData['storage']['used_gb'] ?? 0;
                    $overageGB = max(0, $usedGB - $allocatedGB);

                    if ($overageGB > 0) {
                        $pricing = $this->getStorageOveragePricing($overageGB);

                        $billingResult = $this->createWHMCSBillingItem([
                            'instance_id' => $instance->contabo_instance_id,
                            'service_type' => 'storage_overage',
                            'description' => "Storage Overage - {$overageGB}GB",
                            'amount' => $pricing['monthly_cost'],
                            'billing_period' => 'monthly',
                            'config_data' => ['overage_gb' => $overageGB, 'rate_per_gb' => $pricing['rate_per_gb']]
                        ]);

                        if ($billingResult['success']) {
                            $results['billed']++;
                            $results['total_amount'] += $pricing['monthly_cost'];
                        }
                    }
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('storage_overage_billing_failed', [
                'error' => $e->getMessage()
            ]);
            return $results;
        }
    }

    /**
     * Process bandwidth overage billing
     */
    private function processBandwidthOverageBilling()
    {
        $results = ['processed' => 0, 'billed' => 0, 'total_amount' => 0.00];

        try {
            $instances = \WHMCS\Database\Capsule::table('mod_contabo_instances')->get();

            foreach ($instances as $instance) {
                $results['processed']++;

                $usageData = $this->getInstanceUsage($instance->contabo_instance_id);
                
                if ($usageData && isset($usageData['bandwidth'])) {
                    $includedGB = $usageData['bandwidth']['included_gb'] ?? 1000; // Default 1TB
                    $usedGB = $usageData['bandwidth']['used_gb'] ?? 0;
                    $overageGB = max(0, $usedGB - $includedGB);

                    if ($overageGB > 0) {
                        $pricing = $this->getBandwidthOveragePricing($overageGB);

                        $billingResult = $this->createWHMCSBillingItem([
                            'instance_id' => $instance->contabo_instance_id,
                            'service_type' => 'bandwidth_overage',
                            'description' => "Bandwidth Overage - {$overageGB}GB",
                            'amount' => $pricing['monthly_cost'],
                            'billing_period' => 'monthly',
                            'config_data' => ['overage_gb' => $overageGB, 'rate_per_gb' => $pricing['rate_per_gb']]
                        ]);

                        if ($billingResult['success']) {
                            $results['billed']++;
                            $results['total_amount'] += $pricing['monthly_cost'];
                        }
                    }
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->logHelper->log('bandwidth_overage_billing_failed', [
                'error' => $e->getMessage()
            ]);
            return $results;
        }
    }

    /**
     * Create WHMCS billing item
     */
    private function createWHMCSBillingItem($itemData)
    {
        try {
            // Get service ID for the instance
            $instance = \WHMCS\Database\Capsule::table('mod_contabo_instances')
                ->where('contabo_instance_id', $itemData['instance_id'])
                ->first();

            if (!$instance) {
                throw new Exception('Instance not found in WHMCS');
            }

            // Check if this billing item already exists for this period
            $existingItem = \WHMCS\Database\Capsule::table('mod_contabo_billing_items')
                ->where('instance_id', $itemData['instance_id'])
                ->where('service_type', $itemData['service_type'])
                ->where('billing_month', date('Y-m'))
                ->first();

            if ($existingItem) {
                // Update existing item
                \WHMCS\Database\Capsule::table('mod_contabo_billing_items')
                    ->where('id', $existingItem->id)
                    ->update([
                        'amount' => $itemData['amount'],
                        'config_data' => json_encode($itemData['config_data']),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                $billingItemId = $existingItem->id;
            } else {
                // Create new billing item
                $billingItemId = \WHMCS\Database\Capsule::table('mod_contabo_billing_items')->insertGetId([
                    'service_id' => $instance->service_id,
                    'instance_id' => $itemData['instance_id'],
                    'service_type' => $itemData['service_type'],
                    'description' => $itemData['description'],
                    'amount' => $itemData['amount'],
                    'billing_period' => $itemData['billing_period'],
                    'billing_month' => date('Y-m'),
                    'config_data' => json_encode($itemData['config_data']),
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }

            // Create WHMCS invoice item
            $invoiceResult = $this->createWHMCSInvoiceItem($instance->service_id, $itemData);

            return [
                'success' => true,
                'billing_item_id' => $billingItemId,
                'invoice_item_id' => $invoiceResult['invoice_item_id'] ?? null
            ];

        } catch (Exception $e) {
            $this->logHelper->log('billing_item_creation_failed', [
                'item_data' => $itemData,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create WHMCS invoice item for billing
     */
    private function createWHMCSInvoiceItem($serviceId, $itemData)
    {
        try {
            // Get the WHMCS service
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->first();

            if (!$service) {
                throw new Exception('WHMCS service not found');
            }

            // Get or create current invoice for this client
            $invoice = $this->getOrCreateMonthlyInvoice($service->userid);

            // Add item to invoice
            $invoiceItemId = \WHMCS\Database\Capsule::table('tblinvoiceitems')->insertGetId([
                'invoiceid' => $invoice['invoice_id'],
                'userid' => $service->userid,
                'type' => 'addon',
                'relid' => $serviceId,
                'description' => $itemData['description'],
                'amount' => $itemData['amount'],
                'taxed' => 1,
                'duedate' => date('Y-m-d'),
                'paymentmethod' => $service->paymentmethod
            ]);

            // Update invoice totals
            $this->updateInvoiceTotals($invoice['invoice_id']);

            return [
                'success' => true,
                'invoice_item_id' => $invoiceItemId,
                'invoice_id' => $invoice['invoice_id']
            ];

        } catch (Exception $e) {
            throw new Exception('Invoice creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get or create monthly invoice for client
     */
    private function getOrCreateMonthlyInvoice($userId)
    {
        try {
            $currentMonth = date('Y-m');
            
            // Look for existing invoice this month
            $existingInvoice = \WHMCS\Database\Capsule::table('tblinvoices')
                ->where('userid', $userId)
                ->where('date', 'like', $currentMonth . '%')
                ->where('status', 'Unpaid')
                ->first();

            if ($existingInvoice) {
                return ['invoice_id' => $existingInvoice->id, 'created' => false];
            }

            // Create new invoice
            $invoiceId = \WHMCS\Database\Capsule::table('tblinvoices')->insertGetId([
                'userid' => $userId,
                'date' => date('Y-m-d'),
                'duedate' => date('Y-m-d', strtotime('+30 days')),
                'datepaid' => '0000-00-00 00:00:00',
                'subtotal' => '0.00',
                'credit' => '0.00',
                'tax' => '0.00',
                'tax2' => '0.00',
                'total' => '0.00',
                'balance' => '0.00',
                'taxrate' => '0.00',
                'taxrate2' => '0.00',
                'status' => 'Unpaid',
                'paymentmethod' => '',
                'notes' => 'VPS Server Add-ons & Usage Charges'
            ]);

            return ['invoice_id' => $invoiceId, 'created' => true];

        } catch (Exception $e) {
            throw new Exception('Invoice creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Update invoice totals after adding items
     */
    private function updateInvoiceTotals($invoiceId)
    {
        try {
            // Calculate totals from invoice items
            $totals = \WHMCS\Database\Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->selectRaw('SUM(amount) as subtotal')
                ->first();

            $subtotal = $totals->subtotal ?? 0;
            $tax = $subtotal * 0.1; // 10% tax rate - adjust as needed
            $total = $subtotal + $tax;

            // Update invoice
            \WHMCS\Database\Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->update([
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'tax' => number_format($tax, 2, '.', ''),
                    'total' => number_format($total, 2, '.', ''),
                    'balance' => number_format($total, 2, '.', '')
                ]);

        } catch (Exception $e) {
            $this->logHelper->log('invoice_total_update_failed', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get backup pricing based on configuration
     */
    private function getBackupPricing($config)
    {
        $configData = json_decode($config->config, true);
        $retentionDays = $configData['retention_days'] ?? 7;

        $pricingTiers = [
            7 => 4.99,   // 7 days - €4.99/month
            14 => 8.99,  // 14 days - €8.99/month  
            30 => 14.99, // 30 days - €14.99/month
            60 => 19.99  // 60 days - €19.99/month
        ];

        // Find the appropriate pricing tier
        $monthlyRate = $pricingTiers[7]; // Default to lowest tier
        foreach ($pricingTiers as $days => $rate) {
            if ($retentionDays <= $days) {
                $monthlyRate = $rate;
                break;
            }
        }

        return [
            'monthly_cost' => $monthlyRate,
            'retention_days' => $retentionDays,
            'tier' => array_search($monthlyRate, $pricingTiers)
        ];
    }

    /**
     * Get additional IP pricing
     */
    private function getAdditionalIPPricing($ipCount)
    {
        $baseRate = 2.99; // €2.99 per additional IP per month
        $monthlyRate = $ipCount * $baseRate;

        // Volume discounts
        if ($ipCount >= 10) {
            $monthlyRate *= 0.85; // 15% discount for 10+ IPs
        } elseif ($ipCount >= 5) {
            $monthlyRate *= 0.90; // 10% discount for 5+ IPs
        }

        return [
            'monthly_cost' => round($monthlyRate, 2),
            'per_ip_rate' => $baseRate,
            'ip_count' => $ipCount,
            'discount_applied' => $ipCount >= 5
        ];
    }

    /**
     * Get storage overage pricing
     */
    private function getStorageOveragePricing($overageGB)
    {
        $ratePerGB = 0.10; // €0.10 per GB overage per month
        $monthlyRate = $overageGB * $ratePerGB;

        return [
            'monthly_cost' => round($monthlyRate, 2),
            'rate_per_gb' => $ratePerGB,
            'overage_gb' => $overageGB
        ];
    }

    /**
     * Get bandwidth overage pricing
     */
    private function getBandwidthOveragePricing($overageGB)
    {
        $ratePerGB = 0.05; // €0.05 per GB bandwidth overage
        $monthlyRate = $overageGB * $ratePerGB;

        return [
            'monthly_cost' => round($monthlyRate, 2),
            'rate_per_gb' => $ratePerGB,
            'overage_gb' => $overageGB
        ];
    }

    /**
     * Get instance usage data from Contabo API
     */
    private function getInstanceUsage($instanceId)
    {
        try {
            // This would typically call a Contabo usage API endpoint
            // For now, return mock data structure
            return [
                'storage' => [
                    'used_gb' => rand(50, 200),
                    'allocated_gb' => 100
                ],
                'bandwidth' => [
                    'used_gb' => rand(800, 1200),
                    'included_gb' => 1000
                ]
            ];

        } catch (Exception $e) {
            $this->logHelper->log('usage_data_fetch_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get billing statistics
     */
    public function getBillingStatistics($period = 'current_month')
    {
        try {
            $monthFilter = '';
            switch ($period) {
                case 'current_month':
                    $monthFilter = date('Y-m');
                    break;
                case 'last_month':
                    $monthFilter = date('Y-m', strtotime('-1 month'));
                    break;
                case 'current_year':
                    $monthFilter = date('Y') . '%';
                    break;
            }

            $query = \WHMCS\Database\Capsule::table('mod_contabo_billing_items')
                ->selectRaw('
                    service_type,
                    COUNT(*) as item_count,
                    SUM(amount) as total_amount,
                    AVG(amount) as average_amount
                ')
                ->groupBy('service_type');

            if ($monthFilter) {
                if (strpos($monthFilter, '%') !== false) {
                    $query->where('billing_month', 'like', $monthFilter);
                } else {
                    $query->where('billing_month', $monthFilter);
                }
            }

            $results = $query->get();

            $stats = [
                'period' => $period,
                'total_revenue' => 0,
                'total_items' => 0,
                'breakdown' => []
            ];

            foreach ($results as $result) {
                $stats['total_revenue'] += $result->total_amount;
                $stats['total_items'] += $result->item_count;
                
                $stats['breakdown'][$result->service_type] = [
                    'count' => $result->item_count,
                    'total_amount' => round($result->total_amount, 2),
                    'average_amount' => round($result->average_amount, 2)
                ];
            }

            $stats['total_revenue'] = round($stats['total_revenue'], 2);

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('billing_stats_failed', [
                'period' => $period,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
