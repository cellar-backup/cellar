<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'version' => config('app.version', '0.10.0'),
            'needs_setup' => ! AppSetting::get('setup_completed'),
            'checks' => [
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis(),
            ],
        ]);
    }

    private function checkDatabase(): string
    {
        try {
            \DB::connection()->getPdo();

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }

    private function checkRedis(): string
    {
        try {
            \Illuminate\Support\Facades\Redis::ping();

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
