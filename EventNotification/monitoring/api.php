<?php
/**
 * WHMCS Monitoring Addon API
 * Handles AJAX requests from the admin interface
 */

if (!defined("WHMCS")) {
    define("WHMCS", 1);
    require_once "../../../init.php";
}

header('Content-Type: application/json');

// Check if user is admin
if (!isset($_SESSION['adminid']) || empty($_SESSION['adminid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    require_once dirname(__DIR__) . '/classes/AlertManager.php';
    $alertManager = new AlertManager(dirname(__DIR__) . '/');
    
    switch ($action) {
        case 'acknowledge':
            $alertId = $input['alert_id'] ?? '';
            $user = $input['user'] ?? 'admin';
            $notes = $input['notes'] ?? '';
            
            if (empty($alertId)) {
                throw new Exception('Alert ID is required');
            }
            
            $success = $alertManager->acknowledgeAlert($alertId, $user, $notes);
            
            if ($success) {
                echo json_encode(['status' => 'success', 'message' => 'Alert acknowledged successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to acknowledge alert']);
            }
            break;
            
        case 'resolve':
            $alertId = $input['alert_id'] ?? '';
            $user = $input['user'] ?? 'admin';
            $notes = $input['notes'] ?? '';
            
            if (empty($alertId)) {
                throw new Exception('Alert ID is required');
            }
            
            $success = $alertManager->resolveAlert($alertId, $user, $notes);
            
            if ($success) {
                echo json_encode(['status' => 'success', 'message' => 'Alert resolved successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to resolve alert']);
            }
            break;
            
        case 'get_alerts':
            $limit = min(100, (int)($input['limit'] ?? 50));
            $alerts = $alertManager->getOpenAlerts($limit);
            
            echo json_encode([
                'status' => 'success',
                'alerts' => $alerts,
                'count' => count($alerts)
            ]);
            break;
            
        case 'get_alert_details':
            $alertId = $input['alert_id'] ?? $_GET['alert_id'] ?? '';
            
            if (empty($alertId)) {
                throw new Exception('Alert ID is required');
            }
            
            $alert = $alertManager->getAlertDetails($alertId);
            
            if ($alert) {
                echo json_encode(['status' => 'success', 'alert' => $alert]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Alert not found']);
            }
            break;
            
        case 'test_notification':
            $title = $input['title'] ?? 'Test Notification from WHMCS Admin';
            $message = $input['message'] ?? 'This is a test notification sent from the WHMCS admin panel at ' . date('Y-m-d H:i:s');
            $severity = (int)($input['severity'] ?? 3);
            
            // Create test alert
            $alertId = $alertManager->createAlert($title, $message, $severity, 'admin_test', ['test' => true]);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Test notification sent successfully',
                'alert_id' => $alertId
            ]);
            break;
            
        case 'get_stats':
            $days = min(30, (int)($input['days'] ?? 7));
            $stats = $alertManager->getAlertStats($days);
            
            echo json_encode([
                'status' => 'success',
                'stats' => $stats,
                'period_days' => $days
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
