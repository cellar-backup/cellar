<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Job::with('plan')->orderByDesc('created_at');

        if ($request->has('plan')) {
            $query->where('plan_id', $request->input('plan'));
        }
        if ($request->has('job_type')) {
            $query->where('job_type', $request->input('job_type'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $jobs = $query->paginate($request->integer('per_page', 25));

        // Append plan_name to each item
        $jobs->getCollection()->transform(function (Job $job) {
            $data = $job->toArray();
            $data['plan_name'] = $job->plan_name;

            return $data;
        });

        return response()->json($jobs);
    }

    public function show(Job $job): JsonResponse
    {
        return response()->json(array_merge($job->toArray(), [
            'plan_name' => $job->plan_name,
        ]));
    }
}
