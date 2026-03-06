<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SetupController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (AppSetting::get('setup_completed')) {
            return response()->json(['message' => 'Setup has already been completed.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'timezone' => 'nullable|string|max:100',
            'app_url' => 'nullable|string|max:500',
            'max_parallel_jobs' => 'nullable|integer|min:1|max:20',
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
