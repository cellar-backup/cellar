<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Profile::orderBy('name');

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:schedule,retention',
            'config' => 'required|array',
            'is_default' => 'nullable|boolean',
        ]);

        // If this is being set as default, unset other defaults of the same type
        if (! empty($data['is_default'])) {
            Profile::where('type', $data['type'])->update(['is_default' => false]);
        }

        $profile = Profile::create($data);

        return response()->json($profile, 201);
    }

    public function show(Profile $profile): JsonResponse
    {
        return response()->json($profile);
    }

    public function update(Request $request, Profile $profile): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'config' => 'sometimes|array',
            'is_default' => 'nullable|boolean',
        ]);

        // If this is being set as default, unset other defaults of the same type
        if (! empty($data['is_default'])) {
            Profile::where('type', $profile->type)
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);
        }

        $profile->update($data);

        return response()->json($profile->fresh());
    }

    public function destroy(Profile $profile): JsonResponse
    {
        $profile->delete();

        return response()->json(null, 204);
    }
}
