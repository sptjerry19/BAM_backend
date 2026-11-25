<?php

namespace App\Console\Commands\ContentStudio;

use App\Services\ContentStudio\Ai\AiException;
use App\Services\ContentStudio\Ai\MockAiClient;
use App\Services\ContentStudio\Ai\OpenAIGateway;
use App\Services\ContentStudio\Planning\ContentPlanner;
use App\Services\ContentStudio\Pipeline\ContentPipelineStarter;
use App\Services\ContentStudio\Planning\PlanResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PlanContent extends Command
{
    protected $signature = 'content:plan
        {--date= : Plan date YYYY-MM-DD (default: today + --days-ahead)}
        {--days-ahead=1 : Days ahead of today when --date is not given}
        {--dry-run : Validate and print, write nothing}
        {--mock-ai : Use the built-in mock instead of OpenAI (free, no key)}
        {--no-dispatch : Save the plan but do not start the production pipelines}';

    protected $description = 'Create the content plan for a day (one OpenAI call, validated, idempotent)';

    public function handle(ContentPlanner $planner, OpenAIGateway $gateway, MockAiClient $mock, ContentPipelineStarter $starter): int
    {
        $tz = (string) config('content-studio.timezone');
        $date = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), $tz)->startOfDay()
            : CarbonImmutable::now($tz)->addDays((int) $this->option('days-ahead'))->startOfDay();

        $useMock = (bool) $this->option('mock-ai');
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf('Plan for %s (%s) using %s%s', $date->toDateString(), $tz, $useMock ? 'MOCK AI' : 'OpenAI', $dryRun ? ' [dry-run]' : ''));
        if ($dryRun && ! $useMock) {
            $this->warn('--dry-run still calls OpenAI (and costs tokens). Add --mock-ai for a free run.');
        }

        try {
            $result = $planner->plan($date, $useMock ? $mock : $gateway, $dryRun);
        } catch (AiException $e) {
            $this->error("AI unavailable [{$e->kind}]: ".$e->getMessage());
            $this->line('Nothing was written.');

            return self::FAILURE;
        }

        $this->report($result);

        if ($result->status === PlanResult::CREATED && ! $this->option('no-dispatch')) {
            foreach ($result->items as $item) {
                $starter->start($item);
            }
            $this->line(sprintf('pipelines started: %d (a content-queue worker must be running)', count($result->items)));
        }

        // Nothing could be produced although the model was asked: surface it to schedulers.
        return $result->status === PlanResult::CREATED && $result->items === [] && $result->rejected !== []
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function report(PlanResult $r): void
    {
        $this->line('status: '.$r->status);

        if ($r->status === PlanResult::COMPLETE) {
            $this->info('Every slot for this date already has an item. Nothing to do (no AI call made).');
        }

        foreach ($r->items as $item) {
            $slot = is_array($item) ? $item['slot'] : $item->slot->value;
            $title = is_array($item) ? $item['title'] : $item->title;
            $slides = is_array($item) ? count($item['slides']) : count($item->script_json['slides'] ?? []);
            $id = is_array($item) ? '(proposed)' : '#'.$item->id;
            $this->info(sprintf('  + %-9s %s  %s  [%d slides]', $slot, $id, $title, $slides));
        }
        foreach ($r->rejected as $x) {
            $this->warn(sprintf('  - %-9s rejected: %s', $x['slot'], implode(', ', $x['violations'])));
        }
        foreach ($r->shortfalls as $s) {
            $this->warn(sprintf('  ! %-9s shortfall: %s', $s['slot'], $s['reason']));
        }

        if ($r->ai !== null) {
            $this->line(sprintf(
                'ai: model=%s tokens_in=%d tokens_out=%d cost=%s prompt=%s%s',
                $r->ai->model, $r->ai->tokensIn, $r->ai->tokensOut,
                $r->ai->costUsd === null ? 'unknown (set OPENAI_PRICE_*_PER_MTOK)' : sprintf('$%.4f', $r->ai->costUsd),
                $r->promptVersion, $r->ai->mock ? ' (mock)' : '',
            ));
        }
    }
}
