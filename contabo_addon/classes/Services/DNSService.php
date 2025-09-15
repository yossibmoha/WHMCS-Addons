<?php
/**
 * DNS Management Service
 * 
 * Handles DNS zone and record management for domains
 */

namespace ContaboAddon\Services;

use Exception;

class DNSService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get all DNS zones
     */
    public function getDNSZones()
    {
        try {
            // Note: Contabo API doesn't directly provide DNS management
            // This implementation simulates DNS management through local database
            $zones = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->orderBy('domain_name', 'asc')
                ->get();

            $result = [];
            foreach ($zones as $zone) {
                $recordsCount = \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                    ->where('zone_id', $zone->id)
                    ->count();

                $result[] = [
                    'id' => $zone->id,
                    'domain_name' => $zone->domain_name,
                    'status' => $zone->status,
                    'records_count' => $recordsCount,
                    'nameservers' => json_decode($zone->nameservers, true) ?: [],
                    'created_at' => $zone->created_at,
                    'updated_at' => $zone->updated_at
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('dns_zones_fetch_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create DNS zone
     */
    public function createDNSZone($domainName, $options = [])
    {
        try {
            // Validate domain name
            if (!$this->isValidDomainName($domainName)) {
                throw new Exception('Invalid domain name format');
            }

            // Check if zone already exists
            $existingZone = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('domain_name', $domainName)
                ->first();

            if ($existingZone) {
                throw new Exception('DNS zone already exists for this domain');
            }

            // Default nameservers
            $defaultNameservers = [
                'ns1.contabo.net',
                'ns2.contabo.net',
                'ns3.contabo.net'
            ];

            $zoneData = [
                'domain_name' => $domainName,
                'status' => 'active',
                'nameservers' => json_encode($options['nameservers'] ?? $defaultNameservers),
                'ttl' => $options['ttl'] ?? 3600,
                'refresh' => $options['refresh'] ?? 3600,
                'retry' => $options['retry'] ?? 1800,
                'expire' => $options['expire'] => 1209600,
                'minimum' => $options['minimum'] ?? 300,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $zoneId = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->insertGetId($zoneData);

            // Create default DNS records
            $this->createDefaultDNSRecords($zoneId, $domainName, $options);

            $this->logHelper->log('dns_zone_created', [
                'zone_id' => $zoneId,
                'domain_name' => $domainName
            ]);

            return [
                'success' => true,
                'zone_id' => $zoneId,
                'domain_name' => $domainName,
                'message' => 'DNS zone created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_zone_creation_failed', [
                'domain_name' => $domainName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get DNS records for a zone
     */
    public function getDNSRecords($zoneId)
    {
        try {
            $zone = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $zoneId)
                ->first();

            if (!$zone) {
                throw new Exception('DNS zone not found');
            }

            $records = \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->where('zone_id', $zoneId)
                ->orderBy('type')
                ->orderBy('name')
                ->get();

            $result = [
                'zone_id' => $zoneId,
                'domain_name' => $zone->domain_name,
                'records' => []
            ];

            foreach ($records as $record) {
                $result['records'][] = [
                    'id' => $record->id,
                    'name' => $record->name,
                    'type' => $record->type,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'priority' => $record->priority,
                    'disabled' => (bool)$record->disabled,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at
                ];
            }

            return $result;

        } catch (Exception $e) {
            $this->logHelper->log('dns_records_fetch_failed', [
                'zone_id' => $zoneId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create DNS record
     */
    public function createDNSRecord($zoneId, $recordData)
    {
        try {
            $zone = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $zoneId)
                ->first();

            if (!$zone) {
                throw new Exception('DNS zone not found');
            }

            // Validate record data
            $this->validateDNSRecord($recordData);

            $record = [
                'zone_id' => $zoneId,
                'name' => $recordData['name'],
                'type' => strtoupper($recordData['type']),
                'content' => $recordData['content'],
                'ttl' => $recordData['ttl'] ?? 3600,
                'priority' => $recordData['priority'] ?? null,
                'disabled' => $recordData['disabled'] ?? false,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Special handling for different record types
            if ($record['type'] === 'MX' && empty($record['priority'])) {
                $record['priority'] = 10;
            }

            $recordId = \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->insertGetId($record);

            // Update zone's updated_at timestamp
            \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $zoneId)
                ->update(['updated_at' => date('Y-m-d H:i:s')]);

            $this->logHelper->log('dns_record_created', [
                'record_id' => $recordId,
                'zone_id' => $zoneId,
                'type' => $record['type'],
                'name' => $record['name']
            ]);

            return [
                'success' => true,
                'record_id' => $recordId,
                'message' => 'DNS record created successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_record_creation_failed', [
                'zone_id' => $zoneId,
                'record_data' => $recordData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update DNS record
     */
    public function updateDNSRecord($recordId, $recordData)
    {
        try {
            $existingRecord = \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->where('id', $recordId)
                ->first();

            if (!$existingRecord) {
                throw new Exception('DNS record not found');
            }

            // Validate record data
            $this->validateDNSRecord($recordData);

            $updateData = [
                'name' => $recordData['name'],
                'type' => strtoupper($recordData['type']),
                'content' => $recordData['content'],
                'ttl' => $recordData['ttl'] ?? $existingRecord->ttl,
                'priority' => $recordData['priority'] ?? $existingRecord->priority,
                'disabled' => $recordData['disabled'] ?? $existingRecord->disabled,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->where('id', $recordId)
                ->update($updateData);

            // Update zone's updated_at timestamp
            \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $existingRecord->zone_id)
                ->update(['updated_at' => date('Y-m-d H:i:s')]);

            $this->logHelper->log('dns_record_updated', [
                'record_id' => $recordId,
                'zone_id' => $existingRecord->zone_id,
                'type' => $updateData['type'],
                'name' => $updateData['name']
            ]);

            return [
                'success' => true,
                'message' => 'DNS record updated successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_record_update_failed', [
                'record_id' => $recordId,
                'record_data' => $recordData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete DNS record
     */
    public function deleteDNSRecord($recordId)
    {
        try {
            $record = \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->where('id', $recordId)
                ->first();

            if (!$record) {
                throw new Exception('DNS record not found');
            }

            \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->where('id', $recordId)
                ->delete();

            // Update zone's updated_at timestamp
            \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $record->zone_id)
                ->update(['updated_at' => date('Y-m-d H:i:s')]);

            $this->logHelper->log('dns_record_deleted', [
                'record_id' => $recordId,
                'zone_id' => $record->zone_id,
                'type' => $record->type,
                'name' => $record->name
            ]);

            return [
                'success' => true,
                'message' => 'DNS record deleted successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_record_deletion_failed', [
                'record_id' => $recordId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete DNS zone
     */
    public function deleteDNSZone($zoneId)
    {
        try {
            $zone = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $zoneId)
                ->first();

            if (!$zone) {
                throw new Exception('DNS zone not found');
            }

            // Delete all records in the zone first
            \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->where('zone_id', $zoneId)
                ->delete();

            // Delete the zone
            \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $zoneId)
                ->delete();

            $this->logHelper->log('dns_zone_deleted', [
                'zone_id' => $zoneId,
                'domain_name' => $zone->domain_name
            ]);

            return [
                'success' => true,
                'message' => 'DNS zone and all records deleted successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_zone_deletion_failed', [
                'zone_id' => $zoneId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Import DNS zone from file
     */
    public function importDNSZone($domainName, $zoneFileContent)
    {
        try {
            // Create the DNS zone first
            $result = $this->createDNSZone($domainName);
            $zoneId = $result['zone_id'];

            // Parse zone file content
            $records = $this->parseZoneFile($zoneFileContent, $domainName);

            $importedCount = 0;
            $failedCount = 0;

            foreach ($records as $recordData) {
                try {
                    $this->createDNSRecord($zoneId, $recordData);
                    $importedCount++;
                } catch (Exception $e) {
                    $failedCount++;
                    $this->logHelper->log('dns_import_record_failed', [
                        'zone_id' => $zoneId,
                        'record_data' => $recordData,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logHelper->log('dns_zone_imported', [
                'zone_id' => $zoneId,
                'domain_name' => $domainName,
                'imported_records' => $importedCount,
                'failed_records' => $failedCount
            ]);

            return [
                'success' => true,
                'zone_id' => $zoneId,
                'imported_records' => $importedCount,
                'failed_records' => $failedCount,
                'message' => "DNS zone imported successfully: {$importedCount} records imported, {$failedCount} failed"
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_zone_import_failed', [
                'domain_name' => $domainName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Export DNS zone to file format
     */
    public function exportDNSZone($zoneId)
    {
        try {
            $zoneData = $this->getDNSRecords($zoneId);
            $zone = \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                ->where('id', $zoneId)
                ->first();

            if (!$zone) {
                throw new Exception('DNS zone not found');
            }

            $zoneFile = $this->generateZoneFile($zoneData, $zone);

            $this->logHelper->log('dns_zone_exported', [
                'zone_id' => $zoneId,
                'domain_name' => $zone->domain_name
            ]);

            return [
                'success' => true,
                'zone_file' => $zoneFile,
                'filename' => $zone->domain_name . '.zone'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_zone_export_failed', [
                'zone_id' => $zoneId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get DNS statistics
     */
    public function getDNSStatistics()
    {
        try {
            $stats = [
                'total_zones' => \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')->count(),
                'active_zones' => \WHMCS\Database\Capsule::table('mod_contabo_dns_zones')
                    ->where('status', 'active')
                    ->count(),
                'total_records' => \WHMCS\Database\Capsule::table('mod_contabo_dns_records')->count(),
                'disabled_records' => \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                    ->where('disabled', 1)
                    ->count()
            ];

            // Get record type distribution
            $recordTypes = \WHMCS\Database\Capsule::table('mod_contabo_dns_records')
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray();

            $stats['record_types'] = $recordTypes;

            return $stats;

        } catch (Exception $e) {
            $this->logHelper->log('dns_stats_failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check DNS propagation
     */
    public function checkDNSPropagation($domainName, $recordType = 'A')
    {
        try {
            $nameservers = [
                '8.8.8.8' => 'Google',
                '1.1.1.1' => 'Cloudflare',
                '208.67.222.222' => 'OpenDNS',
                '4.2.2.2' => 'Level3'
            ];

            $results = [];

            foreach ($nameservers as $server => $provider) {
                $result = [
                    'nameserver' => $server,
                    'provider' => $provider,
                    'records' => [],
                    'query_time' => null,
                    'status' => 'failed'
                ];

                try {
                    $startTime = microtime(true);
                    
                    // Use system DNS lookup (simplified)
                    if ($recordType === 'A') {
                        $records = gethostbynamel($domainName);
                        if ($records) {
                            $result['records'] = $records;
                            $result['status'] = 'success';
                        }
                    }
                    
                    $endTime = microtime(true);
                    $result['query_time'] = round(($endTime - $startTime) * 1000, 2);
                    
                } catch (Exception $e) {
                    $result['error'] = $e->getMessage();
                }

                $results[] = $result;
            }

            // Calculate propagation percentage
            $successfulQueries = array_filter($results, function($r) { return $r['status'] === 'success'; });
            $propagationPercent = round((count($successfulQueries) / count($results)) * 100);

            return [
                'domain_name' => $domainName,
                'record_type' => $recordType,
                'propagation_percent' => $propagationPercent,
                'results' => $results,
                'checked_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logHelper->log('dns_propagation_check_failed', [
                'domain_name' => $domainName,
                'record_type' => $recordType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate domain name
     */
    private function isValidDomainName($domainName)
    {
        return filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Validate DNS record
     */
    private function validateDNSRecord($recordData)
    {
        $requiredFields = ['name', 'type', 'content'];
        foreach ($requiredFields as $field) {
            if (empty($recordData[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        $validTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'PTR', 'SRV', 'SOA'];
        if (!in_array(strtoupper($recordData['type']), $validTypes)) {
            throw new Exception('Invalid DNS record type');
        }

        // Type-specific validation
        switch (strtoupper($recordData['type'])) {
            case 'A':
                if (!filter_var($recordData['content'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new Exception('Invalid IPv4 address for A record');
                }
                break;
            case 'AAAA':
                if (!filter_var($recordData['content'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new Exception('Invalid IPv6 address for AAAA record');
                }
                break;
            case 'MX':
                if (empty($recordData['priority']) || !is_numeric($recordData['priority'])) {
                    throw new Exception('MX record requires valid priority');
                }
                break;
        }

        if (isset($recordData['ttl']) && (!is_numeric($recordData['ttl']) || $recordData['ttl'] < 60)) {
            throw new Exception('TTL must be numeric and at least 60 seconds');
        }
    }

    /**
     * Create default DNS records for new zone
     */
    private function createDefaultDNSRecords($zoneId, $domainName, $options = [])
    {
        $defaultRecords = [
            [
                'name' => $domainName,
                'type' => 'SOA',
                'content' => 'ns1.contabo.net. admin.' . $domainName . '. 1 3600 1800 1209600 300',
                'ttl' => 3600
            ],
            [
                'name' => $domainName,
                'type' => 'NS',
                'content' => 'ns1.contabo.net.',
                'ttl' => 3600
            ],
            [
                'name' => $domainName,
                'type' => 'NS',
                'content' => 'ns2.contabo.net.',
                'ttl' => 3600
            ]
        ];

        // Add A record if IP is provided
        if (!empty($options['ip_address'])) {
            $defaultRecords[] = [
                'name' => $domainName,
                'type' => 'A',
                'content' => $options['ip_address'],
                'ttl' => 3600
            ];

            // Add www A record
            $defaultRecords[] = [
                'name' => 'www.' . $domainName,
                'type' => 'A',
                'content' => $options['ip_address'],
                'ttl' => 3600
            ];
        }

        foreach ($defaultRecords as $recordData) {
            try {
                \WHMCS\Database\Capsule::table('mod_contabo_dns_records')->insert([
                    'zone_id' => $zoneId,
                    'name' => $recordData['name'],
                    'type' => $recordData['type'],
                    'content' => $recordData['content'],
                    'ttl' => $recordData['ttl'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                // Log but don't fail the zone creation
                $this->logHelper->log('default_record_creation_failed', [
                    'zone_id' => $zoneId,
                    'record_data' => $recordData,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Parse zone file content
     */
    private function parseZoneFile($content, $domainName)
    {
        $records = [];
        $lines = explode("\n", $content);
        $currentOrigin = $domainName . '.';

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || $line[0] === ';') {
                continue;
            }

            // Handle $ORIGIN directive
            if (strpos($line, '$ORIGIN') === 0) {
                $parts = explode(' ', $line);
                $currentOrigin = $parts[1];
                continue;
            }

            // Parse DNS records (simplified)
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                $records[] = [
                    'name' => $parts[0] === '@' ? $domainName : $parts[0],
                    'ttl' => is_numeric($parts[1]) ? $parts[1] : 3600,
                    'type' => $parts[count($parts) >= 5 ? 3 : 2],
                    'content' => implode(' ', array_slice($parts, count($parts) >= 5 ? 4 : 3))
                ];
            }
        }

        return $records;
    }

    /**
     * Generate zone file content
     */
    private function generateZoneFile($zoneData, $zone)
    {
        $content = ";; DNS Zone file for {$zone->domain_name}\n";
        $content .= ";; Generated on " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "\$TTL {$zone->ttl}\n";
        $content .= "\$ORIGIN {$zone->domain_name}.\n\n";

        foreach ($zoneData['records'] as $record) {
            $name = $record['name'] === $zone->domain_name ? '@' : $record['name'];
            $content .= sprintf("%-30s %d IN %-6s %s\n",
                $name,
                $record['ttl'],
                $record['type'],
                $record['content']
            );
        }

        return $content;
    }
}
