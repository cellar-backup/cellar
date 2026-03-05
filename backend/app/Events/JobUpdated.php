<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast immediately (not queued) when a backup job's progress or status changes.
 *
 * The frontend patches the plan card's running_job in-place from this event,
 * so no polling is needed during job execution.
 */
class JobUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $jobId;

    public string $planId;

    public string $status;

    public int $progress;

    public string $jobType;

    public ?string $startedAt;

    public ?string $finishedAt;

    public ?string $errorMessage;

    /** Plan name for display — allows the frontend to build a full Job row from a single event. */
    public string $planName;

    public string $createdAt;

    public function __construct(Job $job)
    {
        $this->jobId = $job->id;
        $this->planId = $job->plan_id;
        $this->status = $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status;
        $this->progress = $job->progress ?? 0;
        $this->jobType = $job->job_type instanceof \BackedEnum ? $job->job_type->value : (string) $job->job_type;
        $this->startedAt = $job->started_at?->toIso8601String();
        $this->finishedAt = $job->finished_at?->toIso8601String();
        $this->errorMessage = $job->error_message;
        $this->planName = $job->plan_name ?? $job->plan?->name ?? '';
        $this->createdAt = $job->created_at?->toIso8601String() ?? now()->toIso8601String();
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('jobs');
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'job.updated';
    }
}
