<?php
/**
 * Contabo API Client
 * 
 * Handles OAuth2 authentication and API communication with Contabo API
 */

namespace ContaboAddon\API;

use Exception;

class ContaboAPIClient
{
    private $clientId;
    private $clientSecret;
    private $apiUser;
    private $apiPassword;
    private $baseUrl = 'https://api.contabo.com';
    private $authUrl = 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token';
    private $accessToken;
    private $tokenExpiry;
    private $debugMode;

    public function __construct($config = [])
    {
        $this->clientId = $config['client_id'] ?? '';
        $this->clientSecret = $config['client_secret'] ?? '';
        $this->apiUser = $config['api_user'] ?? '';
        $this->apiPassword = $config['api_password'] ?? '';
        $this->debugMode = ($config['debug_logging'] ?? 'no') === 'yes';
    }

    /**
     * Get OAuth2 access token
     */
    private function getAccessToken()
    {
        // Check if we have a valid cached token
        if ($this->accessToken && $this->tokenExpiry > time()) {
            return $this->accessToken;
        }

        $postData = [
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $this->apiUser,
            'password' => $this->apiPassword
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logApiCall('AUTH', 'POST', $this->authUrl, $postData, null, null, $error);
            throw new Exception('cURL Error: ' . $error);
        }

        $data = json_decode($response, true);
        
        if ($httpCode !== 200 || !isset($data['access_token'])) {
            $this->logApiCall('AUTH', 'POST', $this->authUrl, $postData, $data, $httpCode);
            throw new Exception('Authentication failed: ' . ($data['error_description'] ?? 'Unknown error'));
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60; // 1 minute buffer

        $this->logApiCall('AUTH', 'POST', $this->authUrl, ['client_id' => $this->clientId], 
                         ['status' => 'success'], $httpCode);

        return $this->accessToken;
    }

    /**
     * Make authenticated API request
     */
    public function makeRequest($method, $endpoint, $data = null, $headers = [])
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl . $endpoint;
        $requestId = $this->generateUuid();

        $defaultHeaders = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'x-request-id: ' . $requestId,
            'User-Agent: WHMCS-Contabo-Addon/1.0.0'
        ];

        $headers = array_merge($defaultHeaders, $headers);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;

            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;

            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;

            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;

