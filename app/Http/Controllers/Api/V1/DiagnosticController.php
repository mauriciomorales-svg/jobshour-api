<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ServiceRequest;

class DiagnosticController extends Controller
{
    public function check(Request $request)
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'status' => 'checking',
            'checks' => []
        ];

        // Check 1: Database connection
        try {
            DB::connection()->getPdo();
            $results['checks']['database'] = [
                'status' => 'ok',
                'message' => 'Database connection successful'
            ];
        } catch (\Exception $e) {
            $results['checks']['database'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            $results['status'] = 'error';
        }

        // Check 2: ServiceRequest model
        try {
            $count = ServiceRequest::count();
            $results['checks']['service_requests'] = [
                'status' => 'ok',
                'message' => "Found {$count} service requests"
            ];
        } catch (\Exception $e) {
            $results['checks']['service_requests'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            $results['status'] = 'error';
        }

        // Check 3: DemandMapController show method (test with a real request)
        try {
            $testRequest = ServiceRequest::where('status', 'pending')
                ->whereNotNull('category_id')
                ->whereNotNull('client_id')
                ->first();
            
            if ($testRequest) {
                // Simular llamada al método show
                $controller = new \App\Http\Controllers\Api\V1\DemandMapController();
                $mockRequest = Request::create("/api/v1/demand/{$testRequest->id}", 'GET');
                
                // Cargar relaciones
                $testRequest->load(['client:id,name,avatar,phone', 'category:id,slug,display_name,color']);
                
                // Verificar que category y client existen
                $categoryOk = $testRequest->category !== null;
                $clientOk = $testRequest->client !== null;
                
                $results['checks']['demand_map_controller'] = [
                    'status' => ($categoryOk && $clientOk) ? 'ok' : 'warning',
                    'message' => "Test request ID: {$testRequest->id}",
                    'category_exists' => $categoryOk,
                    'client_exists' => $clientOk,
                    'category_display_name' => $testRequest->category ? ($testRequest->category->display_name ?? 'null') : 'category is null',
                    'client_name' => $testRequest->client ? ($testRequest->client->name ?? 'null') : 'client is null'
                ];
            } else {
                $results['checks']['demand_map_controller'] = [
                    'status' => 'warning',
                    'message' => 'No test request found with category and client'
                ];
            }
        } catch (\Exception $e) {
            $results['checks']['demand_map_controller'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            $results['status'] = 'error';
        }

        // Check 4: DashboardController liveStats
        try {
            $controller = new \App\Http\Controllers\Api\V1\DashboardController();
            $mockRequest = Request::create('/api/v1/dashboard/live-stats?lat=-37.6672&lng=-72.5730&radius=50', 'GET');
            $response = $controller->liveStats($mockRequest);
            $data = json_decode($response->getContent(), true);
            
            $results['checks']['dashboard_live_stats'] = [
                'status' => ($response->getStatusCode() === 200 && $data['status'] === 'success') ? 'ok' : 'error',
                'status_code' => $response->getStatusCode(),
                'response_status' => $data['status'] ?? 'unknown',
                'message' => 'LiveStats endpoint working'
            ];
        } catch (\Exception $e) {
            $results['checks']['dashboard_live_stats'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            $results['status'] = 'error';
        }

        // Check 5: Recent errors in logs
        try {
            $logFile = storage_path('logs/laravel.log');
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                $errorCount = substr_count($logContent, '[ERROR]');
                $recentErrors = [];
                
                // Buscar errores de las últimas 5 líneas que contengan ERROR
                $lines = explode("\n", $logContent);
                $recentLines = array_slice($lines, -50);
                foreach ($recentLines as $line) {
                    if (strpos($line, '[ERROR]') !== false || strpos($line, 'ERROR') !== false) {
                        $recentErrors[] = substr($line, 0, 200); // Primeros 200 caracteres
                    }
                }
                
                $results['checks']['recent_logs'] = [
                    'status' => count($recentErrors) === 0 ? 'ok' : 'warning',
                    'total_errors' => $errorCount,
                    'recent_errors' => array_slice($recentErrors, -5), // Últimos 5 errores
                    'message' => count($recentErrors) === 0 ? 'No recent errors' : 'Found ' . count($recentErrors) . ' recent errors'
                ];
            } else {
                $results['checks']['recent_logs'] = [
                    'status' => 'warning',
                    'message' => 'Log file not found'
                ];
            }
        } catch (\Exception $e) {
            $results['checks']['recent_logs'] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

        $results['overall_status'] = $results['status'] === 'error' ? 'error' : ($results['status'] === 'checking' ? 'ok' : 'warning');
        
        return response()->json($results, $results['status'] === 'error' ? 500 : 200);
    }
}
