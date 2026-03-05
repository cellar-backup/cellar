<?php

namespace App\Observers;

use App\Events\JobUpdated;
use App\Models\Job;

/**
 * Fires a WebSocket broadcast whenever a backup job's progress or status
 * changes, so the Vue frontend receives real-time updates without polling.
 */
class JobObserver
{
    /**
     * Broadcast when a new job is created so the frontend can show it
     * immediately in the Jobs view without requiring a page refresh.
     */
    public function created(Job $job): void
    {
        broadcast(new JobUpdated($job));
    }

    public function updated(Job $job): void
    {
        // Only broadcast when progress or status actually changed
        if ($job->wasChanged(['progress', 'status'])) {
            broadcast(new JobUpdated($job));
        }
    }
}
