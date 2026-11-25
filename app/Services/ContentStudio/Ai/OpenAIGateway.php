<?php

namespace App\Services\ContentStudio\Ai;

use App\Models\ContentStudio\ContentAiRun;
use App\Services\ContentStudio\Contracts\AiClient;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * OpenAI Responses API with Structured Outputs.
 *
 * - store=false, key only from server config, never sent inside the prompt
 * - reads the `output` array properly (reasoning / message / refusal items)
 * - retries only what can succeed on retry (429, 5xx, timeouts, truncated JSON),
 *   bounded, with Retry-After + jitter; every HTTP call counts against the per-run cap
 * - structured output proves the SHAPE only; callers must validate the content
 */
final class OpenAIGateway implements AiClient
{
    /** @var Closure(int): void */
    private Closure $sleeper;

    public function __construct(
        private readonly BudgetGuard $budget,
        private readonly ContentEventLogger $events,
    ) {
        $this->sleeper = static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** Tests replace the real sleep. @param Closure(int): void $sleeper milliseconds */
    public function useSleeper(Closure $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    public function generateStructured(AiRequest $request): AiResult
    {
        if (! config('content-studio.ai.enabled')) {
            throw AiException::disabled('AI is disabled (CONTENT_AI_ENABLED=false).');
        }
        $key = (string) config('services.openai.key');
        if ($key === '') {
            throw AiException::disabled('OPENAI_API_KEY is not set.');
        }

        $input = json_encode($request->input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (str_contains($input, $key) || preg_match('/Bearer\s+[A-Za-z0-9._-]{10,}/', $input)) {
            throw AiException::permanent('Refusing to send a request whose input contains a credential.');
        }

        $this->budget->assertAvailable();

        $maxRetries = (int) config('content-studio.ai.max_retries');
        $attempt = 0;

        while (true) {
            $attempt++;
            $this->budget->registerCall();

            try {
                return $this->callOnce($request, $key, $input);
            } catch (AiException $e) {
                $this->recordFailure($request, $e);
                if (! $e->retryable() || $attempt > $maxRetries) {
                    throw $e;
                }
                ($this->sleeper)($this->backoffMs($attempt, $e->retryAfterSeconds));
            }
        }
    }

    private function callOnce(AiRequest $request, string $key, string $input): AiResult
    {
        $payload = [
            'model' => $request->model,
            'store' => false,
            'instructions' => $request->instructions,
            'input' => $input,
            'max_output_tokens' => (int) config('content-studio.ai.max_output_tokens'),
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => $request->schemaName,
                'strict' => true,
                'schema' => $request->schema,
            ]],
        ];

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config('content-studio.ai.timeout_seconds'))
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/responses', $payload);
        } catch (ConnectionException $e) {
            throw AiException::transient('Network error talking to OpenAI: '.$this->events->scrub($e->getMessage()));
        }

        if (! $response->successful()) {
            throw $this->httpError($response);
        }

        return $this->parse($request, $response);
    }

    private function httpError(Response $response): AiException
    {
        $status = $response->status();
        $error = $response->json('error') ?? [];
        $code = (string) ($error['code'] ?? $error['type'] ?? '');
        $message = $this->events->scrub('OpenAI HTTP '.$status.($code !== '' ? " ({$code})" : '').': '.($error['message'] ?? 'no message'));

        if ($status === 429) {
            // "insufficient_quota" will not fix itself by waiting.
            if (str_contains($code, 'insufficient_quota')) {
                return AiException::permanent($message, $status);
            }
            $retryAfter = $response->header('Retry-After');

            return AiException::transient($message, $status, is_numeric($retryAfter) ? (int) $retryAfter : null);
        }
        if ($status === 408 || $status === 409 || $status >= 500) {
            return AiException::transient($message, $status);
        }

        // 400, 401, 403, 404, 422 ...: our request or credentials are wrong.
        return AiException::permanent($message, $status);
    }

    private function parse(AiRequest $request, Response $response): AiResult
    {
        $body = $response->json();
        if (! is_array($body)) {
            throw AiException::invalidOutput('OpenAI response was not JSON.');
        }

        $status = $body['status'] ?? null;
        if ($status === 'incomplete') {
            $reason = $body['incomplete_details']['reason'] ?? 'unknown';
            throw AiException::invalidOutput("OpenAI response incomplete ({$reason}).");
        }
        if ($status !== 'completed') {
            $detail = $body['error']['message'] ?? $status ?? 'no status';

            throw AiException::transient($this->events->scrub('OpenAI response not completed: '.$detail));
        }

        $texts = [];
        foreach ($body['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue; // reasoning and tool items carry no answer text
            }
            foreach ($item['content'] ?? [] as $part) {
                $type = $part['type'] ?? null;
                if ($type === 'refusal') {
                    throw AiException::refusal('The model refused: '.mb_substr((string) ($part['refusal'] ?? ''), 0, 200));
                }
                if ($type === 'output_text') {
                    $texts[] = (string) ($part['text'] ?? '');
                }
            }
        }

        $json = trim(implode('', $texts));
        if ($json === '') {
            throw AiException::invalidOutput('OpenAI returned no output text.');
        }

        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiException::invalidOutput('OpenAI output was not valid JSON.');
        }
        if (! is_array($value)) {
            throw AiException::invalidOutput('OpenAI output JSON was not an object.');
        }

        $in = (int) ($body['usage']['input_tokens'] ?? 0);
        $out = (int) ($body['usage']['output_tokens'] ?? 0);
        $cost = $this->budget->cost($in, $out);

        ContentAiRun::create([
            'content_item_id' => $request->contentItemId,
            'purpose' => $request->purpose,
            'model' => $request->model,
            'prompt_version' => $request->promptVersion,
            'response_id' => $body['id'] ?? null,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'estimated_cost_usd' => $cost ?? 0,
            'status' => 'ok',
        ]);

        return new AiResult($value, $request->model, $body['id'] ?? null, $in, $out, $cost);
    }

    private function recordFailure(AiRequest $request, AiException $e): void
    {
        try {
            ContentAiRun::create([
                'content_item_id' => $request->contentItemId,
                'purpose' => $request->purpose,
                'model' => $request->model,
                'prompt_version' => $request->promptVersion,
                'status' => 'error',
                'error' => mb_substr($this->events->scrub("[{$e->kind}] ".$e->getMessage()), 0, 500),
            ]);
        } catch (Throwable) {
            // Never let bookkeeping hide the real error.
        }
    }

    private function backoffMs(int $attempt, ?int $retryAfter): int
    {
        $exponential = 1000 * (2 ** ($attempt - 1));
        $wait = $retryAfter !== null ? max($retryAfter * 1000, $exponential) : $exponential;

        return min($wait, 60_000) + random_int(0, 500);
    }
}
