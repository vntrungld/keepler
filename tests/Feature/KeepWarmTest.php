<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeepWarmTest extends TestCase
{
    use RefreshDatabase;

    public function test_responds_ok_without_authentication(): void
    {
        $this->getJson('/cron/warm')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    /**
     * The point of the endpoint: the managed database sleeps on its own
     * schedule, so the ping has to reach it and not just the web container.
     */
    public function test_touches_the_database(): void
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->getJson('/cron/warm')->assertOk();

        $this->assertContains('select 1', $statements);
    }
}
