<?php

namespace Tests\Concerns;

use App\Services\CapitalRivals\DiceService;

trait CreatesCapitalRivalsMatch
{
    /**
     * @param  list<int>  $values
     */
    protected function scriptDice(array $values): void
    {
        $bag = (object) ['i' => 0, 'vals' => $values];
        DiceService::$generator = static function () use ($bag) {
            $v = $bag->vals[$bag->i] ?? 1;
            $bag->i++;

            return $v;
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected function startedPair(): array
    {
        $create = $this->postJson('/api/games/cr/rooms', ['name' => 'Host']);
        $code = $create->json('data.room.code');
        $host = $create->json('data.you.player_token');
        $join = $this->postJson("/api/games/cr/rooms/{$code}/join", ['name' => 'Guest']);
        $guest = $join->json('data.you.player_token');
        $this->postJson("/api/games/cr/rooms/{$code}/ready", ['ready' => true], ['X-Player-Token' => $guest]);
        $start = $this->postJson("/api/games/cr/rooms/{$code}/start", [], ['X-Player-Token' => $host]);
        $current = (int) $start->json('data.room.current_seat');
        $first = $current === 0 ? $host : $guest;
        $second = $current === 0 ? $guest : $host;

        return [$code, $first, $second];
    }
}
