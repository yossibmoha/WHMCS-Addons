<?php
// File: /whmcs_monitor.php (place in web root or separate monitoring server)

// Configuration
$whmcsUrl = 'https://yourdomain.com/whmcs';
$apiIdentifier = 'your_api_identifier';
$apiSecret = 'your_api_secret';
$ntfyUrl = 'https://your-ntfy-server.com/whmcs-monitor';

class WHMCSMonitor {
    private $whmcsUrl;
    private $apiId;
    private $apiSecret;
    private $ntfyUrl;
    
    public function __construct($whmcsUrl, $apiId, $apiSecret, $ntfyUrl) {
        $this->whmcsUrl = $whmcsUrl;
        $this->apiId = $apiId;
        $this->apiSecret = $apiSecret;
        $this->ntfyUrl = $ntfyUrl;
    }
    
    public function runChecks() {
        $results = [];
        
        // 1. Website Availability
        $results['website'] = $this->checkWebsiteAvailability();
        
        // 2. API Connectivity
        $results['api'] = $this->checkAPIConnectivity();
        
        // 3. Database Performance
        $results['database'] = $this->checkDatabasePerformance();
        
        // 4. System Statistics
        $results['stats'] = $this->getSystemStats();
        
        // 5. SSL Certificate
        $results['ssl'] = $this->checkSSLCertificate();
        
        // Send notifications for any issues
        $this->processResults($results);
        
        return $results;
    }
    
