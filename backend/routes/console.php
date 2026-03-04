<?php

use App\Jobs\RunBackup;
use App\Jobs\RunPrune;
use App\Models\BackupPlan;
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
            RunBackup::dispatch($plan->id);
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
            RunPrune::dispatch($plan->id);
        }
    }
})->everyMinute()->name('cellar:dispatch-scheduled-prunes')->withoutOverlapping();

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
