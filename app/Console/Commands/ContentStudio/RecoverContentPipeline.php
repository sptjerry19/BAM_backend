<?php

namespace App\Console\Commands\ContentStudio;

use App\Services\ContentStudio\Pipeline\PipelineRecovery;
use Illuminate\Console\Command;

class RecoverContentPipeline extends Command
{
    protected $signature = 'content:recover {--boot : Run right after start-up (re-send idle runs immediately)}';

    protected $description = 'Close expired step leases and re-send the next job of unfinished content runs';

    public function handle(PipelineRecovery $recovery): int
    {
        $report = $recovery->recover((bool) $this->option('boot'));

        $this->line(sprintf(
            'expired_steps=%d recovered_outputs=%d redispatched=%d rejected=%d%s',
            $report['expired_steps'], $report['recovered_outputs'], $report['redispatched'], $report['rejected'],
            $report['paused'] ? ' (automation paused: nothing re-sent)' : '',
        ));

        return self::SUCCESS;
    }
}
