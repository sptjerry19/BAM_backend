<?php

namespace App\Services\Social;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class VisibilityService
{
    public function viewer(): ?User
    {
        if (request()->isMethod('GET') && request()->boolean('as_guest')) {
            return null;
        }

        return auth('api')->user();
    }

    public function blocked(int $a, ?int $b): bool
    {
        if (! $b || $a === $b) {
            return false;
        }

        return DB::table('user_blocks')->where(fn ($q) => $q->where('user_id', $a)->where('target_id', $b))
            ->orWhere(fn ($q) => $q->where('user_id', $b)->where('target_id', $a))->exists();
    }

    public function friends(int $a, ?int $b): bool
    {
        return $b && DB::table('friendships')->where('user_low', min($a, $b))->where('user_high', max($a, $b))->where('status', 'accepted')->exists();
    }

    public function allows(int $owner, string $audience, ?int $viewer): bool
    {
        if ($owner === $viewer) {
            return true;
        }
        if ($this->blocked($owner, $viewer)) {
            return false;
        }

        return $audience === 'public' || ($audience === 'friends' && $this->friends($owner, $viewer));
    }

    public function field(int $owner, string $field, ?int $viewer): bool
    {
        $default = in_array($field, ['email', 'phone', 'spend']) ? 'only_me' : 'public';
        $audience = DB::table('profile_privacy_settings')->where('user_id', $owner)->where('field', $field)->value('audience') ?? $default;

        return $this->allows($owner, $audience, $viewer);
    }

    public function scopePeople(Builder $query, ?int $viewer, string $column = 'user_id'): Builder
    {
        if ($viewer) {
            $query->whereNotIn($column, DB::table('user_blocks')->where('user_id', $viewer)->select('target_id'))
                ->whereNotIn($column, DB::table('user_blocks')->where('target_id', $viewer)->select('user_id'));
        }

        return $query;
    }

    /** SQL filtering happens before pagination, including block checks. */
    public function scope(Builder $query, ?int $viewer, string $table = 'posts'): Builder
    {
        if ($viewer) {
            $query->whereNotExists(function ($q) use ($viewer, $table) {
                $q->selectRaw('1')->from('user_blocks')->where(function ($q) use ($viewer, $table) {
                    $q->where(fn ($s) => $s->where('user_blocks.user_id', $viewer)->whereColumn('user_blocks.target_id', "$table.user_id"))
                        ->orWhere(fn ($s) => $s->where('user_blocks.target_id', $viewer)->whereColumn('user_blocks.user_id', "$table.user_id"));
                });
            });
        }

        return $query->where(function ($q) use ($viewer, $table) {
            $q->where("$table.audience", 'public');
            if ($viewer) {
                $q->orWhere("$table.user_id", $viewer)->orWhere(function ($s) use ($viewer, $table) {
                    $s->where("$table.audience", 'friends')->whereExists(function ($f) use ($viewer, $table) {
                        $f->selectRaw('1')->from('friendships')->where('status', 'accepted')->where(function ($p) use ($viewer, $table) {
                            $p->where(fn ($x) => $x->where('user_low', $viewer)->whereColumn('user_high', "$table.user_id"))
                                ->orWhere(fn ($x) => $x->where('user_high', $viewer)->whereColumn('user_low', "$table.user_id"));
                        });
                    });
                });
            }
        });
    }
}
