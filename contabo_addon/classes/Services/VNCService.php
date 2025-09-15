<?php
/**
 * Contabo VNC Service
 * 
 * Handles VNC access credentials and management
 */

namespace ContaboAddon\Services;

use WHMCS\Database\Capsule;
use Exception;

class VNCService
{
    private $apiClient;
    private $logHelper;

    public function __construct($apiClient)
    {
        $this->apiClient = $apiClient;
        $this->logHelper = new \ContaboAddon\Helpers\LogHelper();
    }

    /**
     * Get VNC credentials for instance
     */
    public function getVNCCredentials($instanceId)
    {
        try {
            $response = $this->apiClient->makeRequest('GET', "/v1/compute/instances/{$instanceId}/vnc");
            
            if (!isset($response['data']) || empty($response['data'])) {
                throw new Exception('No VNC data returned from API');
            }

            $vncData = $response['data'][0];

            $credentials = [
                'enabled' => $vncData['enabled'] ?? false,
                'ip' => $vncData['vncIp'] ?? null,
                'port' => $vncData['vncPort'] ?? null,
                'url' => $this->generateVNCUrl($vncData),
                'status' => $vncData['enabled'] ? 'active' : 'inactive'
            ];

            $this->logHelper->log('vnc_credentials_retrieved', [
                'instance_id' => $instanceId,
                'enabled' => $credentials['enabled']
            ]);

            return $credentials;

        } catch (Exception $e) {
            $this->logHelper->log('vnc_credentials_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update VNC password
     */
    public function updateVNCPassword($instanceId, $newPassword)
    {
        try {
            $requestData = [
                'vncPassword' => $newPassword
            ];

            $response = $this->apiClient->makeRequest('PATCH', "/v1/compute/instances/{$instanceId}/vnc", $requestData);

            $this->logHelper->log('vnc_password_updated', [
                'instance_id' => $instanceId
            ]);

            return [
                'success' => true,
                'message' => 'VNC password updated successfully'
            ];

        } catch (Exception $e) {
            $this->logHelper->log('vnc_password_update_failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate VNC connection URL
     */
    private function generateVNCUrl($vncData)
    {
        if (!$vncData['enabled'] || !$vncData['vncIp'] || !$vncData['vncPort']) {
            return null;
        }

        return "vnc://{$vncData['vncIp']}:{$vncData['vncPort']}";
    }

    /**
     * Generate VNC connection instructions
     */
    public function generateConnectionInstructions($instanceId)
    {
        try {
            $credentials = $this->getVNCCredentials($instanceId);
            
            if (!$credentials['enabled']) {
                return [
                    'enabled' => false,
                    'message' => 'VNC is not enabled for this instance'
                ];
            }

            return [
                'enabled' => true,
                'instructions' => [
                    'vnc_viewer' => [
                        'title' => 'VNC Viewer',
                        'steps' => [
                            '1. Download and install a VNC viewer (TigerVNC, RealVNC, etc.)',
                            "2. Connect to: {$credentials['ip']}:{$credentials['port']}",
                            '3. Enter the VNC password when prompted',
                            '4. You should now have remote desktop access'
                        ]
                    ],
                    'browser' => [
                        'title' => 'Browser-based VNC',
                        'steps' => [
                            '1. Use noVNC or similar web-based VNC client',
                            "2. Server: {$credentials['ip']}",
                            "3. Port: {$credentials['port']}",
                            '4. Connect using the VNC password'
                        ]
                    ],
                    'command_line' => [
                        'title' => 'Command Line',
                        'commands' => [
                            "vncviewer {$credentials['ip']}:{$credentials['port']}",
                            "# Alternative with different VNC clients:",
                            "remmina vnc://{$credentials['ip']}:{$credentials['port']}",
                            "vinagre vnc://{$credentials['ip']}:{$credentials['port']}"
                        ]
                    ]
                ],
                'connection_details' => $credentials,
                'security_notes' => [
                    'VNC connections are not encrypted by default',
                    'Consider using SSH tunneling for secure access',
                    'Change VNC password regularly',
                    'Disable VNC when not needed'
                ]
            ];

        } catch (Exception $e) {
            return [
                'enabled' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create SSH tunnel command for secure VNC access
     */
    public function generateSSHTunnelCommand($instanceId, $sshUser = 'root', $localPort = 5901)
    {
        try {
            // Get instance details for SSH IP
            $instance = $this->apiClient->getInstance($instanceId);
            $sshIp = $instance['ipConfig']['v4']['ip'] ?? null;
            
            if (!$sshIp) {
                throw new Exception('Instance IP address not available');
            }

            // Get VNC details
            $vncCredentials = $this->getVNCCredentials($instanceId);
            
            if (!$vncCredentials['enabled']) {
                throw new Exception('VNC is not enabled for this instance');
            }

            $vncPort = $vncCredentials['port'];

            return [
                'ssh_tunnel' => "ssh -L {$localPort}:localhost:{$vncPort} {$sshUser}@{$sshIp}",
                'vnc_connect' => "vncviewer localhost:{$localPort}",
                'instructions' => [
                    "1. Open terminal and run: ssh -L {$localPort}:localhost:{$vncPort} {$sshUser}@{$sshIp}",
                    '2. Keep the SSH connection open',
                    "3. In another terminal/VNC client, connect to: localhost:{$localPort}",
                    '4. Enter VNC password when prompted'
                ],
                'security_benefit' => 'SSH tunnel encrypts VNC traffic for secure remote access'
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to generate SSH tunnel command: ' . $e->getMessage());
        }
    }
}
