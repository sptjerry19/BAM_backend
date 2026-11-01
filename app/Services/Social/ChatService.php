<?php

namespace App\Services\Social;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public function __construct(private readonly VisibilityService $visibility) {}

    public function participant(int $id, int $user): object
    {
        $conversation = DB::table('conversations')->find($id);
        abort_unless($conversation && in_array($user, [$conversation->user_low, $conversation->user_high], true)
            && DB::table('conversation_participants')->where('conversation_id', $id)->where('user_id', $user)->exists(), 403);

        return $conversation;
    }

    public function start(int $target): array
    {
        $user = $this->visibility->viewer()->id;
        User::findOrFail($target);
        abort_if($target === $user || $this->visibility->blocked($user, $target), 403);
        $id = DB::transaction(function () use ($user, $target) {
            User::whereIn('id', [$user, $target])->orderBy('id')->lockForUpdate()->get();
            $pair = ['user_low' => min($user, $target), 'user_high' => max($user, $target)];
            $conversation = DB::table('conversations')->where($pair)->first();
            if ($conversation) {
                return $conversation->id;
            }
            $id = DB::table('conversations')->insertGetId($pair + ['requested_by' => $user,
                'status' => $this->visibility->friends($user, $target) ? 'accepted' : 'pending', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversation_participants')->insert([
                ['conversation_id' => $id, 'user_id' => $user], ['conversation_id' => $id, 'user_id' => $target],
            ]);

            return $id;
        });

        return $this->summary($this->participant($id, $user), $user);
    }

    public function summary(object $c, int $user): array
    {
        $peerId = $c->user_low === $user ? $c->user_high : $c->user_low;
        $peer = User::with('member')->findOrFail($peerId);
        $read = DB::table('conversation_participants')->where('conversation_id', $c->id)->pluck('last_read_id', 'user_id');

        return ['id' => $c->id, 'status' => $c->status, 'requested_by' => $c->requested_by,
            'peer' => ['id' => $peerId, 'name' => $peer->name, 'avatar' => $peer->member?->avatar],
            'blocked' => $this->visibility->blocked($user, $peerId), 'my_read_id' => $read[$user] ?? 0,
            'peer_read_id' => $read[$peerId] ?? 0,
            'unread' => DB::table('messages')->where('conversation_id', $c->id)->where('user_id', '!=', $user)->where('id', '>', $read[$user] ?? 0)->count()];
    }

    public function conversations(array $filters): array
    {
        $user = $this->visibility->viewer()->id;
        $page = DB::table('conversations')->where(fn ($q) => $q->where('user_low', $user)->orWhere('user_high', $user))
            ->orderByDesc('updated_at')->orderByDesc('id')->simplePaginate(20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => array_map(fn ($c) => $this->summary($c, $user), $page->items()), 'has_more' => $page->hasMorePages()];
    }

    public function messages(int $id, array $filters): array
    {
        $user = $this->visibility->viewer()->id;
        $conversation = $this->participant($id, $user);
        $query = DB::table('messages')->where('conversation_id', $id);
        if (isset($filters['after'])) {
            $query->where('id', '>', $filters['after'])->orderBy('id');
        } else {
            $query->when($filters['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))->orderByDesc('id');
        }
        $rows = $query->limit(51)->get();
        $items = $rows->take(50)->sortBy('id')->values();
        $media = DB::table('media_assets')->whereIn('message_id', $items->pluck('id'))->get()->groupBy('message_id');

        return ['data' => $items->map(fn ($m) => array_merge((array) $m, ['media' => ($media[$m->id] ?? collect())->map(fn ($a) => app(MediaService::class)->resource($a, $user))])),
            'has_more' => $rows->count() > 50, 'conversation' => $this->summary($conversation, $user)];
    }

    public function send(int $id, array $data): array
    {
        $user = $this->visibility->viewer()->id;
        if (empty(trim($data['body'] ?? '')) && empty($data['media_ids'])) {
            throw ValidationException::withMessages(['body' => [__('messages.social_content_required')]]);
        }

        return DB::transaction(function () use ($id, $data, $user) {
            $c = $this->participant($id, $user);
            User::whereIn('id', [$c->user_low, $c->user_high])->orderBy('id')->lockForUpdate()->get();
            $c = DB::table('conversations')->where('id', $id)->lockForUpdate()->first();
            $peer = $c->user_low === $user ? $c->user_high : $c->user_low;
            abort_if($this->visibility->blocked($user, $peer), 403);
            $existing = DB::table('messages')->where('user_id', $user)->where('client_message_id', $data['client_message_id'])->first();
            if ($existing) {
                abort_unless($existing->conversation_id === $id, 409);

                return (array) $existing;
            }
            abort_unless($c->status === 'accepted' || ($c->status === 'pending' && $c->requested_by === $user), 403);
            // A message request can contain only one initial message until accepted.
            abort_if($c->status === 'pending' && DB::table('messages')->where('conversation_id', $id)->exists(), 403);
            $message = DB::table('messages')->insertGetId(['conversation_id' => $id, 'user_id' => $user,
                'client_message_id' => $data['client_message_id'], 'body' => $data['body'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            app(MediaService::class)->attach($data['media_ids'] ?? [], $user, 'message', 'message_id', $message);
            DB::table('conversations')->where('id', $id)->update(['updated_at' => now()]);
            foreach ([$user, $peer] as $recipient) {
                $this->outbox($recipient, 'MessageSent', ['conversation_id' => $id, 'message_id' => $message]);
            }

            return (array) DB::table('messages')->find($message);
        });
    }

    public function accept(int $id): void
    {
        $user = $this->visibility->viewer()->id;
        $c = $this->participant($id, $user);
        abort_if($c->requested_by === $user || $this->visibility->blocked($c->user_low, $c->user_high), 403);
        DB::transaction(function () use ($id, $c) {
            DB::table('conversations')->where('id', $id)->update(['status' => 'accepted', 'updated_at' => now()]);
            foreach ([$c->user_low, $c->user_high] as $recipient) {
                $this->outbox($recipient, 'ConversationChanged', ['conversation_id' => $id]);
            }
        });
    }

    public function read(int $id, int $message): void
    {
        $user = $this->visibility->viewer()->id;
        $c = $this->participant($id, $user);
        abort_unless(DB::table('messages')->where('id', $message)->where('conversation_id', $id)->exists(), 422);
        DB::transaction(function () use ($id, $message, $user, $c) {
            $updated = DB::table('conversation_participants')->where('conversation_id', $id)->where('user_id', $user)
                ->where('last_read_id', '<', $message)->update(['last_read_id' => $message]);
            if ($updated && ! $this->visibility->blocked($c->user_low, $c->user_high)) {
                foreach ([$c->user_low, $c->user_high] as $recipient) {
                    $this->outbox($recipient, 'MessageRead', ['conversation_id' => $id, 'message_id' => $message, 'reader_id' => $user]);
                }
            }
        });
    }

    public function outbox(int $recipient, string $event, array $payload): void
    {
        DB::table('social_outbox')->insert(['user_id' => $recipient, 'event' => $event,
            'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
    }
}
