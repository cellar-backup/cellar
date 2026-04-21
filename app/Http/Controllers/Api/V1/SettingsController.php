<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Return all settings as a key-value object.
     */
    public function index(): JsonResponse
    {
        $settings = AppSetting::all()->pluck('value', 'key');

        return response()->json($settings);
    }

    /**
     * Bulk-update settings.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|max:100',
            'settings.*.value' => 'nullable|string|max:5000',
        ]);

        foreach ($data['settings'] as $setting) {
            AppSetting::set($setting['key'], $setting['value']);
        }

        $settings = AppSetting::all()->pluck('value', 'key');

        return response()->json($settings);
    }
}
