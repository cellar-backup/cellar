<?php

use App\Jobs\RunBackup;
use App\Jobs\RunPrune;
use App\Models\BackupPlan;
use App\Models\Job;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Cellar Scheduler
|--------------------------------------------------------------------------
|
| Instead of managing periodic tasks in a separate DB table (like
| django-celery-beat), we use Laravel's native scheduler. Every minute
| the scheduler evaluates which plans are due and dispatches jobs.
|
*/

Schedule::call(function () {
    $plans = BackupPlan::where('schedule_enabled', true)
        ->whereNotNull('schedule_cron')
        ->get();

    foreach ($plans as $plan) {
        // Laravel's CronExpression is used internally, but Schedule::call
        // doesn't support dynamic cron per iteration. So we use a manual
        // cron matcher here.
        if (cronMatchesNow($plan->schedule_cron)) {
            $job = Job::create([
                'plan_id' => $plan->id,
                'job_type' => 'backup',
                'status' => 'pending',
                'progress' => 0,
            ]);
            RunBackup::dispatch($plan->id, $job->id);
            $plan->update(['next_run' => nextCronRun($plan->schedule_cron)]);
        }
    }
})->everyMinute()->name('cellar:dispatch-scheduled-backups')->withoutOverlapping();

// Prune runs 30 minutes after each backup cron
Schedule::call(function () {
    $plans = BackupPlan::where('schedule_enabled', true)
        ->whereNotNull('schedule_cron')
        ->whereJsonLength('retention_policy', '>', 0)
        ->get();

    foreach ($plans as $plan) {
        $pruneCron = shiftCronMinutes($plan->schedule_cron, 30);
        if (cronMatchesNow($pruneCron)) {
            $job = Job::create([
                'plan_id' => $plan->id,
                'job_type' => 'prune',
                'status' => 'pending',
                'progress' => 0,
            ]);
            RunPrune::dispatch($plan->id, false, $job->id);
        }
    }
})->everyMinute()->name('cellar:dispatch-scheduled-prunes')->withoutOverlapping();

// Reconcile the archives table with borg repos daily at 03:00
Schedule::command('cellar:sync-archives')
    ->dailyAt('03:00')
    ->name('cellar:sync-archives')
    ->withoutOverlapping();

// Check source connectivity every 15 minutes
Schedule::command('cellar:check-source-health')
    ->everyFifteenMinutes()
    ->name('cellar:check-source-health')
    ->withoutOverlapping();

// Clean up old job log files daily at 04:00 (retain 30 days)
Schedule::call(function () {
    \App\Services\JobLogger::cleanup(30);
})->dailyAt('04:00')->name('cellar:cleanup-job-logs');

/*
|--------------------------------------------------------------------------
| Cron helpers
|--------------------------------------------------------------------------
*/

function cronMatchesNow(string $cron): bool
{
    $expression = new \Cron\CronExpression($cron);

    return $expression->isDue();
}

function nextCronRun(string $cron): \DateTimeInterface
{
    $expression = new \Cron\CronExpression($cron);

    return $expression->getNextRunDate();
}

function shiftCronMinutes(string $cron, int $addMinutes): string
{
    $parts = explode(' ', $cron);
    if (count($parts) < 5) {
        return $cron;
    }

    if (is_numeric($parts[0])) {
        $parts[0] = (string) (((int) $parts[0] + $addMinutes) % 60);
    }

    return implode(' ', $parts);
}
