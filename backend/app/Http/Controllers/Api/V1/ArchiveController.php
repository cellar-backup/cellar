<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Archive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArchiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Archive::with('plan')->orderByDesc('timestamp');

        if ($request->has('plan')) {
            $query->where('plan_id', $request->input('plan'));
        }

        $archives = $query->paginate($request->integer('per_page', 25));

        $archives->getCollection()->transform(function (Archive $a) {
            $data = $a->toArray();
            $data['plan_name'] = $a->plan_name;

            return $data;
        });

        return response()->json($archives);
    }

    public function show(Archive $archive): JsonResponse
    {
        return response()->json(array_merge($archive->toArray(), [
            'plan_name' => $archive->plan_name,
        ]));
    }

    public function destroy(Archive $archive): JsonResponse
    {
        $archive->delete();

        return response()->json(null, 204);
    }
}
