<?php

namespace App\Services\ContentStudio\Sources\Specs;

/** Minimal robots.txt reader: the `User-agent: *` group's Allow/Disallow prefixes (longest match wins). */
final class RobotsRules
{
    /** @param list<array{0: bool, 1: string}> $rules [allow?, path prefix] */
    private function __construct(private array $rules, public readonly ?int $crawlDelay) {}

    public static function parse(string $txt): self
    {
        $rules = [];
        $delay = null;
        $starGroup = false;   // are we inside a group that applies to "*"?
        $afterAgent = false;  // previous directive was a User-agent line (consecutive agents share a group)

        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);

            if ($k === 'user-agent') {
                $starGroup = $v === '*' || ($afterAgent && $starGroup);
                $afterAgent = true;

                continue;
            }
            $afterAgent = false;
            if (! $starGroup) {
                continue;
            }
            if ($k === 'disallow' && $v !== '') {
                $rules[] = [false, $v];
            } elseif ($k === 'allow' && $v !== '') {
                $rules[] = [true, $v];
            } elseif ($k === 'crawl-delay' && is_numeric($v)) {
                $delay = (int) ceil((float) $v);
            }
        }

        return new self($rules, $delay);
    }

    public function allows(string $path): bool
    {
        $allowed = true;
        $length = -1;
        foreach ($this->rules as [$allow, $prefix]) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            // longest prefix wins; on a tie Allow wins
            if (strlen($prefix) > $length || (strlen($prefix) === $length && $allow)) {
                $allowed = $allow;
                $length = strlen($prefix);
            }
        }

        return $allowed;
    }
}
