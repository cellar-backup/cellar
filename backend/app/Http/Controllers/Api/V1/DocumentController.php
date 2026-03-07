<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            CustomDocument::orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:custom_documents',
            'description' => 'nullable|string',
            'backup_command' => 'required|string',
            'restore_command' => 'required|string',
            'health_check' => 'nullable|string',
            'env_vars' => 'nullable|array',
            'stream_to_engine' => 'nullable|boolean',
        ]);

        return response()->json(CustomDocument::create($data), 201);
    }

    public function show(CustomDocument $document): JsonResponse
    {
        return response()->json($document);
    }

    public function update(Request $request, CustomDocument $document): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255|unique:custom_documents,name,'.$document->id,
            'description' => 'nullable|string',
            'backup_command' => 'sometimes|string',
            'restore_command' => 'sometimes|string',
            'health_check' => 'nullable|string',
            'env_vars' => 'nullable|array',
            'stream_to_engine' => 'nullable|boolean',
        ]);

        $document->update($data);

        return response()->json($document->fresh());
    }

    public function destroy(CustomDocument $document): JsonResponse
    {
        $document->delete();

        return response()->json(null, 204);
    }

    public function test(CustomDocument $document): JsonResponse
    {
        // TODO: Run a dry-run test of the document commands
        return response()->json([
            'status' => 'ok',
            'message' => 'Dry-run test not yet implemented.',
        ]);
    }
}
