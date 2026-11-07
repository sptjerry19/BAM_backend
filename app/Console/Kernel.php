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
        // Only jobs that exist in this phase. Plan / discover / publish / report
        // are added together with their commands in later phases.
        //
        // onOneServer(): the legacy `scheduler` container (scope `all`) and the
        // `content-scheduler` container share the same .env, so both register these
        // entries once the feature flag is on. The Redis lock makes each tick run once.
        $schedule->command('content:recover')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
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
