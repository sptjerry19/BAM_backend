<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * CONTENT_SCHEDULE_SCOPE decides who is scheduled:
     *   all (default) - social:* and content:*  -> unchanged for existing setups
     *   content       - content:* only          -> the content-scheduler container
     *   social        - social:* only
     */
    protected function schedule(Schedule $schedule): void
    {
        $scope = (string) config('content-studio.schedule_scope', 'all');

        if (in_array($scope, ['all', 'social'], true)) {
            $this->scheduleSocial($schedule);
        }

        if (in_array($scope, ['all', 'content'], true) && config('content-studio.enabled')) {
            $this->scheduleContentStudio($schedule);
        }
    }

    private function scheduleSocial(Schedule $schedule): void
    {
        $schedule->command('social:outbox')->everySecond()->withoutOverlapping();
        $schedule->command('social:cleanup')->daily()->withoutOverlapping();
        $schedule->command('social:recover-videos')->everyFiveMinutes()->withoutOverlapping();
    }

    private function scheduleContentStudio(Schedule $schedule): void
    {
        // Only commands that exist. Publish / sync / report arrive with their phase.
        //
        // onOneServer(): the legacy `scheduler` container (scope `all`) and the
        // `content-scheduler` container share the same .env, so both register these
        // entries once the feature flag is on. The Redis lock makes each tick run once.
        $schedule->command('content:recover')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();

        // Discovery first, so the plan sees today's sources. Both in Asia/Ho_Chi_Minh.
        $tz = (string) config('content-studio.timezone');
        $schedule->command('content:discover')->dailyAt('05:50')->timezone($tz)->withoutOverlapping(30)->onOneServer();
        // Planning costs money: only scheduled when AI is explicitly enabled and a key exists.
        $schedule->command('content:plan --days-ahead=1')->dailyAt('06:00')->timezone($tz)->withoutOverlapping(60)->onOneServer()
            ->when(fn () => (bool) config('content-studio.ai.enabled') && (string) config('services.openai.key') !== '');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
