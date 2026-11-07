<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * No token is sent, so these requests never reach the database.
 */
class UnauthenticatedApiTest extends TestCase
{
    public function test_protected_routes_answer_401_even_without_an_accept_header(): void
    {
        foreach (['/api/me', '/api/stats', '/api/admin/content-studio/overview'] as $path) {
            $this->get($path)->assertUnauthorized();
        }
    }

    public function test_protected_routes_answer_401_for_json_clients(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
