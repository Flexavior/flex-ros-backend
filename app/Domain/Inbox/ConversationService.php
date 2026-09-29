<?php

namespace App\Domain\Inbox;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Conversation flow control: who takes ownership, who it is handed to,
 * and the audit trail behind every transition.
 */
class ConversationService
{
    public function __construct(
        protected ConvymesClient $client,
        protected InboxBroadcastService $broadcast,
    ) {
    }

    /** An agent takes ownership of an unowned (or handed-back) conversation. */
    public function claim(Conversation $conversation, User $user, ?string $note = null): Conversation
    {
        if (!$user->isInboxAgent()) {
            throw new RuntimeException('Your role cannot own conversations.');
        }

        if (!$conversation->isUnowned() && $conversation->owner_id !== $user->id) {
            throw new RuntimeException('Conversation is already owned by ' . ($conversation->owner?->name ?? 'another agent') . '.');
        }

        return $this->transferOwnership($conversation, $user, $user, ConversationEvent::TYPE_CLAIMED, $note);
    }

    /** Assign/reassign to a designated user (supervisor and above). */
    public function assignTo(Conversation $conversation, User $actor, User $target, ?string $note = null): Conversation
    {
        if (!$actor->isInboxManager()) {
            throw new RuntimeException('Only supervisor or senior management can assign conversations to others.');
        }

        if (!$target->isInboxAgent()) {
            throw new RuntimeException($target->name . ' cannot own conversations with role ' . ($target->role?->code ?? 'none') . '.');
        }

        $type = $conversation->isUnowned() ? ConversationEvent::TYPE_ASSIGNED : ConversationEvent::TYPE_REASSIGNED;

        return $this->transferOwnership($conversation, $actor, $target, $type, $note);
    }

    /** Hand the conversation back to the shared queue. */
    public function release(Conversation $conversation, User $actor, ?string $note = null): Conversation
    {
        $isOwner = $conversation->owner_id === $actor->id;

        if (!$isOwner && !$actor->isInboxManager()) {
            throw new RuntimeException('Only the owner or a supervisor can release this conversation.');
        }

        $previousOwner = $conversation->owner_id;

        DB::transaction(function () use ($conversation, $actor, $previousOwner, $note) {
            $conversation->update([
                'owner_id' => null,
                'claimed_at' => null,
                'status' => Conversation::STATUS_UNASSIGNED,
            ]);

            $this->recordEvent($conversation, ConversationEvent::TYPE_RELEASED, $actor, $previousOwner, null, $note);
        });

        // Reflect the release in the gateway so its own inbox stays consistent (best effort)
        $this->pushAssignment($conversation, null);

        $fresh = $conversation->fresh(['owner']);
        $this->broadcast->notify($fresh->id, 'ownership');

        return $fresh;
    }

    /** Change the lifecycle status (open / pending / closed). */
    public function changeStatus(Conversation $conversation, User $actor, string $status, ?string $note = null): Conversation
    {
        if (!in_array($status, [
            Conversation::STATUS_UNASSIGNED,
            Conversation::STATUS_OPEN,
            Conversation::STATUS_PENDING,
            Conversation::STATUS_CLOSED,
        ], true)) {
            throw new RuntimeException('Unsupported conversation status: ' . $status);
        }

        if ($conversation->owner_id !== $actor->id && !$actor->isInboxManager()) {
            throw new RuntimeException('Only the owner or a supervisor can change this conversation status.');
        }

        $previous = $conversation->status;

        DB::transaction(function () use ($conversation, $actor, $status, $previous, $note) {
            $conversation->update(['status' => $status]);

            $this->recordEvent($conversation, ConversationEvent::TYPE_STATUS_CHANGED, $actor,
                $conversation->owner_id, $conversation->owner_id, $note,
                ['from' => $previous, 'to' => $status]);
        });

        $fresh = $conversation->fresh();
        $this->broadcast->notify($fresh->id, 'status');

        return $fresh;
    }

    /** Link the channel conversation to a CRM lead or customer record. */
    public function link(Conversation $conversation, User $actor, array $data): Conversation
    {
        $conversation->update([
            'lead_id' => $data['lead_id'] ?? $conversation->lead_id,
            'customer_id' => $data['customer_id'] ?? $conversation->customer_id,
        ]);

        $this->recordEvent($conversation, ConversationEvent::TYPE_LINKED, $actor,
            $conversation->owner_id, $conversation->owner_id, null, [
                'lead_id' => $conversation->lead_id,
                'customer_id' => $conversation->customer_id,
            ]);

        $fresh = $conversation->fresh(['lead', 'customer']);
        $this->broadcast->notify($fresh->id, 'link');

        return $fresh;
    }

