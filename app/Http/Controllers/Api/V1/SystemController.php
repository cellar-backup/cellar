<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        try {
            $needsSetup = ! AppSetting::get('setup_completed');
        } catch (\Throwable) {
            $needsSetup = true;
        }

        return response()->json([
            'status' => 'healthy',
            'version' => config('cellar.version', '0.12.0'),
            'needs_setup' => $needsSetup,
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
            Redis::connection()->ping();

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
