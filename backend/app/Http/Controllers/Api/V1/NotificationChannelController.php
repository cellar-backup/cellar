<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationChannelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            NotificationChannel::orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'channel_type' => 'required|string|max:20',
            'config' => 'nullable|array',
            'events_filter' => 'nullable|array',
            'enabled' => 'nullable|boolean',
            'backup_plan_id' => 'nullable|uuid|exists:backup_plans,id',
        ]);

        return response()->json(NotificationChannel::create($data), 201);
    }

    public function show(NotificationChannel $notificationChannel): JsonResponse
    {
        return response()->json($notificationChannel);
    }

    public function update(Request $request, NotificationChannel $notificationChannel): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'channel_type' => 'sometimes|string|max:20',
            'config' => 'nullable|array',
            'events_filter' => 'nullable|array',
            'enabled' => 'nullable|boolean',
            'backup_plan_id' => 'nullable|uuid|exists:backup_plans,id',
        ]);

        $notificationChannel->update($data);

        return response()->json($notificationChannel->fresh());
    }

    public function destroy(NotificationChannel $notificationChannel): JsonResponse
    {
        $notificationChannel->delete();

        return response()->json(null, 204);
    }
}
