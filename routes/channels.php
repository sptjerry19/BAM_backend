<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('users.{id}', fn ($user, $id) => (int) $user->id === (int) $id, ['guards' => ['api']]);

Broadcast::channel('conversations.{id}', function ($user, $id) {
    try {
        $conversation = app(\App\Services\Social\ChatService::class)->participant((int) $id, $user->id);
        return $conversation->status === 'accepted'
            && ! app(\App\Services\Social\VisibilityService::class)->blocked($conversation->user_low, $conversation->user_high);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        return false;
    }
}, ['guards' => ['api']]);
