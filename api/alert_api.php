<?php
/**
 * Alert Management API for WHMCS Monitoring Dashboard
 * Provides REST API endpoints for alert management operations
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../classes/AlertManager.php';
require_once __DIR__ . '/../includes/hooks/whmcs_notification_config_with_alerts.php';

class AlertAPI {
    private $alertManager;
    
    public function __construct() {
        $this->alertManager = new AlertManager(__DIR__ . '/../');
    }
    
    /**
     * Route requests to appropriate handlers
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $pathParts = explode('/', trim($path, '/'));
        
        // Simple authentication check
        if (!$this->authenticate()) {
            $this->sendResponse(401, ['error' => 'Unauthorized']);
            return;
        }
        
        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet($pathParts);
                    break;
                case 'POST':
                    $this->handlePost($pathParts);
                    break;
                case 'PUT':
                    $this->handlePut($pathParts);
                    break;
                case 'DELETE':
                    $this->handleDelete($pathParts);
                    break;
                default:
                    $this->sendResponse(405, ['error' => 'Method not allowed']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['error' => 'Internal server error', 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Simple authentication (enhance for production)
     */
    private function authenticate() {
        // For development - in production, use proper authentication
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $apiKey = $_GET['api_key'] ?? '';
        
        // Allow requests from localhost without authentication in development
        if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1') {
            return true;
        }
        
        // Check for API key or authorization header
        return !empty($apiKey) || !empty($authHeader);
    }
    
    /**
     * Handle GET requests
     */
    private function handleGet($pathParts) {
        $endpoint = $pathParts[array_search('alert_api.php', $pathParts) + 1] ?? 'alerts';
        
        switch ($endpoint) {
            case 'alerts':
                $this->getAlerts();
                break;
            case 'alert':
                $alertId = $pathParts[array_search('alert', $pathParts) + 1] ?? null;
                if ($alertId) {
                    $this->getAlert($alertId);
                } else {
                    $this->sendResponse(400, ['error' => 'Alert ID required']);
                }
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'health':
                $this->getHealth();
                break;
            default:
                $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }
    
    /**
     * Handle POST requests  
     */
    private function handlePost($pathParts) {
        $endpoint = $pathParts[array_search('alert_api.php', $pathParts) + 1] ?? 'create';
        $input = json_decode(file_get_contents('php://input'), true);
        
        switch ($endpoint) {
            case 'create':
                $this->createAlert($input);
                break;
            case 'test':
                $this->testAlert($input);
                break;
            case 'escalation':
                $this->processEscalations();
                break;
            default:
                $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }
    
    /**
     * Handle PUT requests
     */
    private function handlePut($pathParts) {
        $endpoint = $pathParts[array_search('alert_api.php', $pathParts) + 1] ?? '';
        $alertId = $pathParts[array_search('alert_api.php', $pathParts) + 2] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$alertId) {
            $this->sendResponse(400, ['error' => 'Alert ID required']);
            return;
        }
        
        switch ($endpoint) {
            case 'acknowledge':
                $this->acknowledgeAlert($alertId, $input);
                break;
            case 'resolve':
                $this->resolveAlert($alertId, $input);
                break;
            default:
                $this->sendResponse(404, ['error' => 'Action not found']);
        }
    }
    
    /**
     * Handle DELETE requests
     */
    private function handleDelete($pathParts) {
        $endpoint = $pathParts[array_search('alert_api.php', $pathParts) + 1] ?? '';
        
        switch ($endpoint) {
            case 'cleanup':
                $days = $_GET['days'] ?? 30;
                $this->cleanupAlerts($days);
                break;
            default:
                $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }
    
    /**
     * Get alerts with optional filtering
     */
    private function getAlerts() {
        $status = $_GET['status'] ?? 'open';
        $limit = min(100, (int)($_GET['limit'] ?? 50));
        
        if ($status === 'open') {
            $alerts = $this->alertManager->getOpenAlerts($limit);
        } else {
            // Add more filtering options as needed
            $alerts = $this->alertManager->getOpenAlerts($limit);
        }
        
        $this->sendResponse(200, [
            'alerts' => $alerts,
            'count' => count($alerts),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get specific alert details
     */
    private function getAlert($alertId) {
        $alert = $this->alertManager->getAlertDetails($alertId);
        
        if ($alert) {
            $this->sendResponse(200, ['alert' => $alert]);
        } else {
            $this->sendResponse(404, ['error' => 'Alert not found']);
        }
    }
    
    /**
     * Get alert statistics
     */
    private function getStats() {
        $days = min(90, (int)($_GET['days'] ?? 7));
        $stats = $this->alertManager->getAlertStats($days);
        
        $this->sendResponse(200, [
            'stats' => $stats,
            'period_days' => $days,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get system health
     */
    private function getHealth() {
        $health = [
            'status' => 'healthy',
            'alert_manager' => 'operational',
            'database' => 'connected',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Test database connection
        try {
            $this->alertManager->getAlertStats(1);
        } catch (Exception $e) {
            $health['status'] = 'unhealthy';
            $health['database'] = 'error';
            $health['error'] = $e->getMessage();
        }
        
        $httpCode = $health['status'] === 'healthy' ? 200 : 503;
        $this->sendResponse($httpCode, $health);
    }
    
    /**
     * Create new alert
     */
    private function createAlert($input) {
        $required = ['title', 'message'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $this->sendResponse(400, ['error' => "Field '$field' is required"]);
                return;
            }
        }
        
        $alertId = $this->alertManager->createAlert(
            $input['title'],
            $input['message'],
            $input['severity'] ?? 3,
            $input['source'] ?? 'api',
            $input['metadata'] ?? []
        );
        
        $this->sendResponse(201, [
            'alert_id' => $alertId,
            'message' => 'Alert created successfully'
        ]);
    }
    
    /**
     * Test alert creation
     */
    private function testAlert($input) {
        $severity = $input['severity'] ?? 3;
        $testTitle = "🧪 Test Alert - " . date('H:i:s');
        $testMessage = "This is a test alert created via API.\n\nTimestamp: " . date('Y-m-d H:i:s');
        
        $alertId = $this->alertManager->createAlert(
            $testTitle,
            $testMessage,
            $severity,
            'test',
            ['test' => true, 'api_user' => 'test']
        );
        
        $this->sendResponse(200, [
            'alert_id' => $alertId,
            'message' => 'Test alert created successfully'
        ]);
    }
    
    /**
     * Acknowledge alert
     */
    private function acknowledgeAlert($alertId, $input) {
        $user = $input['user'] ?? 'api-user';
        $notes = $input['notes'] ?? '';
        
        $success = $this->alertManager->acknowledgeAlert($alertId, $user, $notes);
        
        if ($success) {
            $this->sendResponse(200, ['message' => 'Alert acknowledged successfully']);
        } else {
            $this->sendResponse(404, ['error' => 'Alert not found or already acknowledged']);
        }
    }
    
    /**
     * Resolve alert
     */
    private function resolveAlert($alertId, $input) {
        $user = $input['user'] ?? 'api-user';
        $notes = $input['notes'] ?? '';
        
        $success = $this->alertManager->resolveAlert($alertId, $user, $notes);
        
        if ($success) {
            $this->sendResponse(200, ['message' => 'Alert resolved successfully']);
        } else {
            $this->sendResponse(404, ['error' => 'Alert not found or already resolved']);
        }
    }
    
    /**
     * Process escalations
     */
    private function processEscalations() {
        $escalatedCount = $this->alertManager->processEscalations();
        
        $this->sendResponse(200, [
            'message' => 'Escalations processed successfully',
            'escalated_count' => $escalatedCount
        ]);
    }
    
    /**
     * Cleanup old alerts
     */
    private function cleanupAlerts($days) {
        $result = $this->alertManager->cleanupOldAlerts($days);
        
        $this->sendResponse(200, [
            'message' => 'Cleanup completed successfully',
            'deleted_alerts' => $result['alerts'],
            'deleted_actions' => $result['actions']
        ]);
    }
    
    /**
     * Send JSON response
     */
    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

// Initialize and handle request
$api = new AlertAPI();
$api->handleRequest();
?>