    private function checkWebsiteAvailability() {
        $start = microtime(true);
        $ch = curl_init($this->whmcsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = (microtime(true) - $start) * 1000;
        
        curl_close($ch);
        
        return [
            'status' => $httpCode == 200 ? 'OK' : 'ERROR',
            'http_code' => $httpCode,
            'response_time' => round($responseTime, 2),
            'available' => $response !== false
        ];
    }
    
    private function checkAPIConnectivity() {
        $postfields = [
            'action' => 'GetStats',
            'identifier' => $this->apiId,
            'secret' => $this->apiSecret,
            'responsetype' => 'json',
        ];
        
        $start = microtime(true);
        $ch = curl_init($this->whmcsUrl . '/includes/api.php');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $responseTime = (microtime(true) - $start) * 1000;
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        return [
            'status' => isset($data['result']) && $data['result'] == 'success' ? 'OK' : 'ERROR',
            'response_time' => round($responseTime, 2),
            'error' => isset($data['message']) ? $data['message'] : null
        ];
    }
    
    private function checkDatabasePerformance() {
        // Use API to get recent activity (tests database)
        $postfields = [
            'action' => 'GetActivityLog',
            'identifier' => $this->apiId,
            'secret' => $this->apiSecret,
            'responsetype' => 'json',
            'limitnum' => 1
        ];
        
        $start = microtime(true);
        $ch = curl_init($this->whmcsUrl . '/includes/api.php');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $queryTime = (microtime(true) - $start) * 1000;
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        return [
            'status' => isset($data['result']) && $data['result'] == 'success' ? 'OK' : 'ERROR',
            'query_time' => round($queryTime, 2),
            'slow_query' => $queryTime > 2000 ? true : false
        ];
    }
    
    private function getSystemStats() {
        $postfields = [
            'action' => 'GetStats',
            'identifier' => $this->apiId,
            'secret' => $this->apiSecret,
            'responsetype' => 'json',
        ];
        
        $ch = curl_init($this->whmcsUrl . '/includes/api.php');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (isset($data['stats'])) {
            return [
                'status' => 'OK',
                'clients_active' => $data['stats']['clients']['active'] ?? 0,
                'orders_pending' => $data['stats']['orders']['pending'] ?? 0,
                'tickets_open' => $data['stats']['tickets']['open'] ?? 0,
                'invoices_overdue' => $data['stats']['invoices']['overdue'] ?? 0
            ];
        }
        
        return ['status' => 'ERROR', 'error' => 'Could not retrieve stats'];
    }
    
    private function checkSSLCertificate() {
        $url = parse_url($this->whmcsUrl);
        $hostname = $url['host'];
        $port = 443;
        
        $context = stream_context_create([
            "ssl" => [
                "capture_peer_cert" => true,
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
        ]);
        
        $socket = stream_socket_client(
            "ssl://{$hostname}:{$port}",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$socket) {
            return ['status' => 'ERROR', 'error' => $errstr];
        }
        
        $cert = stream_context_get_params($socket)["options"]["ssl"]["peer_certificate"];
        $certData = openssl_x509_parse($cert);
        
        fclose($socket);
        
        $expiryDate = $certData['validTo_time_t'];
        $daysUntilExpiry = ($expiryDate - time()) / 86400;
        
        return [
            'status' => $daysUntilExpiry > 0 ? 'OK' : 'EXPIRED',
            'expires' => date('Y-m-d H:i:s', $expiryDate),
            'days_until_expiry' => round($daysUntilExpiry),
            'issuer' => $certData['issuer']['CN'] ?? 'Unknown'
        ];
    }
    
    private function processResults($results) {
        $alerts = [];
        
        // Website availability
        if ($results['website']['status'] != 'OK') {
            $alerts[] = "🔴 Website Down - HTTP " . $results['website']['http_code'];
        } elseif ($results['website']['response_time'] > 3000) {
            $alerts[] = "⚠️ Slow Website - " . $results['website']['response_time'] . "ms";
        }
        
        // API connectivity
        if ($results['api']['status'] != 'OK') {
            $alerts[] = "🔴 API Error - " . ($results['api']['error'] ?? 'Connection failed');
        } elseif ($results['api']['response_time'] > 5000) {
            $alerts[] = "⚠️ Slow API - " . $results['api']['response_time'] . "ms";
        }
        
        // Database performance
        if ($results['database']['status'] != 'OK') {
            $alerts[] = "🔴 Database Error";
        } elseif ($results['database']['slow_query']) {
            $alerts[] = "⚠️ Slow Database - " . $results['database']['query_time'] . "ms";
        }
        
        // System stats alerts
        if (isset($results['stats']['tickets_open']) && $results['stats']['tickets_open'] > 20) {
            $alerts[] = "📈 High Open Tickets: " . $results['stats']['tickets_open'];
        }
        
        if (isset($results['stats']['invoices_overdue']) && $results['stats']['invoices_overdue'] > 50) {
            $alerts[] = "💰 High Overdue Invoices: " . $results['stats']['invoices_overdue'];
        }
        
        // SSL Certificate
        if ($results['ssl']['status'] == 'EXPIRED') {
            $alerts[] = "🔒 SSL Certificate Expired!";
        } elseif ($results['ssl']['days_until_expiry'] < 30) {
            $alerts[] = "⚠️ SSL Expires in " . $results['ssl']['days_until_expiry'] . " days";
        }
        
        // Send notifications
        if (!empty($alerts)) {
            $this->sendNotification("🚨 WHMCS Monitor Alert", implode("\n", $alerts), 4);
        } else {
            // Optional: Send daily OK status
            $message = "✅ All systems operational\n" .
                      "Website: " . $results['website']['response_time'] . "ms\n" .
                      "API: " . $results['api']['response_time'] . "ms\n" .
                      "Open Tickets: " . ($results['stats']['tickets_open'] ?? 0) . "\n" .
                      "SSL: " . $results['ssl']['days_until_expiry'] . " days";
            
            $this->sendNotification("✅ WHMCS Monitor Status", $message, 1);
        }
    }
    
    private function sendNotification($title, $message, $priority) {
        $data = json_encode([
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'tags' => ['monitor', 'whmcs']
        ]);
        
        $ch = curl_init($this->ntfyUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}

// Run monitoring (can be called via cron)
if (php_sapi_name() === 'cli' || isset($_GET['monitor'])) {
    $monitor = new WHMCSMonitor($whmcsUrl, $apiIdentifier, $apiSecret, $ntfyUrl);
    $results = $monitor->runChecks();
    
    if (php_sapi_name() === 'cli') {
        echo "WHMCS Monitoring completed at " . date('Y-m-d H:i:s') . "\n";
        print_r($results);
    } else {
        header('Content-Type: application/json');
        echo json_encode($results, JSON_PRETTY_PRINT);
    }
}
?>