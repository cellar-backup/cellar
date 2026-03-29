<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_defaults_generates_random_password_when_no_env(): void
    {
        // Ensure no env password is set (or it's the weak default)
        config(['cellar.admin_password' => null]);

        $this->artisan('cellar:seed-defaults')
            ->assertSuccessful();

        $user = User::first();
        $this->assertNotNull($user);
        $this->assertEquals('admin', $user->username);

        // The password should NOT be 'admin' or 'changeme'
        $this->assertFalse(Hash::check('admin', $user->password));
        $this->assertFalse(Hash::check('changeme', $user->password));
    }

    public function test_seed_defaults_uses_env_password_when_set(): void
    {
        config(['cellar.admin_password' => 'my-secure-env-password']);

        $this->artisan('cellar:seed-defaults')
            ->assertSuccessful();

        $user = User::first();
        $this->assertTrue(Hash::check('my-secure-env-password', $user->password));
    }

    public function test_seed_defaults_rejects_weak_env_password(): void
    {
        // 'admin' should be treated as no password (triggers random generation)
        config(['cellar.admin_password' => 'admin']);

        $this->artisan('cellar:seed-defaults')
            ->assertSuccessful();

        $user = User::first();
        $this->assertFalse(Hash::check('admin', $user->password));
    }

    public function test_seed_defaults_idempotent(): void
    {
        config(['cellar.admin_password' => 'test-password-123']);

        $this->artisan('cellar:seed-defaults')->assertSuccessful();
        $this->artisan('cellar:seed-defaults')->assertSuccessful();

        $this->assertEquals(1, User::count());
    }
}
