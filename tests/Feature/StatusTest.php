<?php

namespace Tests\Feature;

use App\Jobs\RecordHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_ok_and_the_version(): void
    {
        config(['app.version' => 'abc1234']);

        $this->getJson('/status')->assertOk()->assertJson([
            'version' => 'abc1234',
            'database' => 'ok',
            'cache' => 'ok',
            'worker_heartbeat_seconds_ago' => null,
        ]);
    }

    public function test_heartbeat_job_shows_up_in_status(): void
    {
        (new RecordHeartbeat)->handle();
        $this->travel(30)->seconds();

        $this->getJson('/status')->assertOk()->assertJsonPath('worker_heartbeat_seconds_ago', 30);
    }

    public function test_status_is_503_when_the_database_is_down(): void
    {
        DB::shouldReceive('select')->andThrow(new \RuntimeException('connection refused'));

        $this->getJson('/status')->assertStatus(503)->assertJsonPath('database', 'down');
    }

    public function test_root_redirects_to_status(): void
    {
        $this->get('/')->assertRedirect('/status');
    }

    public function test_heartbeat_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('RecordHeartbeat')->assertSuccessful();
    }
}
