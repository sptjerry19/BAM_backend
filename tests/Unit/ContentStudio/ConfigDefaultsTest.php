<?php

namespace Tests\Unit\ContentStudio;

use Tests\TestCase;

/**
 * Loads config/content-studio.php with specific environment values to prove how
 * empty / missing values are read. This is where a copied `.env.example` bites.
 *
 * It changes process-wide environment variables, so it records every original
 * value and puts it back in tearDown. (phpunit.xml forces several of these; losing
 * them would make later tests silently read the developer's real .env.)
 */
class ConfigDefaultsTest extends TestCase
{
    /** @var array<string, array{getenv: string|false, env: array{0: bool, 1: mixed}, server: array{0: bool, 1: mixed}}> */
    private array $original = [];

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $o) {
            $o['getenv'] === false ? putenv($name) : putenv("{$name}={$o['getenv']}");
            $o['env'][0] ? $_ENV[$name] = $o['env'][1] : $this->forget($_ENV, $name);
            $o['server'][0] ? $_SERVER[$name] = $o['server'][1] : $this->forget($_SERVER, $name);
        }
        $this->original = [];
        parent::tearDown();
    }

    /** @param array<string, mixed> $bag */
    private function forget(array &$bag, string $name): void
    {
        unset($bag[$name]);
    }

    /** @param array<string, string|null> $env @return array<string, mixed> */
    private function load(array $env): array
    {
        foreach ($env as $name => $value) {
            $this->original[$name] ??= [
                'getenv' => getenv($name),
                'env' => [array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
                'server' => [array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
            ];

            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }

        return require config_path('content-studio.php');
    }

    public function test_empty_or_missing_prices_mean_unknown_never_zero(): void
    {
        foreach ([null, ''] as $blank) {
            $config = $this->load(['OPENAI_PRICE_INPUT_PER_MTOK' => $blank, 'OPENAI_PRICE_OUTPUT_PER_MTOK' => $blank]);

            $this->assertNull($config['ai']['pricing']['input_per_mtok'], 'blank input price must not become $0');
            $this->assertNull($config['ai']['pricing']['output_per_mtok']);
        }

        $config = $this->load(['OPENAI_PRICE_INPUT_PER_MTOK' => '2.5', 'OPENAI_PRICE_OUTPUT_PER_MTOK' => '10']);
        $this->assertSame([2.5, 10.0], [$config['ai']['pricing']['input_per_mtok'], $config['ai']['pricing']['output_per_mtok']]);
    }

    public function test_an_explicit_zero_price_is_respected(): void
    {
        $config = $this->load(['OPENAI_PRICE_INPUT_PER_MTOK' => '0', 'OPENAI_PRICE_OUTPUT_PER_MTOK' => '0']);

        $this->assertSame([0.0, 0.0], [$config['ai']['pricing']['input_per_mtok'], $config['ai']['pricing']['output_per_mtok']], 'someone really can set 0 (a free tier)');
    }

    public function test_an_empty_storage_disk_falls_back_to_the_default_disk(): void
    {
        $config = $this->load(['CONTENT_STORAGE_DISK' => '', 'FILESYSTEM_DISK' => 'local']);
        $this->assertSame('local', $config['storage']['disk']);

        $config = $this->load(['CONTENT_STORAGE_DISK' => 's3', 'FILESYSTEM_DISK' => 'local']);
        $this->assertSame('s3', $config['storage']['disk']);
    }

    public function test_everything_that_spends_money_or_posts_is_off_by_default(): void
    {
        $config = $this->load([
            'CONTENT_STUDIO_ENABLED' => null, 'CONTENT_AI_ENABLED' => null, 'CONTENT_AUTOPUBLISH_ENABLED' => null,
            'CONTENT_AUTOMATION_PAUSED' => null, 'OPENAI_API_KEY' => null,
        ]);

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['ai']['enabled']);
        $this->assertFalse($config['autopublish_enabled']);
    }

    public function test_this_test_class_does_not_leak_its_changes(): void
    {
        $before = [getenv('CONTENT_STUDIO_ENABLED'), $_ENV['CONTENT_STUDIO_ENABLED'] ?? null];
        $this->load(['CONTENT_STUDIO_ENABLED' => null]);
        $this->tearDown();

        $this->assertSame($before, [getenv('CONTENT_STUDIO_ENABLED'), $_ENV['CONTENT_STUDIO_ENABLED'] ?? null], 'phpunit.xml\'s forced value is restored');
    }
}
