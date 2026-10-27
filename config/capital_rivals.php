<?php

return [
    'starting_cash' => (int) env('CR_STARTING_CASH', 1500),
    'start_reward' => (int) env('CR_START_REWARD', 200),
    'dice_count' => (int) env('CR_DICE_COUNT', 2),
    'dice_sides' => (int) env('CR_DICE_SIDES', 6),
    'turn_timeout' => (int) env('CR_TURN_TIMEOUT', 30),
    'max_turns' => (int) env('CR_MAX_TURNS', 0),
    'min_players' => 2,
    'max_players' => 4,
    'skins' => ['ace', 'ruby', 'kiwi', 'blaze'],
    'victory' => 'last_standing',
    'max_upgrade_level' => 4,
    'upgrade_cost_ratio' => 0.5,
];
