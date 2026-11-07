<?php

namespace Tests\Feature\ContentStudio;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Tests\TestCase;

class SchedulerScopeTest extends TestCase
{
    /** @return list<\Illuminate\Console\Scheduling\Event> */
    private function scheduledEvents(string $scope, bool $enabled): array
    {
        config(['content-studio.schedule_scope' => $scope, 'content-studio.enabled' => $enabled]);

        $schedule = new Schedule();
        $kernel = $this->app->make(KernelContract::class);
        $this->assertInstanceOf(Kernel::class, $kernel);
        (new \ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $schedule);

        return $schedule->events();
    }

    /** @return list<string> */
    private function scheduledCommands(string $scope, bool $enabled): array
    {
        return array_map(fn ($e) => (string) $e->command, $this->scheduledEvents($scope, $enabled));
    }

    private function names(array $commands, string $prefix): array
    {
        return array_values(array_filter($commands, fn ($c) => str_contains($c, "{$prefix}:")));
    }

    public function test_default_scope_with_studio_disabled_keeps_only_the_existing_social_schedule(): void
    {
        $commands = $this->scheduledCommands('all', false);

        $this->assertCount(3, $this->names($commands, 'social'));
        $this->assertSame([], $this->names($commands, 'content'));
    }

    public function test_content_scope_never_schedules_social(): void
    {
        $commands = $this->scheduledCommands('content', true);

        $this->assertSame([], $this->names($commands, 'social'));
        $this->assertCount(1, array_filter($commands, fn ($c) => str_contains($c, 'content:recover')));
    }

    public function test_content_scope_is_empty_while_the_feature_flag_is_off(): void
    {
        $this->assertSame([], $this->scheduledCommands('content', false));
    }

    public function test_all_scope_with_studio_enabled_schedules_both(): void
    {
        $commands = $this->scheduledCommands('all', true);

        $this->assertCount(3, $this->names($commands, 'social'));
        $this->assertNotEmpty($this->names($commands, 'content'));
    }

    public function test_every_content_entry_runs_on_one_server_only(): void
    {
        // Both schedulers (legacy `all` + `content`) register content:* from the same .env.
        $content = array_filter($this->scheduledEvents('all', true), fn ($e) => str_contains((string) $e->command, 'content:'));

        $this->assertNotEmpty($content);
        foreach ($content as $event) {
            $this->assertTrue($event->onOneServer, "{$event->command} must use onOneServer()");
        }
    }

    public function test_media_queue_connection_outlives_its_worker_timeout(): void
    {
        $workerTimeout = 1800; // docker-compose.content.yml (content-media, Phase 2)

        $this->assertGreaterThan($workerTimeout, config('queue.connections.redis-media.retry_after'));
        $this->assertSame(config('queue.connections.redis.connection'), config('queue.connections.redis-media.connection'), 'same Redis');
        $this->assertLessThan(config('queue.connections.redis.retry_after'), 180, 'content-queue timeout < retry_after');
    }
}
