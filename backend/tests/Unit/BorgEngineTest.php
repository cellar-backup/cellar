<?php

namespace Tests\Unit;

use App\Services\Engines\BorgEngine;
use App\Services\Engines\BorgError;
use Tests\TestCase;

class BorgEngineTest extends TestCase
{
    public function test_initialize_defaults_to_encrypted(): void
    {
        $engine = new BorgEngine(passphrase: null);

        $this->expectException(BorgError::class);
        $this->expectExceptionMessage('passphrase');

        // Should fail because no passphrase set for encrypted repo
        $engine->initialize('/tmp/nonexistent-borg-repo');
    }

    public function test_initialize_allows_none_without_passphrase(): void
    {
        $engine = new BorgEngine(
            borgPath: '/usr/bin/false', // won't actually run
            passphrase: null,
        );

        // Should not throw for passphrase — will fail on borg binary, which is fine
        // The point is: encryption=none does NOT require a passphrase
        try {
            $engine->initialize('/tmp/nonexistent-borg-repo', 'none');
        } catch (BorgError $e) {
            // Expected: borg binary failure, NOT passphrase error
            $this->assertStringNotContainsString('passphrase', $e->getMessage());
        }

        $this->assertTrue(true); // If we get here, no passphrase error was thrown
    }

    public function test_initialize_with_passphrase_does_not_throw(): void
    {
        $engine = new BorgEngine(
            borgPath: '/usr/bin/false',
            passphrase: 'my-secure-passphrase',
        );

        // Should not throw passphrase error
        try {
            $engine->initialize('/tmp/nonexistent-borg-repo');
        } catch (BorgError $e) {
            // Borg binary failure is expected, passphrase error is not
            $this->assertStringNotContainsString('passphrase', $e->getMessage());
        }

        $this->assertTrue(true);
    }
}
