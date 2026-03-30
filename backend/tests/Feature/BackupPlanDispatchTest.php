<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\BackupPlan;
use App\Models\Repository;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupPlanDispatchTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private Repository $repo;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->repo = Repository::create([
            'name' => 'Test Repo',
            'backend_type' => 'local',
            'is_default' => true,
            'config' => ['path' => '/tmp/test-repos'],
        ]);

        $this->source = Source::create([
            'name' => 'Test DB',
            'source_type' => 'postgresql',
            'host' => 'localhost',
            'port' => 5432,
            'username' => 'test',
            'password' => 'test',
            'database_name' => 'testdb',
            'enabled' => true,
        ]);
    }

    public function test_dispatch_backup_queues_job(): void
    {
        Queue::fake();

        $plan = BackupPlan::create([
            'name' => 'Daily DB Backup',
            'source_id' => $this->source->id,
            'repository_id' => $this->repo->id,
            'engine' => 'borg',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/plans/{$plan->id}/backup");

        $response->assertStatus(202)
            ->assertJsonStructure(['detail', 'job_id']);

        Queue::assertPushed(RunBackup::class, function ($job) use ($plan) {
            return $job->planId === $plan->id;
        });
    }

    public function test_dispatch_backup_rejected_for_disabled_source(): void
    {
        Queue::fake();

        $this->source->update(['enabled' => false]);

        $plan = BackupPlan::create([
            'name' => 'Disabled Source Plan',
            'source_id' => $this->source->id,
            'repository_id' => $this->repo->id,
            'engine' => 'borg',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/plans/{$plan->id}/backup");

        $response->assertStatus(422)
            ->assertJsonFragment(['detail' => 'Source is disabled. Enable the source before running a backup.']);

        Queue::assertNotPushed(RunBackup::class);
    }

    public function test_plan_crud_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/plans');
        $response->assertUnauthorized();
    }
}
