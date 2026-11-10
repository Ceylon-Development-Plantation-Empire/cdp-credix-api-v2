<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Landing page and health checks (previously route closures, which cannot be route-cached).
 * Responses are unchanged.
 */
class HealthController extends Controller
{
    /**
     * GET / : the starter kit's landing page.
     */
    public function landing(): View
    {
        return view('landing');
    }

    /**
     * GET /api/health-check : liveness for load balancers (no database access).
     */
    public function ping(): JsonResponse
    {
        return response()->json(['message' => 'CDP Starter Kit API is working!']);
    }

    /**
     * GET /health : readiness, including a database round-trip. Never reveals connection details.
     */
    public function health(): JsonResponse
    {
        $now = now();
        $status = [
            'status' => 'healthy',
            'date' => $now->toDateString(),
            'time' => $now->toTimeString(),
            'service' => 'CDP Starter Kit API',
            'components' => [],
        ];

        try {
            DB::select('SELECT 1');
            $status['components']['database'] = 'healthy';
        } catch (\Throwable) {
            $status['components']['database'] = 'unhealthy';
            $status['status'] = 'degraded';
        }

        return response()->json($status);
    }
}
