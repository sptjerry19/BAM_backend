<?php

namespace App\Console\Commands\ContentStudio;

use App\Console\Commands\ContentStudio\Concerns\FindsContentItem;
use App\Services\ContentStudio\Quality\QualityGate;
use Illuminate\Console\Command;

class QualityCheckContent extends Command
{
    use FindsContentItem;

    protected $signature = 'content:qc {--content-id= : Content item id}';

    protected $description = 'Run the quality gate on one item and print every check (changes nothing)';

    public function handle(QualityGate $gate): int
    {
        if (($item = $this->contentItem()) === null) {
            return self::FAILURE;
        }

        $report = $gate->evaluate($item);
        foreach ($report->toArray()['checks'] as $check) {
            $line = sprintf('%-4s %-16s %s', $check['passed'] ? 'ok' : strtoupper($check['severity']), $check['code'], $check['passed'] ? '' : $check['message']);
            $check['passed'] ? $this->line($line) : $this->warn($line);
        }
        $this->line('verdict: '.$report->verdict());

        return $report->verdict() === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
