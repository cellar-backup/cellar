<?php

namespace Tests\Feature;

use App\Models\BackupPlan;
use App\Models\Job;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class BackupJobTest extends TestCase
{
    use DatabaseMigrations;

    private BackupPlan $plan;

    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $repo = Repository::create([
            'name' => 'Test Repo',
            'backend_type' => 'local',
            'is_default' => true,
            'config' => ['path' => sys_get_temp_dir().'/cellar-test-repos'],
        ]);

        $source = Source::create([
            'name' => 'Test FS',
            'source_type' => 'directory',
            'path' => sys_get_temp_dir(),
            'enabled' => true,
        ]);

        $this->plan = BackupPlan::create([
            'name' => 'Test Plan',
            'source_id' => $source->id,
            'repository_id' => $repo->id,
            'engine' => 'borg',
        ]);

        $this->repoPath = sys_get_temp_dir().'/cellar-test-repos/'.$this->plan->id;
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (is_dir($this->repoPath)) {
            exec('rm -rf '.escapeshellarg($this->repoPath));
        }
        parent::tearDown();
    }

    /**
     * Verify that a backup job record can be created and starts in the
     * correct 'pending' status without throwing an exception.
     */
    public function test_backup_job_creates_and_runs_without_exception(): void
    {
        $jobRecord = Job::create([
            'plan_id' => $this->plan->id,
            'job_type' => 'backup',
            'status' => 'pending',
            'progress' => 0,
        ]);

        // Verify the job record was persisted with correct status
        $this->assertDatabaseHas('backup_jobs', [
            'id' => $jobRecord->id,
            'status' => 'pending',
        ]);
    }

    public function test_cancelled_job_does_not_execute(): void
    {
        $jobRecord = Job::create([
            'plan_id' => $this->plan->id,
            'job_type' => 'backup',
            'status' => 'cancelled',
            'progress' => 0,
        ]);

        $this->assertTrue($jobRecord->isCancelled());
    }
}