    /**
     * Reply to the customer through the correct channel adapter (via ConvyMes),
     * then store the outbound message locally.
     */
    public function reply(Conversation $conversation, User $actor, string $text): ConversationMessage
    {
        if (!$actor->isInboxAgent()) {
            throw new RuntimeException('Your role cannot reply to conversations.');
        }

        if ($conversation->owner_id !== $actor->id && !$actor->isInboxManager()) {
            throw new RuntimeException('Claim the conversation before replying, or ask a supervisor to assign it to you.');
        }

        $result = $this->client->reply($conversation->external_id, $text);

        $message = DB::transaction(function () use ($conversation, $actor, $text, $result) {
            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'channel' => $conversation->channel,
                'direction' => 'out',
                'body' => $text,
                'sender_name' => $actor->name,
                'sender_ref' => (string) $actor->id,
                'external_message_id' => $result['message']['id'] ?? null,
                'sent_by' => $actor->id,
                'sent_at' => now(),
                'meta' => ['gateway' => 'convymes', 'platform_result' => $result['platformResult'] ?? null],
            ]);

            $conversation->update([
                'last_message_at' => now(),
                // First reply from a new owner moves the conversation into "open"
                'status' => $conversation->status === Conversation::STATUS_UNASSIGNED
                    ? Conversation::STATUS_OPEN
                    : $conversation->status,
            ]);

            $this->recordEvent($conversation, ConversationEvent::TYPE_REPLIED, $actor,
                $conversation->owner_id, $conversation->owner_id, null,
                ['message_id' => $message->id, 'channel' => $conversation->channel]);

            return $message;
        });

        $this->broadcast->notify($conversation->id, 'message');

        return $message;
    }

    /** Shared ownership transfer with audit + gateway push. */
    protected function transferOwnership(
        Conversation $conversation,
        User $actor,
        User $target,
        string $type,
        ?string $note
    ): Conversation {
        $previousOwner = $conversation->owner_id;

        DB::transaction(function () use ($conversation, $actor, $target, $previousOwner, $type, $note) {
            $conversation->update([
                'owner_id' => $target->id,
                'claimed_at' => $conversation->claimed_at ?? now(),
                'status' => Conversation::STATUS_OPEN,
                'unread_count' => 0,
            ]);

            $this->recordEvent($conversation, $type, $actor, $previousOwner, $target->id, $note);
        });

        $this->pushAssignment($conversation, $target);

        $fresh = $conversation->fresh(['owner', 'events.actor', 'events.toOwner']);
        $this->broadcast->notify($fresh->id, 'ownership');

        return $fresh;
    }

    /** Best-effort mirror of the assignment inside the gateway (never blocks CRM flow). */
    protected function pushAssignment(Conversation $conversation, ?User $target): void
    {
        try {
            if (!$this->client->enabled()) {
                return;
            }

            $agentId = $target ? $this->gatewayAgentId($target) : null;
            if ($target && $agentId === null) {
                return; // no mapping configured for this CRM user — keep CRM-only ownership
            }

            $this->client->assign($conversation->external_id, $agentId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Map a CRM user to the gateway agent id when configured
     * (setting `integrations.convymes.agent_map` = {"<crm_user_id>": <gateway_agent_id>}).
     */
    protected function gatewayAgentId(User $user): ?int
    {
        $map = \App\Models\Setting::get('integrations.convymes.agent_map', []);

        return isset($map[$user->id]) ? (int) $map[$user->id] : null;
    }

    protected function recordEvent(
        Conversation $conversation,
        string $type,
        ?User $actor,
        ?int $fromOwnerId,
        ?int $toOwnerId,
        ?string $note = null,
        ?array $meta = null
    ): ConversationEvent {
        return ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'type' => $type,
            'actor_id' => $actor?->id,
            'from_owner_id' => $fromOwnerId,
            'to_owner_id' => $toOwnerId,
            'note' => $note,
            'meta' => $meta,
        ]);
    }
}

