<?php

namespace App\Support\CapitalRivals;

final class MatchStatus
{
    public const WAITING_FOR_PLAYERS = 'WAITING_FOR_PLAYERS';
    public const READY = 'READY';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const WAITING_FOR_ACTION = 'WAITING_FOR_ACTION';
    public const RESOLVING_TILE = 'RESOLVING_TILE';
    public const WAITING_FOR_PAYMENT = 'WAITING_FOR_PAYMENT';
    public const BANKRUPTCY_RESOLUTION = 'BANKRUPTCY_RESOLUTION';
    public const TURN_FINISHED = 'TURN_FINISHED';
    public const FINISHED = 'FINISHED';
    public const CANCELLED = 'CANCELLED';
}
