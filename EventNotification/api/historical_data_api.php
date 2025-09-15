<?php
/**
 * Historical Data API for WHMCS Monitoring Dashboard
 * Provides REST API endpoints for historical data and analytics
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../classes/HistoricalDataManager.php';

class HistoricalDataAPI {
    private $historyManager;
    
    public function __construct() {
        $this->historyManager = new HistoricalDataManager(__DIR__ . '/../');
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
                default:
                    $this->sendResponse(405, ['error' => 'Method not allowed']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['error' => 'Internal server error', 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Simple authentication
     */
    private function authenticate() {
        // For development - enhance for production
        $apiKey = $_GET['api_key'] ?? '';
        
        // Allow requests from localhost in development
        if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1') {
            return true;
        }
        
        return !empty($apiKey);
    }
    
    /**
     * Handle GET requests
     */
    private function handleGet($pathParts) {
        $endpoint = $pathParts[array_search('historical_data_api.php', $pathParts) + 1] ?? 'summary';
        
        switch ($endpoint) {
            case 'summary':
                $this->getSummary();
                break;
            case 'trends':
                $this->getTrends();
                break;
            case 'whmcs':
                $this->getWHMCSTrends();
                break;
            case 'events':
                $this->getEventAnalysis();
                break;
            case 'availability':
                $this->getAvailability();
                break;
            case 'performance':
                $this->getPerformanceSummary();
                break;
            case 'export':
                $this->exportData();
                break;
            case 'health':
                $this->getHealthCheck();
                break;
            default:
                $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }
    
    /**
     * Handle POST requests
     */
    private function handlePost($pathParts) {
        $endpoint = $pathParts[array_search('historical_data_api.php', $pathParts) + 1] ?? '';
        $input = json_decode(file_get_contents('php://input'), true);
        
        switch ($endpoint) {
            case 'metric':
                $this->recordMetric($input);
                break;
            case 'event':
                $this->recordEvent($input);
                break;
            case 'cleanup':
                $this->cleanupData($input);
                break;
            default:
                $this->sendResponse(404, ['error' => 'Endpoint not found']);
        }
    }
    
    /**
     * Get performance summary
     */
    private function getSummary() {
        $hours = min(168, (int)($_GET['hours'] ?? 24)); // Max 1 week
        $summary = $this->historyManager->getPerformanceSummary($hours);
        
        $this->sendResponse(200, [
            'summary' => $summary,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get trend data for specific metric
     */
    private function getTrends() {
        $metric = $_GET['metric'] ?? 'cpu_usage';
        $hours = min(168, (int)($_GET['hours'] ?? 24));
        
        $trendData = $this->historyManager->getTrendData($metric, $hours);
        
        // Calculate statistics
        $values = array_column($trendData, 'value');
        $stats = [];
        
        if (!empty($values)) {
            $stats = [
                'avg' => round(array_sum($values) / count($values), 2),
                'min' => min($values),
                'max' => max($values),
                'samples' => count($values)
            ];
        }
        
        $this->sendResponse(200, [
            'metric' => $metric,
            'period_hours' => $hours,
            'data' => $trendData,
            'statistics' => $stats,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get WHMCS performance trends
     */
    private function getWHMCSTrends() {
        $hours = min(168, (int)($_GET['hours'] ?? 24));
        $trends = $this->historyManager->getWHMCSTrends($hours);
        
        // Calculate response time statistics
        $responseTimes = array_filter(array_column($trends, 'response_time'));
        $responseStats = [];
        
        if (!empty($responseTimes)) {
            $responseStats = [
                'avg' => round(array_sum($responseTimes) / count($responseTimes), 2),
                'min' => min($responseTimes),
                'max' => max($responseTimes),
                'p95' => $this->percentile($responseTimes, 95),
                'p99' => $this->percentile($responseTimes, 99)
            ];
        }
        
        // Format data for charting
        $chartData = [
            'timestamps' => array_column($trends, 'timestamp'),
            'response_times' => array_column($trends, 'response_time'),
            'db_query_times' => array_column($trends, 'database_query_time'),
            'open_tickets' => array_column($trends, 'open_tickets'),
            'pending_orders' => array_column($trends, 'pending_orders')
        ];
        
        $this->sendResponse(200, [
            'period_hours' => $hours,
            'raw_data' => $trends,
            'chart_data' => $chartData,
            'response_time_stats' => $responseStats,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get event analysis
     */
    private function getEventAnalysis() {
        $days = min(30, (int)($_GET['days'] ?? 7));
        $analysis = $this->historyManager->getEventAnalysis($days);
        
        // Calculate event trends
        $totalEvents = array_sum(array_column($analysis, 'total_count'));
        $highSeverityEvents = count(array_filter($analysis, function($event) {
            return $event['max_severity'] >= 4;
        }));
        
        $this->sendResponse(200, [
            'period_days' => $days,
            'events' => $analysis,
            'summary' => [
                'total_events' => $totalEvents,
                'event_types' => count($analysis),
                'high_severity_types' => $highSeverityEvents,
                'avg_events_per_day' => $days > 0 ? round($totalEvents / $days, 1) : 0
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get service availability
     */
    private function getAvailability() {
        $service = $_GET['service'] ?? 'nginx';
        $hours = min(168, (int)($_GET['hours'] ?? 24));
        
        $availability = $this->historyManager->getAvailabilityStats($service, $hours);
        
        $this->sendResponse(200, [
            'service' => $service,
            'period_hours' => $hours,
            'availability' => $availability,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get comprehensive performance summary
     */
    private function getPerformanceSummary() {
        $hours = min(168, (int)($_GET['hours'] ?? 24));
        $summary = $this->historyManager->getPerformanceSummary($hours);
        
        // Add availability data for key services
        $services = ['nginx', 'mysql', 'whmcs'];
        $serviceAvailability = [];
        
        foreach ($services as $service) {
            $serviceAvailability[$service] = $this->historyManager->getAvailabilityStats($service, $hours);
        }
        
        $summary['service_availability'] = $serviceAvailability;
        
        $this->sendResponse(200, [
            'performance' => $summary,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Export historical data
     */
    private function exportData() {
        $table = $_GET['table'] ?? 'system_metrics';
        $hours = min(168, (int)($_GET['hours'] ?? 24));
        $format = $_GET['format'] ?? 'csv';
        
        // Validate table name
        $allowedTables = ['system_metrics', 'whmcs_metrics', 'event_counters', 'availability_log'];
        if (!in_array($table, $allowedTables)) {
            $this->sendResponse(400, ['error' => 'Invalid table name']);
            return;
        }
        
        if ($format === 'csv') {
            $filePath = $this->historyManager->exportToCSV($table, $hours);
            
            if ($filePath && file_exists($filePath)) {
                $this->sendResponse(200, [
                    'export_file' => basename($filePath),
                    'download_url' => '/storage/exports/' . basename($filePath),
                    'file_size' => filesize($filePath),
                    'generated_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->sendResponse(500, ['error' => 'Export failed']);
            }
        } else {
            $this->sendResponse(400, ['error' => 'Unsupported format']);
        }
    }
    
    /**
     * Health check endpoint
     */
    private function getHealthCheck() {
        $health = [
            'status' => 'healthy',
            'database' => 'connected',
            'data_collection' => 'operational',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        try {
            // Test database connection
            $this->historyManager->getTrendData('cpu_usage', 1);
            
            // Check if recent data exists
            $recentData = $this->historyManager->getTrendData('cpu_usage', 1);
            if (empty($recentData)) {
                $health['status'] = 'degraded';
                $health['data_collection'] = 'no_recent_data';
            }
        } catch (Exception $e) {
            $health['status'] = 'unhealthy';
            $health['database'] = 'error';
            $health['error'] = $e->getMessage();
        }
        
        $httpCode = $health['status'] === 'healthy' ? 200 : 503;
        $this->sendResponse($httpCode, $health);
    }
    
    /**
     * Record custom metric
     */
    private function recordMetric($input) {
        $required = ['metric_type', 'value'];
        foreach ($required as $field) {
            if (!isset($input[$field])) {
                $this->sendResponse(400, ['error' => "Field '$field' is required"]);
                return;
            }
        }
        
        $result = $this->historyManager->recordSystemMetric(
            $input['metric_type'],
            $input['value'],
            $input['unit'] ?? null,
            $input['source'] ?? 'api',
            $input['metadata'] ?? []
        );
        
        if ($result) {
            $this->sendResponse(201, ['message' => 'Metric recorded successfully']);
        } else {
            $this->sendResponse(500, ['error' => 'Failed to record metric']);
        }
    }
    
    /**
     * Record custom event
     */
    private function recordEvent($input) {
        $required = ['event_type'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $this->sendResponse(400, ['error' => "Field '$field' is required"]);
                return;
            }
        }
        
        $result = $this->historyManager->recordEvent(
            $input['event_type'],
            $input['severity'] ?? 3,
            $input['count'] ?? 1
        );
        
        if ($result) {
            $this->sendResponse(201, ['message' => 'Event recorded successfully']);
        } else {
            $this->sendResponse(500, ['error' => 'Failed to record event']);
        }
    }
    
    /**
     * Cleanup historical data
     */
    private function cleanupData($input) {
        $days = min(365, max(1, (int)($input['days'] ?? 90)));
        
        $result = $this->historyManager->cleanupOldData($days);
        
        $this->sendResponse(200, [
            'message' => 'Cleanup completed successfully',
            'deleted_records' => $result,
            'kept_days' => $days
        ]);
    }
    
    /**
     * Calculate percentile value
     */
    private function percentile($array, $percentile) {
        if (empty($array)) return 0;
        
        sort($array);
        $index = ($percentile / 100) * (count($array) - 1);
        
        if (floor($index) == $index) {
            return $array[$index];
        } else {
            $lower = $array[floor($index)];
            $upper = $array[ceil($index)];
            return $lower + ($upper - $lower) * ($index - floor($index));
        }
    }
    
    /**
     * Send JSON response
     */
    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
        exit;
    }
}

// Initialize and handle request
$api = new HistoricalDataAPI();
$api->handleRequest();
?>
