<?php

namespace App\Services\ContentStudio\Ops;

use App\Models\ContentStudio\ContentEvent;

/**
 * Append-only audit trail. Payloads are redacted before they are stored so
 * credentials never end up in the database or in logs.
 */
final class ContentEventLogger
{
    private const SENSITIVE_KEY = '/token|secret|password|passwd|authorization|api[_-]?key|cookie|credential/i';

    /** @param array<string, mixed> $payload */
    public function log(
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        array $payload = [],
        string $actor = 'system',
        ?string $traceId = null,
    ): ContentEvent {
        return ContentEvent::create([
            'trace_id' => $traceId,
            'actor' => $actor,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'payload_redacted' => $this->redact($payload) ?: null,
        ]);
    }

    /** Strip bearer tokens and API keys from free text (exception messages, etc.). */
    public function scrub(string $text): string
    {
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[redacted]', $text) ?? $text;

        return preg_replace('/\bsk-[A-Za-z0-9_-]{8,}/', '[redacted]', $text) ?? $text;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
