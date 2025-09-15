<?php
/**
 * Contabo Object Storage Service
 * 
 * Handles S3-compatible object storage management, credentials, and statistics
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class ObjectStorageService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Create a new object storage
     */
    public function createObjectStorage($data)
    {
        try {
            // Validate required fields
            $requiredFields = ['region', 'totalPurchasedSpaceInGb'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }

            // Prepare storage data
            $storageData = [
                'region' => $data['region'],
                'totalPurchasedSpaceInGb' => (int)$data['totalPurchasedSpaceInGb'],
                'displayName' => $data['displayName'] ?? 'WHMCS Object Storage'
            ];

            // Add auto-scaling configuration if provided
            if (!empty($data['autoScaling'])) {
                $storageData['autoScaling'] = [
                    'state' => $data['autoScaling']['state'] ?? 'enabled',
                    'sizeLimitTb' => $data['autoScaling']['sizeLimitTb'] ?? 1,
                    'errorEmailsEnabled' => $data['autoScaling']['errorEmailsEnabled'] ?? true,
                    'usageThreshold' => $data['autoScaling']['usageThreshold'] ?? [
                        'thresholdTb' => 0.8,
                        'emailsEnabled' => true
                    ]
                ];
            }

            // Create object storage via API
            $response = $this->apiClient->createObjectStorage($storageData);

            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('Invalid response from Contabo API');
            }

            $storage = $response['data'][0];

            // Store storage in local database
            $storageId = Capsule::table('mod_contabo_object_storages')->insertGetId([
                'service_id' => $data['service_id'],
                'contabo_storage_id' => $storage['objectStorageId'],
                'name' => $storage['displayName'] ?? $storageData['displayName'],
                'status' => $storage['status'] ?? 'provisioning',
                'region' => $storage['region'],
                'size_gb' => $storage['totalPurchasedSpaceInGb'],
                'auto_scaling' => !empty($storageData['autoScaling']),
                'auto_scaling_max_size_gb' => !empty($storageData['autoScaling']) ? $storageData['autoScaling']['sizeLimitTb'] * 1024 : null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->logHelper->log('object_storage_created', [
                'service_id' => $data['service_id'],
                'contabo_storage_id' => $storage['objectStorageId'],
                'local_id' => $storageId
            ]);

            return [
                'success' => true,
                'storageId' => $storage['objectStorageId'],
                'localId' => $storageId,
                'data' => $storage
            ];

        } catch (Exception $e) {
            $this->logHelper->log('object_storage_creation_failed', [
                'service_id' => $data['service_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get object storage details
     */
    public function getObjectStorage($storageId, $useContaboId = true)
    {
        try {
            if ($useContaboId) {
                // Get from Contabo API
                $response = $this->apiClient->getObjectStorage($storageId);
                $storage = $response['data'][0] ?? null;
                
                if (!$storage) {
                    throw new Exception('Object storage not found');
                }

                // Update local database
                $this->updateLocalStorage($storageId, $storage);

                return $storage;
            } else {
                // Get from local database
                $localStorage = Capsule::table('mod_contabo_object_storages')
                    ->where('id', $storageId)
                    ->first();

                if (!$localStorage) {
                    throw new Exception('Object storage not found in local database');
                }

                // Get fresh data from API
                return $this->getObjectStorage($localStorage->contabo_storage_id, true);
            }

        } catch (Exception $e) {
            $this->logHelper->log('object_storage_fetch_failed', [
                'storage_id' => $storageId,
                'use_contabo_id' => $useContaboId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Resize object storage
     */
    public function resizeObjectStorage($storageId, $newSizeGb)
    {
        try {
            $resizeData = [
                'totalPurchasedSpaceInGb' => (int)$newSizeGb
            ];

            $response = $this->apiClient->resizeObjectStorage($storageId, $resizeData);

            // Update local database
            Capsule::table('mod_contabo_object_storages')
                ->where('contabo_storage_id', $storageId)
                ->update([
                    'size_gb' => $newSizeGb,
                    'updated_at' => now()
                ]);

            $this->logHelper->log('object_storage_resized', [
                'storage_id' => $storageId,
                'new_size_gb' => $newSizeGb
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('object_storage_resize_failed', [
                'storage_id' => $storageId,
                'new_size_gb' => $newSizeGb,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cancel object storage
     */
    public function cancelObjectStorage($storageId)
    {
        try {
            $response = $this->apiClient->cancelObjectStorage($storageId);

            // Update status in local database
            Capsule::table('mod_contabo_object_storages')
                ->where('contabo_storage_id', $storageId)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now()
                ]);

            $this->logHelper->log('object_storage_cancelled', [
                'storage_id' => $storageId
            ]);

            return $response;

        } catch (Exception $e) {
            $this->logHelper->log('object_storage_cancellation_failed', [
                'storage_id' => $storageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get object storage statistics
     */
    public function getStorageStats($storageId)
    {
        try {
            $response = $this->apiClient->getObjectStorageStats($storageId);
            return $response['data'] ?? [];

        } catch (Exception $e) {
            $this->logHelper->log('object_storage_stats_failed', [
                'storage_id' => $storageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get or create S3 credentials for a user
     */
    public function getS3Credentials($userId, $storageId = null)
    {
        try {
            // Check if we have cached credentials
            $localStorage = null;
            if ($storageId) {
                $localStorage = Capsule::table('mod_contabo_object_storages')
                    ->where('contabo_storage_id', $storageId)
                    ->first();

                if ($localStorage && $localStorage->access_keys) {
                    $cachedKeys = json_decode($localStorage->access_keys, true);
                    if ($cachedKeys && isset($cachedKeys['accessKey'])) {
                        return $cachedKeys;
                    }
                }
            }

            // Get credentials from Contabo API
            $response = $this->apiClient->getUserObjectStorageCredentials($userId);
            
            if (!isset($response['data']) || empty($response['data'])) {
                // Create new credentials if none exist
                $response = $this->apiClient->createUserObjectStorageCredentials($userId, [
                    'displayName' => 'WHMCS Generated Key'
                ]);
            }

            $credentials = $response['data'][0] ?? null;
            
            if (!$credentials) {
                throw new Exception('Failed to get or create S3 credentials');
            }

            $credentialData = [
                'accessKey' => $credentials['accessKey'],
                'secretKey' => $credentials['secretKey'],
                'region' => $localStorage->region ?? 'eu-central-1',
                'endpoint' => $this->getS3Endpoint($localStorage->region ?? 'eu-central-1')
            ];

            // Cache credentials if we have a specific storage
            if ($localStorage) {
                Capsule::table('mod_contabo_object_storages')
                    ->where('id', $localStorage->id)
                    ->update([
                        'access_keys' => json_encode($credentialData),
                        'updated_at' => now()
                    ]);
            }

            $this->logHelper->log('s3_credentials_retrieved', [
                'user_id' => $userId,
                'storage_id' => $storageId
            ]);

            return $credentialData;

        } catch (Exception $e) {
            $this->logHelper->log('s3_credentials_failed', [
                'user_id' => $userId,
                'storage_id' => $storageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate S3 connection examples
     */
    public function generateS3Examples($credentials, $bucketName = 'my-bucket')
    {
        $examples = [
            'aws_cli' => [
                'title' => 'AWS CLI Configuration',
                'commands' => [
                    'aws configure set aws_access_key_id ' . $credentials['accessKey'],
                    'aws configure set aws_secret_access_key ' . $credentials['secretKey'],
                    'aws configure set default.region ' . $credentials['region'],
                    'aws configure set default.s3.endpoint_url ' . $credentials['endpoint']
                ]
            ],
            'aws_cli_usage' => [
                'title' => 'AWS CLI Usage Examples',
                'commands' => [
                    "aws s3 mb s3://{$bucketName}",
                    "aws s3 cp file.txt s3://{$bucketName}/",
                    "aws s3 ls s3://{$bucketName}/",
                    "aws s3 sync ./local-folder s3://{$bucketName}/remote-folder"
                ]
            ],
            's3cmd' => [
                'title' => 's3cmd Configuration',
                'config' => [
                    'access_key = ' . $credentials['accessKey'],
                    'secret_key = ' . $credentials['secretKey'],
                    'host_base = ' . str_replace(['https://', 'http://'], '', $credentials['endpoint']),
                    'host_bucket = %(bucket)s.' . str_replace(['https://', 'http://'], '', $credentials['endpoint']),
                    'use_https = True'
                ]
            ],
            'php_sdk' => [
                'title' => 'PHP AWS SDK Example',
                'code' => '<?php
require "vendor/autoload.php";

use Aws\S3\S3Client;

$client = new S3Client([
    "version" => "latest",
    "region" => "' . $credentials['region'] . '",
    "endpoint" => "' . $credentials['endpoint'] . '",
    "credentials" => [
        "key" => "' . $credentials['accessKey'] . '",
        "secret" => "' . $credentials['secretKey'] . '"
    ],
    "use_path_style_endpoint" => true
]);

// Create bucket
$client->createBucket(["Bucket" => "' . $bucketName . '"]);

// Upload file
$client->putObject([
    "Bucket" => "' . $bucketName . '",
    "Key" => "test.txt",
    "Body" => "Hello World!"
]);'
            ],
            'python_boto3' => [
                'title' => 'Python Boto3 Example',
                'code' => 'import boto3

client = boto3.client(
    "s3",
    endpoint_url="' . $credentials['endpoint'] . '",
    aws_access_key_id="' . $credentials['accessKey'] . '",
    aws_secret_access_key="' . $credentials['secretKey'] . '",
    region_name="' . $credentials['region'] . '"
)

# Create bucket
client.create_bucket(Bucket="' . $bucketName . '")

# Upload file
client.put_object(
    Bucket="' . $bucketName . '",
    Key="test.txt",
    Body=b"Hello World!"
)'
            ]
        ];

        return $examples;
    }

    /**
     * Get available regions for object storage
     */
    public function getAvailableRegions()
    {
        return [
            'EU' => [
                'name' => 'Europe (Germany)',
                'code' => 'eu-central-1',
                'endpoint' => 'https://eu2.contabostorage.com'
            ],
            'US-WEST' => [
                'name' => 'US West',
                'code' => 'us-west-1', 
                'endpoint' => 'https://us-west.contabostorage.com'
            ],
            'US-EAST' => [
                'name' => 'US East',
                'code' => 'us-east-1',
                'endpoint' => 'https://us-east.contabostorage.com'
            ],
            'ASIA' => [
                'name' => 'Asia Pacific (Singapore)',
                'code' => 'ap-southeast-1',
                'endpoint' => 'https://ap-southeast.contabostorage.com'
            ]
        ];
    }

    /**
     * Get S3 endpoint for region
     */
    private function getS3Endpoint($region)
    {
        $regions = $this->getAvailableRegions();
        
        foreach ($regions as $regionData) {
            if ($regionData['code'] === $region) {
                return $regionData['endpoint'];
            }
        }

        // Default to EU endpoint
        return 'https://eu2.contabostorage.com';
    }

    /**
     * Get service object storages for a WHMCS service
     */
    public function getServiceObjectStorages($serviceId)
    {
        return Capsule::table('mod_contabo_object_storages')
            ->where('service_id', $serviceId)
            ->get();
    }

    /**
     * Update local storage data
     */
    private function updateLocalStorage($contaboStorageId, $storageData)
    {
        try {
            $updateData = [
                'name' => $storageData['displayName'] ?? '',
                'status' => $storageData['status'] ?? '',
                'size_gb' => $storageData['totalPurchasedSpaceInGb'] ?? 0,
                'updated_at' => now()
            ];

            // Update auto-scaling info if available
            if (isset($storageData['autoScaling'])) {
                $updateData['auto_scaling'] = $storageData['autoScaling']['state'] === 'enabled';
                if (isset($storageData['autoScaling']['sizeLimitTb'])) {
                    $updateData['auto_scaling_max_size_gb'] = $storageData['autoScaling']['sizeLimitTb'] * 1024;
                }
            }

            Capsule::table('mod_contabo_object_storages')
                ->where('contabo_storage_id', $contaboStorageId)
                ->update($updateData);

        } catch (Exception $e) {
            $this->logHelper->log('local_storage_update_failed', [
                'contabo_storage_id' => $contaboStorageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate monthly cost based on usage
     */
    public function calculateMonthlyCost($storageGb, $region = 'EU', $autoScaling = false)
    {
        // Base prices per GB per month (these should be configurable)
        $pricesPerGb = [
            'EU' => 0.025,
            'US-WEST' => 0.025,
            'US-EAST' => 0.025,
            'ASIA' => 0.030
        ];

        $basePrice = $storageGb * ($pricesPerGb[$region] ?? $pricesPerGb['EU']);
        
        // Add auto-scaling premium if enabled (example: 10% premium)
        if ($autoScaling) {
            $basePrice *= 1.1;
        }

        return round($basePrice, 2);
    }

    /**
     * Get storage usage analytics
     */
    public function getStorageAnalytics($storageId, $period = '30d')
    {
        try {
            $stats = $this->getStorageStats($storageId);
            
            if (empty($stats)) {
                return ['error' => 'No statistics available'];
            }

            // Process and format statistics
            $analytics = [
                'current_usage' => [
                    'used_space_gb' => round($stats['usedSpaceBytes'] / (1024**3), 2),
                    'used_space_percentage' => round(($stats['usedSpaceBytes'] / ($stats['maxSpaceBytes'] ?? 1)) * 100, 1),
                    'objects_count' => $stats['numObjects'] ?? 0,
                    'buckets_count' => $stats['numBuckets'] ?? 0
                ],
                'capacity' => [
                    'total_space_gb' => round($stats['maxSpaceBytes'] / (1024**3), 2),
                    'available_space_gb' => round(($stats['maxSpaceBytes'] - $stats['usedSpaceBytes']) / (1024**3), 2)
                ],
                'cost_estimate' => [
                    'current_month' => $this->calculateMonthlyCost(
                        round($stats['usedSpaceBytes'] / (1024**3), 2),
                        $this->getStorageRegion($storageId)
                    )
                ]
            ];

            return $analytics;

        } catch (Exception $e) {
            $this->logHelper->log('storage_analytics_failed', [
                'storage_id' => $storageId,
                'period' => $period,
                'error' => $e->getMessage()
            ]);
            
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get storage region from local database
     */
    private function getStorageRegion($storageId)
    {
        $storage = Capsule::table('mod_contabo_object_storages')
            ->where('contabo_storage_id', $storageId)
            ->first();

        return $storage->region ?? 'EU';
    }
}
