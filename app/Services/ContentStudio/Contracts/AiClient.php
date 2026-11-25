<?php

namespace App\Services\ContentStudio\Contracts;

use App\Services\ContentStudio\Ai\AiRequest;
use App\Services\ContentStudio\Ai\AiResult;

interface AiClient
{
    /**
     * Ask for a JSON document that matches $request->schema.
     *
     * @throws \App\Services\ContentStudio\Ai\AiException
     */
    public function generateStructured(AiRequest $request): AiResult;
}
