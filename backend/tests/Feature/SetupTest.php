<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use DatabaseMigrations;

    public function test_setup_creates_admin_and_completes(): void
    {
        // Seed defaults first (creates admin user)
        $this->artisan('cellar:seed-defaults');

        $response = $this->postJson('/api/v1/setup', [
            'name' => 'Rafa',
            'email' => 'rafa@cellar.dev',
            'password' => 'strong-p4ssword!',
            'password_confirmation' => 'strong-p4ssword!',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['message', 'token', 'user']);

        $this->assertEquals('1', AppSetting::get('setup_completed'));

        // Verify admin was updated
        $admin = User::first();
        $this->assertEquals('Rafa', $admin->name);
        $this->assertEquals('rafa@cellar.dev', $admin->email);
    }

    public function test_setup_cannot_run_twice(): void
    {
        $this->artisan('cellar:seed-defaults');

        // First setup
        $this->postJson('/api/v1/setup', [
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ]);

        // Second attempt
        $response = $this->postJson('/api/v1/setup', [
            'name' => 'Hacker',
            'email' => 'hacker@evil.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ]);

        $response->assertForbidden()
            ->assertJson(['message' => 'Setup has already been completed.']);
    }

    public function test_setup_requires_valid_password_confirmation(): void
    {
        $this->artisan('cellar:seed-defaults');

        $response = $this->postJson('/api/v1/setup', [
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password123!',
            'password_confirmation' => 'different',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_setup_rejects_invalid_token_when_configured(): void
    {
        config(['cellar.setup_token' => 'correct-token-abc']);
        $this->artisan('cellar:seed-defaults');

        $response = $this->postJson('/api/v1/setup', [
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
            'setup_token' => 'wrong-token',
        ]);

        $response->assertForbidden()
            ->assertJson(['message' => 'Invalid or missing setup token.']);
    }

    public function test_setup_accepts_correct_token(): void
    {
        config(['cellar.setup_token' => 'correct-token-abc']);
        $this->artisan('cellar:seed-defaults');

        $response = $this->postJson('/api/v1/setup', [
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
            'setup_token' => 'correct-token-abc',
        ]);

        $response->assertCreated();
    }
}
