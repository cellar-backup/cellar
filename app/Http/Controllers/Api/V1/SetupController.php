<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;

class SetupController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (AppSetting::get('setup_completed')) {
            return response()->json(['message' => 'Setup has already been completed.'], 403);
        }

        // Rate-limit setup attempts: 5 per minute per IP
        $key = 'setup-attempt:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many setup attempts. Try again later.'], 429);
        }
        RateLimiter::hit($key, 60);

        // Require a setup token if one is configured (env or file-based)
        $expectedToken = config('cellar.setup_token');
        if (! empty($expectedToken)) {
            $providedToken = $request->header('X-Setup-Token') ?? $request->input('setup_token');
            if (! hash_equals($expectedToken, (string) $providedToken)) {
                return response()->json(['message' => 'Invalid or missing setup token.'], 403);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'timezone' => 'nullable|string|max:100',
            'app_url' => 'nullable|string|max:500',
            'max_parallel_jobs' => 'nullable|integer|min:1|max:20',
            'setup_token' => 'nullable|string',
        ]);

        // Ensure admin user + default repo exist
        Artisan::call('cellar:seed-defaults');

        $admin = User::first();
        $admin->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        if (! empty($data['timezone'])) {
            AppSetting::set('timezone', $data['timezone']);
        }
        if (! empty($data['app_url'])) {
            AppSetting::set('app_url', $data['app_url']);
        }
        if (! empty($data['max_parallel_jobs'])) {
            AppSetting::set('max_parallel_jobs', (string) $data['max_parallel_jobs']);
        }

        AppSetting::set('setup_completed', '1');

        $token = $admin->createToken('spa')->plainTextToken;

        return response()->json([
            'message' => 'Setup completed successfully.',
            'token' => $token,
            'user' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
        ], 201);
    }
}
