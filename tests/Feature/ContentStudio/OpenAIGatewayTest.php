<?php

namespace Tests\Feature\ContentStudio;

use App\Models\ContentStudio\ContentAiRun;
use App\Services\ContentStudio\Ai\AiException;
use App\Services\ContentStudio\Ai\AiRequest;
use App\Services\ContentStudio\Ai\BudgetGuard;
use App\Services\ContentStudio\Ai\OpenAIGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesContentStudioDatabase;
use Tests\TestCase;

class OpenAIGatewayTest extends TestCase
{
    use UsesContentStudioDatabase;

    private const KEY = 'sk-test-key-1234567890abcdef';

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'content-studio.ai.enabled' => true,
            'services.openai.key' => self::KEY,
            'services.openai.base_url' => 'https://api.openai.test/v1',
            'content-studio.ai.max_retries' => 2,
            'content-studio.ai.max_calls_per_run' => 12,
        ]);
    }

    /** A fresh HTTP factory per scenario: Http::fake() stubs accumulate and the first match wins. */
    private function fake(mixed $responses = null): void
    {
        Http::swap(new Factory());
        Http::fake($responses);
    }

    private function gateway(): OpenAIGateway
    {
        return app(OpenAIGateway::class)->useSleeper(function (int $ms) {
            $this->slept[] = $ms;
        });
    }

    private function request(array $input = ['hello' => 'world']): AiRequest
    {
        return new AiRequest('daily_plan', 'model-x', 'be careful', $input, 'daily_content_plan', ['type' => 'object'], 'planner-abc');
    }

    private function ok(array $payload = ['date' => '2026-11-08', 'items' => []], int $in = 1000, int $out = 500): array
    {
        return [
            'id' => 'resp_1', 'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
            ],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'total_tokens' => $in + $out],
        ];
    }

    private function kindOf(callable $fn): string
    {
        try {
            $fn();
        } catch (AiException $e) {
            return $e->kind;
        }

        return 'no-exception';
    }

    public function test_success_reads_the_message_item_after_a_reasoning_item_and_records_usage(): void
    {
        $this->fake(['*' => Http::response($this->ok(['date' => 'd', 'items' => [['slot' => 'news']]]))]);
        config(['content-studio.ai.pricing.input_per_mtok' => 2.0, 'content-studio.ai.pricing.output_per_mtok' => 8.0]);

        $result = $this->gateway()->generateStructured($this->request());

        $this->assertSame('news', $result->value['items'][0]['slot']);
        $this->assertSame([1000, 500, 'resp_1'], [$result->tokensIn, $result->tokensOut, $result->responseId]);
        $this->assertEqualsWithDelta(0.006, $result->costUsd, 1e-9);

        $run = ContentAiRun::sole();
        $this->assertSame(['ok', 'daily_plan', 'model-x', 'planner-abc', 1000, 500], [$run->status, $run->purpose, $run->model, $run->prompt_version, $run->tokens_in, $run->tokens_out]);
    }

    public function test_request_is_sent_the_safe_way(): void
    {
        $this->fake(['*' => Http::response($this->ok())]);

        $this->gateway()->generateStructured($this->request(['note' => 'Tiếng Việt có dấu']));

        Http::assertSent(function (Request $r) {
            $format = $r['text']['format'];

            return $r->url() === 'https://api.openai.test/v1/responses'
                && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $r['store'] === false
                && $r['model'] === 'model-x'
                && $r['instructions'] === 'be careful'
                && $format['type'] === 'json_schema' && $format['strict'] === true && $format['name'] === 'daily_content_plan'
                && is_string($r['input']) && str_contains($r['input'], 'Tiếng Việt có dấu')
                && ! str_contains($r['input'], self::KEY);
        });
    }

    public function test_disabled_or_missing_key_fails_closed_without_any_http_call(): void
    {
        $this->fake();

        config(['content-studio.ai.enabled' => false]);
        $this->assertSame('disabled', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));

        config(['content-studio.ai.enabled' => true, 'services.openai.key' => '']);
        $this->assertSame('disabled', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));

        Http::assertNothingSent();
    }

    public function test_a_credential_inside_the_input_is_never_sent(): void
    {
        $this->fake();

        $kind = $this->kindOf(fn () => $this->gateway()->generateStructured($this->request(['oops' => self::KEY])));
        $this->assertSame('permanent', $kind);
        $kind = $this->kindOf(fn () => $this->gateway()->generateStructured($this->request(['oops' => 'Authorization: Bearer abcdefghijklmnop123'])));
        $this->assertSame('permanent', $kind);

        Http::assertNothingSent();
    }

    public function test_rate_limit_is_retried_honouring_retry_after(): void
    {
        $this->fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'slow down']], 429, ['Retry-After' => '3'])
            ->push($this->ok())]);

        $this->gateway()->generateStructured($this->request());

        Http::assertSentCount(2);
        $this->assertCount(1, $this->slept);
        $this->assertGreaterThanOrEqual(3000, $this->slept[0], 'must wait at least Retry-After');
        $this->assertLessThanOrEqual(3500, $this->slept[0]);
        $this->assertSame(['error', 'ok'], ContentAiRun::orderBy('id')->pluck('status')->all());
    }

    public function test_insufficient_quota_is_permanent_and_not_retried(): void
    {
        $this->fake(['*' => Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'billing']], 429)]);

        $this->assertSame('permanent', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
        Http::assertSentCount(1);
        $this->assertSame([], $this->slept);
    }

    public function test_server_errors_are_retried_a_bounded_number_of_times(): void
    {
        $this->fake(['*' => Http::response(['error' => ['message' => 'boom']], 503)]);

        $this->assertSame('transient', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
        Http::assertSentCount(3); // 1 + max_retries(2)
        $this->assertCount(2, $this->slept);
    }

    public function test_auth_and_bad_request_errors_are_never_retried(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $this->fake(['*' => Http::response(['error' => ['message' => 'nope']], $status)]);
            $this->assertSame('permanent', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())), "HTTP {$status}");
            Http::assertSentCount(1); // exactly one call for this status: no retry
        }
        $this->assertSame([], $this->slept);
    }

    public function test_network_failure_is_transient(): void
    {
        $this->fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->assertSame('transient', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
        $this->assertCount(2, $this->slept);
    }

    public function test_refusal_is_not_retried(): void
    {
        $this->fake(['*' => Http::response(['id' => 'r', 'status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that']]],
        ]])]);

        $this->assertSame('refusal', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
        Http::assertSentCount(1);
    }

    public function test_incomplete_and_unparseable_output_are_invalid_output(): void
    {
        $this->fake(['*' => Http::response(['id' => 'r', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []])]);
        $this->assertSame('invalid_output', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));

        $this->fake(['*' => Http::response(['id' => 'r', 'status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{not json']]],
        ]])]);
        $this->assertSame('invalid_output', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));

        $this->fake(['*' => Http::response(['id' => 'r', 'status' => 'completed', 'output' => [['type' => 'reasoning']]])]);
        $this->assertSame('invalid_output', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
    }

    public function test_truncated_json_recovers_when_the_retry_is_good(): void
    {
        $this->fake(['*' => Http::sequence()
            ->push(['id' => 'r', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []])
            ->push($this->ok())]);

        $this->assertSame([], $this->gateway()->generateStructured($this->request())->value['items']);
        Http::assertSentCount(2);
    }

    public function test_per_run_call_cap_counts_retries_and_stops_a_loop(): void
    {
        config(['content-studio.ai.max_calls_per_run' => 2]);
        $this->fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->assertSame('budget', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
        Http::assertSentCount(2);

        // a new run resets the counter
        app(BudgetGuard::class)->startRun();
        $this->fake(['*' => Http::response($this->ok())]);
        $this->assertSame([], $this->gateway()->generateStructured($this->request())->value['items']);
    }

    public function test_daily_token_budget_blocks_before_any_call(): void
    {
        config(['content-studio.ai.daily_token_budget' => 1000]);
        ContentAiRun::create(['purpose' => 'x', 'model' => 'm', 'tokens_in' => 900, 'tokens_out' => 200, 'status' => 'ok']);
        $this->fake();

        $this->assertSame('budget', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
        Http::assertNothingSent();
    }

    public function test_daily_usd_budget_applies_only_when_prices_are_known(): void
    {
        config(['content-studio.ai.daily_token_budget' => 0, 'content-studio.ai.daily_budget_usd' => 0.5]);
        ContentAiRun::create(['purpose' => 'x', 'model' => 'm', 'tokens_in' => 1, 'tokens_out' => 1, 'estimated_cost_usd' => 0.6, 'status' => 'ok']);
        $this->fake(['*' => Http::response($this->ok())]);

        // prices unknown: the USD cap cannot be evaluated, the call goes through
        $this->assertSame([], $this->gateway()->generateStructured($this->request())->value['items']);

        config(['content-studio.ai.pricing.input_per_mtok' => 2.0, 'content-studio.ai.pricing.output_per_mtok' => 8.0]);
        $this->assertSame('budget', $this->kindOf(fn () => $this->gateway()->generateStructured($this->request())));
    }

    public function test_failed_calls_are_recorded_without_leaking_the_key(): void
    {
        $this->fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::KEY]], 401)]);

        $this->kindOf(fn () => $this->gateway()->generateStructured($this->request()));

        $run = ContentAiRun::sole();
        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('[permanent]', $run->error);
        $this->assertStringNotContainsString(self::KEY, (string) $run->error);
    }
}
