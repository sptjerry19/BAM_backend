<?php

namespace App\Console\Commands\ContentStudio;

use App\Enums\ContentStudio\RunStatus;
use App\Jobs\ContentStudio\SmokeStep;
use App\Models\ContentStudio\ContentPipelineRun;
use App\Services\ContentStudio\Pipeline\StepDispatcher;
use Illuminate\Console\Command;

class ContentSmokeTest extends Command
{
    protected $signature = 'content:smoke {--wait=30 : Seconds to wait for the worker}';

    protected $description = 'Send a no-op job through the content queue and wait for it (no AI, no network)';

    public function handle(StepDispatcher $dispatcher): int
    {
        $run = ContentPipelineRun::start(null, 'system.smoke');
        $dispatcher->dispatch($run, SmokeStep::class, [$run->id]);
        $this->info("Dispatched smoke run {$run->id}; waiting for a content-queue worker...");

        $deadline = time() + max(1, (int) $this->option('wait'));
        do {
            $run->refresh();
            if ($run->status->isFinished()) {
                break;
            }
            sleep(1);
        } while (time() < $deadline);

        if ($run->status === RunStatus::Succeeded) {
            $output = $run->steps()->where('step_name', 'smoke')->value('output_json');
            $this->info('OK  '.(is_string($output) ? $output : json_encode($output)));

            return self::SUCCESS;
        }

        $this->error("Not finished: status={$run->status->value}. Is the content-queue container running?");

        return self::FAILURE;
    }
}
