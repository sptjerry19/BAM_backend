<?php

namespace App\Services\ContentStudio\Ai;

use App\Models\ContentStudio\ContentAiRun;
use Illuminate\Container\Attributes\Singleton;

/**
 * Application-side spending limits. Two independent caps so it never becomes a
 * silent no-op: a USD cap (only when prices are configured) and a token cap.
 * OpenAI Platform limits are still required; this only protects against loops.
 */
#[Singleton]
final class BudgetGuard
{
    private int $callsThisRun = 0;

    /** Call at the start of every command / job that may talk to the model. */
    public function startRun(): void
    {
        $this->callsThisRun = 0;
    }

    /** Count one HTTP call (retries included) against the per-run cap. */
    public function registerCall(): void
    {
        $max = (int) config('content-studio.ai.max_calls_per_run');
        if ($this->callsThisRun >= $max) {
            throw AiException::budget("Per-run limit of {$max} AI calls reached.");
        }
        $this->callsThisRun++;
    }

    public function assertAvailable(): void
    {
        $since = now(config('content-studio.timezone'))->startOfDay()->utc();
        $used = ContentAiRun::query()->where('status', 'ok')->where('created_at', '>=', $since);

        $budget = (float) config('content-studio.ai.daily_budget_usd');
        if ($this->pricingKnown() && $budget > 0) {
            $spent = (float) (clone $used)->sum('estimated_cost_usd');
            if ($spent >= $budget) {
                throw AiException::budget(sprintf('Daily AI budget of $%.2f is used up ($%.4f spent).', $budget, $spent));
            }
        }

        $tokenBudget = (int) config('content-studio.ai.daily_token_budget');
        if ($tokenBudget > 0) {
            $tokens = (int) (clone $used)->sum('tokens_in') + (int) (clone $used)->sum('tokens_out');
            if ($tokens >= $tokenBudget) {
                throw AiException::budget("Daily AI token budget of {$tokenBudget} is used up ({$tokens} used).");
            }
        }
    }

    public function pricingKnown(): bool
    {
        return config('content-studio.ai.pricing.input_per_mtok') !== null
            && config('content-studio.ai.pricing.output_per_mtok') !== null;
    }

    /** USD, or null when prices are not configured. */
    public function cost(int $tokensIn, int $tokensOut): ?float
    {
        if (! $this->pricingKnown()) {
            return null;
        }

        return ($tokensIn * (float) config('content-studio.ai.pricing.input_per_mtok')
            + $tokensOut * (float) config('content-studio.ai.pricing.output_per_mtok')) / 1_000_000;
    }
}
