<?php

namespace App\Services\CapitalRivals;

final class ChanceDeck
{
    /** @var string|null */
    public static $nextId = null;

    /**
     * Original Dispatch cards — not commercial-game text.
     *
     * @return array<string, array{title: string, text: string, kind: string, amount?: int, position?: int}>
     */
    public static function cards(): array
    {
        return [
            'grant_50' => [
                'title' => 'Harbor Grant',
                'text' => 'The harbor authority issues you a small grant.',
                'kind' => 'credit',
                'amount' => 50,
            ],
            'grant_100' => [
                'title' => 'Canal Dividend',
                'text' => 'A canal share pays a dividend.',
                'kind' => 'credit',
                'amount' => 100,
            ],
            'levy_50' => [
                'title' => 'Courier Fee',
                'text' => 'Pay a courier to reroute your cargo.',
                'kind' => 'debit',
                'amount' => 50,
            ],
            'levy_75' => [
                'title' => 'Inspection Levy',
                'text' => 'Pay an unscheduled inspection levy.',
                'kind' => 'debit',
                'amount' => 75,
            ],
            'recall_gate' => [
                'title' => 'Yard Summons',
                'text' => 'You are ordered to the Holding Yard.',
                'kind' => 'move',
                'position' => 10,
            ],
            'harbor_gate' => [
                'title' => 'Open Channel',
                'text' => 'Return to Harbor Gate and collect the start grant.',
                'kind' => 'go_start',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function shuffled(): array
    {
        $ids = array_keys(self::cards());
        shuffle($ids);

        return array_values($ids);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{id: string, title: string, text: string, kind: string, amount?: int, position?: int}
     */
    public static function draw(array &$state): array
    {
        if (is_string(self::$nextId) && self::$nextId !== '') {
            $id = self::$nextId;
            self::$nextId = null;
        } else {
            $pile = array_values($state['chance_draw'] ?? []);
            if ($pile === []) {
                $pile = self::shuffled();
            }
            $id = (string) array_shift($pile);
            $state['chance_draw'] = $pile;
        }

        $card = self::cards()[$id] ?? self::cards()['grant_50'];

        return ['id' => $id, ...$card];
    }
}
