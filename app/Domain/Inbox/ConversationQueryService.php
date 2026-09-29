<?php

namespace App\Domain\Inbox;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role-aware single-pane queries over mirrored conversations.
 *
 * Visibility rules:
 *  - CEO / Senior Management / Admin : every conversation (drill-down allowed)
 *  - Supervisor                      : their team's conversations + the unassigned queue
 *  - Customer Service / Sales / Staff: conversations they own + the unassigned queue
 *                                      (so agents can pick up new customer queries)
 */
class ConversationQueryService
{
    public function visibleTo(User $user): Builder
    {
        $query = Conversation::query();

        if ($user->isOrgLevel() || $user->hasRole(\App\Models\Role::SUPERVISOR)) {
            $teamIds = $user->hasRole(\App\Models\Role::SUPERVISOR)
                ? $this->teamMemberIds($user)
                : null;

            return $query->where(function ($q) use ($user, $teamIds) {
                $q->whereNull('owner_id');
                if ($teamIds) {
                    $q->orWhereIn('owner_id', $teamIds);
                }
            });
        }

        // Agents see their own threads and the shared queue
        return $query->where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
              ->orWhereNull('owner_id');
        });
    }

    /** @param array<string,mixed> $filters channel, status, owner ('me'|'unassigned'|id), search */
    public function index(User $user, array $filters = [])
    {
        $query = $this->visibleTo($user)
            ->with(['owner:id,name', 'lead:id,name', 'customer:id,client_id,name'])
            ->withCount('messages');

        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (($filters['owner'] ?? null) === 'me') {
            $query->where('owner_id', $user->id);
        } elseif (($filters['owner'] ?? null) === 'unassigned') {
            $query->whereNull('owner_id');
        } elseif (!empty($filters['owner'])) {
            $query->where('owner_id', (int) $filters['owner']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', $term)
                  ->orWhere('customer_ref', 'like', $term)
                  ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $term));
            });
        }

        return $query->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Single-pane counters for the dashboard / inbox header:
     * per-channel volume, the unassigned queue and the ownership leaderboard.
     */
    public function stats(User $user): array
    {
        $base = fn () => $this->visibleTo($user);

        $byChannel = (clone $base())
            ->selectRaw('channel, COUNT(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->all();

        $openByChannel = (clone $base())
            ->open()
            ->selectRaw('channel, COUNT(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->all();

        $ownership = (clone $base())
            ->whereNotNull('owner_id')
            ->join('users', 'users.id', '=', 'conversations.owner_id')
            ->selectRaw('users.id as user_id, users.name as user_name, COUNT(*) as owned, SUM(CASE WHEN conversations.status = ? THEN 1 ELSE 0 END) as open_count', [Conversation::STATUS_OPEN])
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('owned')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'user_name' => $row->user_name,
                'owned' => (int) $row->owned,
                'open' => (int) $row->open_count,
            ])
            ->all();

        return [
            'total' => (clone $base())->count(),
            'unassigned' => (clone $base())->whereNull('owner_id')->count(),
            'mine' => (clone $base())->where('owner_id', $user->id)->count(),
            'closed' => (clone $base())->where('status', Conversation::STATUS_CLOSED)->count(),
            'by_channel' => $byChannel,
            'open_by_channel' => $openByChannel,
            'ownership' => $ownership,
            'last_message_at' => (clone $base())->max('last_message_at'),
        ];
    }

    protected function teamMemberIds(User $user): array
    {
        if (!$user->team_id) {
            return [$user->id];
        }

        return User::where('team_id', $user->team_id)->pluck('id')->all() ?: [$user->id];
    }
}
