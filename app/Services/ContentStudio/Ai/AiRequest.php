<?php

namespace App\Services\ContentStudio\Ai;

final class AiRequest
{
    /**
     * @param  array<string, mixed>  $input   data for the model. NEVER put secrets here.
     * @param  array<string, mixed>  $schema  strict JSON schema of the expected answer
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $model,
        public readonly string $instructions,
        public readonly array $input,
        public readonly string $schemaName,
        public readonly array $schema,
        public readonly ?string $promptVersion = null,
        public readonly ?int $contentItemId = null,
    ) {}
}
