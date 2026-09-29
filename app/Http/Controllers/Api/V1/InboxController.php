<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inbox\ConversationIngestService;
use App\Domain\Inbox\ConversationQueryService;
use App\Domain\Inbox\ConversationService;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Single pane of glass over heterogeneous customer queries
 * (Facebook Page Messenger, Viber, LINE — extensible to more channels).
 */
class InboxController extends Controller
{
    public function __construct(
        protected ConversationQueryService $queries,
        protected ConversationService $conversations,
        protected ConversationIngestService $ingest
    ) {
    }

    /** GET /api/v1/inbox/conversations */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'channel' => 'nullable|string|max:30',
            'status' => 'nullable|in:unassigned,open,pending,closed',
            'owner' => 'nullable|string|max:20',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->queries->index($request->user(), $filters));
    }

    /** GET /api/v1/inbox/conversations/{conversation} — thread + ownership audit */
    public function show(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $conversation->load([
            'owner:id,name', 'team:id,name', 'lead:id,name,status', 'customer:id,client_id,name',
            'messages.sentBy:id,name',
            'events.actor:id,name', 'events.fromOwner:id,name', 'events.toOwner:id,name',
        ]);

        return response()->json([
            'conversation' => $conversation,
            'ownership_history' => $conversation->events
                ->whereIn('type', [
                    ConversationEvent::TYPE_CLAIMED,
                    ConversationEvent::TYPE_ASSIGNED,
                    ConversationEvent::TYPE_REASSIGNED,
                    ConversationEvent::TYPE_RELEASED,
                ])
                ->values(),
            'available_agents' => $this->availableAgents(),
        ]);
    }

    /** GET /api/v1/inbox/stats — per-channel volume + ownership leaderboard */
    public function stats(Request $request)
    {
        return response()->json($this->queries->stats($request->user()));
    }

    /** POST /api/v1/inbox/conversations/{conversation}/claim */
    public function claim(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $data = $request->validate(['note' => 'nullable|string|max:500']);

        try {
            $updated = $this->conversations->claim($conversation, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated->load('owner:id,name'));
    }

    /** POST /api/v1/inbox/conversations/{conversation}/assign */
    public function assign(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'note' => 'nullable|string|max:500',
        ]);

        $target = User::findOrFail($data['user_id']);

        try {
            $updated = $this->conversations->assignTo(
                $conversation,
                $request->user(),
                $target,
                $data['note'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated->load('owner:id,name'));
    }

    /** POST /api/v1/inbox/conversations/{conversation}/release */
    public function release(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $data = $request->validate(['note' => 'nullable|string|max:500']);

        try {
            $updated = $this->conversations->release($conversation, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated->load('owner:id,name'));
    }

    /** POST /api/v1/inbox/conversations/{conversation}/reply */
    public function reply(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $data = $request->validate(['text' => 'required|string|max:4000']);

        try {
            $message = $this->conversations->reply($conversation, $request->user(), $data['text']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($message->load('sentBy:id,name'), 201);
    }

    /** PUT /api/v1/inbox/conversations/{conversation}/status */
    public function updateStatus(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $data = $request->validate([
            'status' => 'required|in:unassigned,open,pending,closed',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $updated = $this->conversations->changeStatus(
                $conversation,
                $request->user(),
                $data['status'],
                $data['note'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($updated);
    }

    /** POST /api/v1/inbox/conversations/{conversation}/link */
    public function link(Request $request, Conversation $conversation)
    {
        $this->authorizeView($request, $conversation);

        $data = $request->validate([
            'lead_id' => 'nullable|exists:leads,id',
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        $updated = $this->conversations->link($conversation, $request->user(), $data);

        return response()->json($updated);
    }

    /** POST /api/v1/inbox/sync — pull from ConvyMes (supervisor+ only) */
    public function sync(Request $request)
    {
        if (!$request->user()->isInboxManager()) {
            abort(403, 'Only supervisors can trigger a gateway sync.');
        }

        $withMessages = $request->boolean('messages', true);

        try {
            $stats = $this->ingest->syncFromGateway($withMessages);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gateway sync failed: ' . $e->getMessage()], 502);
        }

        return response()->json(['ok' => true, 'stats' => $stats]);
    }

    protected function authorizeView(Request $request, Conversation $conversation): void
    {
        $visible = $this->queries->visibleTo($request->user())
            ->where('conversations.id', $conversation->id)
            ->exists();

        abort_unless($visible, 403);
    }

    /** @return array<int, array{id: int, name: string}> */
    protected function availableAgents(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('code', Role::INBOX_AGENTS))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }
}
