<?php

namespace App\Http\Controllers\Api\CapitalRivals;

use App\Exceptions\CapitalRivalsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CapitalRivals\BuyTileRequest;
use App\Http\Requests\CapitalRivals\CreateRoomRequest;
use App\Http\Requests\CapitalRivals\GameActionRequest;
use App\Http\Requests\CapitalRivals\JoinRoomRequest;
use App\Http\Requests\CapitalRivals\ReadyRoomRequest;
use App\Services\CapitalRivals\RoomService;
use App\Services\CapitalRivals\TurnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function __construct(
        private readonly RoomService $rooms,
        private readonly TurnService $turns,
    ) {}

    public function store(CreateRoomRequest $request): JsonResponse
    {
        $data = $this->rooms->create(
            $request->validated('name'),
            $request->user('api')?->id,
            $request->safe()->only(['entry_fee', 'winnings_target']),
        );

        return $this->success($data, __('messages.created'), 201);
    }

    public function join(JoinRoomRequest $request, string $code): JsonResponse
    {
        $token = $request->validated('player_token') ?? $this->token($request);
        $data = $this->rooms->join(
            $code,
            $request->validated('name'),
            $request->user('api')?->id,
            $token,
        );

        return $this->success($data);
    }

    public function action(GameActionRequest $request, string $code): JsonResponse
    {
        $data = $request->validated();

        return $this->success($this->turns->action($code, $this->requireToken($request), $data['action'], isset($data['tile_index']) ? (int) $data['tile_index'] : null));
    }

    public function ready(ReadyRoomRequest $request, string $code): JsonResponse
    {
        $data = $this->rooms->ready(
            $code,
            $this->requireToken($request),
            $request->boolean('ready', true),
        );

        return $this->success($data);
    }

    public function start(Request $request, string $code): JsonResponse
    {
        return $this->success($this->rooms->start($code, $this->requireToken($request)));
    }

    public function leave(Request $request, string $code): JsonResponse
    {
        return $this->success($this->rooms->leave($code, $this->requireToken($request)));
    }

    public function snapshot(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->tick($code, $this->token($request)));
    }

    public function roll(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->roll($code, $this->requireToken($request)));
    }

    public function buy(BuyTileRequest $request, string $code): JsonResponse
    {
        return $this->success($this->turns->buy($code, $this->requireToken($request), (int) $request->validated('level', 1)));
    }

    public function skip(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->skip($code, $this->requireToken($request)));
    }

    public function pay(Request $request, string $code): JsonResponse
    {
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');
        $key = is_string($key) && $key !== '' ? $key : null;

        return $this->success($this->turns->pay($code, $this->requireToken($request), $key));
    }

    public function card(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->card($code, $this->requireToken($request)));
    }

    public function upgrade(Request $request, string $code): JsonResponse
    {
        $tile = (int) $request->input('tile_index', $request->input('tile', -1));

        return $this->success($this->turns->upgrade($code, $this->requireToken($request), $tile));
    }

    public function endTurn(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->endTurn($code, $this->requireToken($request)));
    }

    public function bankrupt(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->bankrupt($code, $this->requireToken($request)));
    }

    public function tick(Request $request, string $code): JsonResponse
    {
        return $this->success($this->turns->tick($code, $this->token($request)));
    }

    private function token(Request $request): ?string
    {
        $header = $request->header('X-Player-Token');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $body = $request->input('player_token');

        return is_string($body) && $body !== '' ? $body : null;
    }

    private function requireToken(Request $request): string
    {
        $token = $this->token($request);
        if (! $token) {
            throw new CapitalRivalsException('unauthorized', 'Player token required.', 403);
        }

        return $token;
    }
}