            case 'GET':
            default:
                // GET is default
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logApiCall($method, $endpoint, $data, null, $httpCode, $requestId, $error);
            throw new Exception('cURL Error: ' . $error);
        }

        $responseData = json_decode($response, true);
        
        $this->logApiCall($method, $endpoint, $data, $responseData, $httpCode, $requestId);

        if ($httpCode >= 400) {
            $errorMessage = $responseData['message'] ?? 'API Error';
            if (isset($responseData['violations'])) {
                $violations = array_map(function($v) {
                    return $v['propertyPath'] . ': ' . $v['message'];
                }, $responseData['violations']);
                $errorMessage .= ' - ' . implode(', ', $violations);
            }
            throw new Exception($errorMessage . " (HTTP $httpCode)");
        }

        return $responseData;
    }

    /**
     * Compute Instances API methods
     */
    public function getInstances($page = 1, $size = 50, $filters = [])
    {
        $params = ['page' => $page, 'size' => $size];
        $params = array_merge($params, $filters);
        
        return $this->makeRequest('GET', '/v1/compute/instances?' . http_build_query($params));
    }

    public function getInstance($instanceId)
    {
        return $this->makeRequest('GET', "/v1/compute/instances/{$instanceId}");
    }

    public function createInstance($data)
    {
        return $this->makeRequest('POST', '/v1/compute/instances', $data);
    }

    public function updateInstance($instanceId, $data)
    {
        return $this->makeRequest('PATCH', "/v1/compute/instances/{$instanceId}", $data);
    }

    public function deleteInstance($instanceId)
    {
        return $this->makeRequest('DELETE', "/v1/compute/instances/{$instanceId}");
    }

    public function startInstance($instanceId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/actions/start");
    }

    public function stopInstance($instanceId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/actions/stop");
    }

    public function restartInstance($instanceId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/actions/restart");
    }

    public function shutdownInstance($instanceId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/actions/shutdown");
    }

    public function rescueInstance($instanceId, $data = [])
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/actions/rescue", $data);
    }

    public function resetInstancePassword($instanceId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/actions/resetPassword");
    }

    public function getInstanceVNC($instanceId)
    {
        return $this->makeRequest('GET', "/v1/compute/instances/{$instanceId}/vnc");
    }

    public function updateInstanceVNCPassword($instanceId, $vncPassword)
    {
        return $this->makeRequest('PATCH', "/v1/compute/instances/{$instanceId}/vnc", ['vncPassword' => $vncPassword]);
    }

    public function upgradeInstance($instanceId, $data)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/upgrade", $data);
    }

    public function reinstallInstance($instanceId, $data)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/reinstall", $data);
    }

    public function cancelInstance($instanceId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/cancel");
    }

    /**
     * Snapshots API methods
     */
    public function getInstanceSnapshots($instanceId)
    {
        return $this->makeRequest('GET', "/v1/compute/instances/{$instanceId}/snapshots");
    }

    public function createSnapshot($instanceId, $data)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/snapshots", $data);
    }

    public function deleteSnapshot($instanceId, $snapshotId)
    {
        return $this->makeRequest('DELETE', "/v1/compute/instances/{$instanceId}/snapshots/{$snapshotId}");
    }

    public function rollbackSnapshot($instanceId, $snapshotId)
    {
        return $this->makeRequest('POST', "/v1/compute/instances/{$instanceId}/snapshots/{$snapshotId}/rollback");
    }

    /**
     * Object Storage API methods
     */
    public function getObjectStorages($page = 1, $size = 50)
    {
        return $this->makeRequest('GET', "/v1/object-storages?page={$page}&size={$size}");
    }

    public function getObjectStorage($storageId)
    {
        return $this->makeRequest('GET', "/v1/object-storages/{$storageId}");
    }

    public function createObjectStorage($data)
    {
        return $this->makeRequest('POST', '/v1/object-storages', $data);
    }

    public function resizeObjectStorage($storageId, $data)
    {
        return $this->makeRequest('POST', "/v1/object-storages/{$storageId}/resize", $data);
    }

    public function cancelObjectStorage($storageId)
    {
        return $this->makeRequest('POST', "/v1/object-storages/{$storageId}/cancel");
    }

    public function getObjectStorageStats($storageId)
    {
        return $this->makeRequest('GET', "/v1/object-storages/{$storageId}/stats");
    }

    /**
     * Private Networks API methods
     */
    public function getPrivateNetworks($page = 1, $size = 50)
    {
        return $this->makeRequest('GET', "/v1/private-networks?page={$page}&size={$size}");
    }

    public function getPrivateNetwork($networkId)
    {
        return $this->makeRequest('GET', "/v1/private-networks/{$networkId}");
    }

    public function createPrivateNetwork($data)
    {
        return $this->makeRequest('POST', '/v1/private-networks', $data);
    }

    public function updatePrivateNetwork($networkId, $data)
    {
        return $this->makeRequest('PATCH', "/v1/private-networks/{$networkId}", $data);
    }

    public function deletePrivateNetwork($networkId)
    {
        return $this->makeRequest('DELETE', "/v1/private-networks/{$networkId}");
    }

    public function assignInstanceToNetwork($networkId, $instanceId, $data)
    {
        return $this->makeRequest('POST', "/v1/private-networks/{$networkId}/instances/{$instanceId}", $data);
    }

    public function removeInstanceFromNetwork($networkId, $instanceId)
    {
        return $this->makeRequest('DELETE', "/v1/private-networks/{$networkId}/instances/{$instanceId}");
    }

    /**
     * VIP Addresses API methods
     */
    public function getVips($page = 1, $size = 50)
    {
        return $this->makeRequest('GET', "/v1/vips?page={$page}&size={$size}");
    }

    public function getVip($ip)
    {
        return $this->makeRequest('GET', "/v1/vips/{$ip}");
    }

    public function createVip($data)
    {
        return $this->makeRequest('POST', '/v1/vips', $data);
    }

    public function assignVip($ip, $resourceType, $resourceId, $data)
    {
        return $this->makeRequest('PUT', "/v1/vips/{$ip}/{$resourceType}/{$resourceId}", $data);
    }

    public function unassignVip($ip, $resourceType, $resourceId)
    {
        return $this->makeRequest('DELETE', "/v1/vips/{$ip}/{$resourceType}/{$resourceId}");
    }

    /**
     * Images API methods
     */
    public function getImages($page = 1, $size = 50, $filters = [])
    {
        $params = ['page' => $page, 'size' => $size];
        $params = array_merge($params, $filters);
        
        return $this->makeRequest('GET', '/v1/compute/images?' . http_build_query($params));
    }

    public function getImage($imageId)
    {
        return $this->makeRequest('GET', "/v1/compute/images/{$imageId}");
    }

    public function createImage($data)
    {
        return $this->makeRequest('POST', '/v1/compute/images', $data);
    }

    public function updateImage($imageId, $data)
    {
        return $this->makeRequest('PATCH', "/v1/compute/images/{$imageId}", $data);
    }

    public function deleteImage($imageId)
    {
        return $this->makeRequest('DELETE', "/v1/compute/images/{$imageId}");
    }

    /**
     * Data Centers API methods
     */
    public function getDataCenters()
    {
        return $this->makeRequest('GET', '/v1/data-centers');
    }

    public function getDataCenter($dataCenterId)
    {
        return $this->makeRequest('GET', "/v1/data-centers/{$dataCenterId}");
    }

    /**
     * Applications API methods
     */
    public function getApplications($page = 1, $size = 50)
    {
        return $this->makeRequest('GET', "/v1/compute/applications?page={$page}&size={$size}");
    }

    /**
     * Users and Credentials API methods
     */
    public function getUserObjectStorageCredentials($userId)
    {
        return $this->makeRequest('GET', "/v1/users/{$userId}/object-storages/credentials");
    }

    public function createUserObjectStorageCredentials($userId, $data)
    {
        return $this->makeRequest('POST', "/v1/users/{$userId}/object-storages/credentials", $data);
    }

    /**
     * Secrets Management API methods
     */
    public function getSecrets($page = 1, $size = 50)
    {
        return $this->makeRequest('GET', "/v1/secrets?page={$page}&size={$size}");
    }

    public function createSecret($data)
    {
        return $this->makeRequest('POST', '/v1/secrets', $data);
    }

    public function getSecret($secretId)
    {
        return $this->makeRequest('GET', "/v1/secrets/{$secretId}");
    }

    public function updateSecret($secretId, $data)
    {
        return $this->makeRequest('PATCH', "/v1/secrets/{$secretId}", $data);
    }

    public function deleteSecret($secretId)
    {
        return $this->makeRequest('DELETE', "/v1/secrets/{$secretId}");
    }

    /**
     * Tags API methods
     */
    public function getTags($page = 1, $size = 50)
    {
        return $this->makeRequest('GET', "/v1/tags?page={$page}&size={$size}");
    }

    public function createTag($data)
    {
        return $this->makeRequest('POST', '/v1/tags', $data);
    }

    public function getTag($tagId)
    {
        return $this->makeRequest('GET', "/v1/tags/{$tagId}");
    }

    public function updateTag($tagId, $data)
    {
        return $this->makeRequest('PATCH', "/v1/tags/{$tagId}", $data);
    }

    public function deleteTag($tagId)
    {
        return $this->makeRequest('DELETE', "/v1/tags/{$tagId}");
    }

    public function assignTagToResource($tagId, $resourceType, $resourceId)
    {
        return $this->makeRequest('PUT', "/v1/tags/{$tagId}/assignments/{$resourceType}/{$resourceId}");
    }

    public function removeTagFromResource($tagId, $resourceType, $resourceId)
    {
        return $this->makeRequest('DELETE', "/v1/tags/{$tagId}/assignments/{$resourceType}/{$resourceId}");
    }

    /**
     * Helper methods
     */
    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function logApiCall($method, $endpoint, $requestData, $responseData, $httpCode, $requestId = null, $error = null)
    {
        if (!$this->debugMode && !$error) {
            return; // Only log errors if debug mode is off
        }

        try {
            \WHMCS\Database\Capsule::table('mod_contabo_api_logs')->insert([
                'action' => basename($endpoint),
                'method' => $method,
                'endpoint' => $endpoint,
                'request_data' => $requestData ? json_encode($requestData) : null,
                'response_data' => $responseData ? json_encode($responseData) : null,
                'response_code' => $httpCode,
                'request_id' => $requestId,
                'user_id' => $_SESSION['adminid'] ?? $_SESSION['uid'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            // Fail silently for logging errors
        }
    }

    /**
     * Test API connection
     */
    public function testConnection()
    {
        try {
            $this->getDataCenters();
            return ['success' => true, 'message' => 'Connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
