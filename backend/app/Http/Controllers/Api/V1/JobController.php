<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Services\JobLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Job::with('plan.source')->orderByDesc('created_at');

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
            $data['source_name'] = $job->source_name;
            $data['source_id'] = $job->source_id;

            return $data;
        });

        return response()->json($jobs);
    }

    public function show(Job $job): JsonResponse
    {
        $job->load('plan.source');

        return response()->json(array_merge($job->toArray(), [
            'plan_name' => $job->plan_name,
            'source_name' => $job->source_name,
            'source_id' => $job->source_id,
        ]));
    }

    /**
     * GET /jobs/{job}/log
     *
     * Return the log file content for a job.
     */
    public function log(Job $job): JsonResponse
    {
        $content = JobLogger::read($job);

        if ($content === null) {
            return response()->json([
                'content' => null,
                'message' => 'No log file available for this job.',
            ]);
        }

        return response()->json([
            'content' => $content,
            'log_path' => $job->log_path,
        ]);
    }
    public function cancel(Job $job): JsonResponse
    {
        if (! in_array($job->status, [JobStatus::Running, JobStatus::Pending])) {
            return response()->json([
                'detail' => 'Only running or pending jobs can be cancelled.',
            ], 422);
        }

        $job->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_message' => 'Cancelled by user.',
        ]);

        // Reset plan status so it no longer shows as running
        if ($job->plan) {
            $job->plan->update(['status' => 'idle']);
        }

        return response()->json([
            'detail' => 'Job cancelled.',
        ]);
    }}
