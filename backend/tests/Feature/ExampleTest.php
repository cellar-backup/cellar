<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use DatabaseMigrations;

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/api/v1/system/health');

        $response->assertOk();
    }
}
