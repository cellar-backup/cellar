<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Models\Job;
use App\Observers\JobObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Job::observe(JobObserver::class);

        // Override Horizon maxProcesses with the DB-stored setting (from setup wizard)
        try {
            $maxJobs = AppSetting::get('max_parallel_jobs');
            if ($maxJobs) {
                config([
                    'horizon.environments.production.supervisor-1.maxProcesses' => (int) $maxJobs,
                    'horizon.environments.local.supervisor-1.maxProcesses' => (int) $maxJobs,
                    'cellar.max_parallel_jobs' => (int) $maxJobs,
                ]);
            }
        } catch (\Throwable) {
            // DB not available yet (migrations, fresh install)
        }
    }
}
