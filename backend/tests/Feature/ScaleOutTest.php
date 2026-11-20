<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * API サーバを複数台に増やしても壊れないための決まりごと（AGENTS.md「スケールアウト前提」）。
 */
class ScaleOutTest extends TestCase
{
    public function test_every_scheduled_task_runs_on_one_server_only(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertTrue($event->onOneServer, "スケジュール「{$event->command}」に ->onOneServer() を付けてください（全台の cron から呼ばれるため）");
        }
    }

    public function test_shared_state_is_not_kept_on_the_local_server(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        foreach (['SESSION_DRIVER=database', 'CACHE_STORE=database', 'QUEUE_CONNECTION=database', 'APP_MAINTENANCE_DRIVER=cache'] as $line) {
            $this->assertStringContainsString($line, $env);
        }
    }
}
