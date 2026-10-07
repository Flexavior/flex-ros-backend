<?php

namespace App\Domain\Inbox;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors conversations/messages from ConvyMes into the CRM single pane.
 * Idempotent: repeated webhook deliveries or overlapping syncs never duplicate rows.
 */
class ConversationIngestService
{
    public function __construct(
        protected ConvymesClient $client,
        protected InboxBroadcastService $broadcast,
    ) {
    }

    /**
     * Ingest one conversation (from a webhook payload or a sync pull).
     *
     * @param array<string,mixed> $payload keys: channel, id|external_id, customer_id|customer_ref,
     *                                     customer_name, status, owner_id, last_message_at
     */
    public function ingestConversation(array $payload): Conversation
    {
        $channel = $this->normalizeChannel($payload['channel'] ?? 'unknown');
        $externalId = (string) ($payload['external_id'] ?? $payload['id'] ?? '');
        if ($externalId === '') {
            throw new \InvalidArgumentException('Conversation payload requires an id.');
        }

        $isNew = false;

        $conversation = DB::transaction(function () use ($channel, $externalId, $payload, &$isNew) {
            $conversation = Conversation::firstOrNew([
                'channel' => $channel,
                'external_id' => $externalId,
            ]);

            $isNew = !$conversation->exists;

            $conversation->fill([
                'customer_ref' => $payload['customer_ref'] ?? $payload['customerId'] ?? $conversation->customer_ref,
                'customer_name' => $payload['customer_name'] ?? $payload['customerName'] ?? $conversation->customer_name,
                'status' => $this->normalizeStatus($payload['status'] ?? null, $conversation->status),
                'last_message_at' => $payload['last_message_at'] ?? $conversation->last_message_at,
            ]);

            // Gateway-side assignment is surfaced as CRM ownership
            if (isset($payload['owner_id'])) {
                $conversation->owner_id = $payload['owner_id'];
            }

            $conversation->save();

            if ($isNew) {
                $this->recordEvent($conversation, ConversationEvent::TYPE_INGESTED, null, [
                    'channel' => $channel,
                    'source' => $payload['source'] ?? 'gateway',
                ]);
            }

            return $conversation;
        });

        if ($isNew) {
            $this->broadcast->notify($conversation->id, 'conversation');
        }

        return $conversation;
    }

    /**
     * Ingest a single message. Returns null when it was already known (dedupe).
     */
    public function ingestMessage(array $payload): ?ConversationMessage
    {
        $channel = $this->normalizeChannel($payload['channel'] ?? 'unknown');
        $externalMessageId = $payload['external_message_id'] ?? $payload['id'] ?? null;
        $direction = ($payload['direction'] ?? 'in') === 'out' ? 'out' : 'in';

        $conversation = null;
        if (!empty($payload['conversation_external_id'])) {
            $conversation = Conversation::where('channel', $channel)
                ->where('external_id', (string) $payload['conversation_external_id'])
                ->first();
        } elseif (!empty($payload['conversation_id'])) {
            $conversation = Conversation::find($payload['conversation_id']);
        }

        $conversation ??= $this->ingestConversation($payload);

        // Idempotency: skip messages we have already stored
        if ($externalMessageId) {
            $exists = ConversationMessage::where('channel', $conversation->channel)
                ->where('external_message_id', (string) $externalMessageId)
                ->exists();

            if ($exists) {
                return null;
            }
        }

        $message = DB::transaction(function () use ($conversation, $channel, $direction, $externalMessageId, $payload) {
            $sentAt = $payload['sent_at'] ?? $payload['created_at'] ?? now();

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'direction' => $direction,
                'body' => $payload['text'] ?? $payload['body'] ?? null,
                'sender_name' => $payload['sender_name'] ?? $conversation->customer_name,
                'sender_ref' => $payload['sender_ref'] ?? $conversation->customer_ref,
                'external_message_id' => $externalMessageId ? (string) $externalMessageId : null,
                'sent_at' => $sentAt,
                'meta' => $payload['meta'] ?? null,
            ]);

            $conversation->forceFill([
                'last_message_at' => $sentAt,
                'unread_count' => $direction === 'in'
                    ? $conversation->unread_count + 1
                    : $conversation->unread_count,
                // A fresh inbound query puts an unowned conversation back in the queue
                'status' => $direction === 'in' && $conversation->isUnowned()
                    ? Conversation::STATUS_UNASSIGNED
                    : $conversation->status,
            ])->save();

            return $message;
        });

        if ($message) {
            $this->broadcast->notify($message->conversation_id, 'message');
        }

        return $message;
    }

    /**
     * Pull every conversation (and optionally its thread) from the gateway.
     *
     * @return array{conversations: int, messages: int, skipped: int}
     */
    public function syncFromGateway(bool $withMessages = true): array
    {
        $stats = ['conversations' => 0, 'messages' => 0, 'skipped' => 0];

        foreach ($this->client->conversations() as $remote) {
            $conversation = $this->ingestConversation([
                'channel' => $remote['channel'] ?? 'unknown',
                'external_id' => $remote['id'] ?? null,
                'customer_ref' => $remote['customer_id'] ?? null,
                'customer_name' => $remote['customer_name'] ?? null,
                'status' => $remote['status'] ?? null,
                'last_message_at' => $remote['last_message_at'] ?? null,
                'source' => 'sync',
            ]);
            $stats['conversations']++;

            if (!$withMessages) {
                continue;
            }

            $thread = $this->client->messages($conversation->external_id);
            foreach ($thread['messages'] as $remoteMessage) {
                $stored = $this->ingestMessage([
                    'channel' => $conversation->channel,
                    'conversation_external_id' => $conversation->external_id,
                    'external_message_id' => $remoteMessage['id'] ?? null,
                    'direction' => ($remoteMessage['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                    'text' => $remoteMessage['text'] ?? null,
                    'sent_at' => $remoteMessage['created_at'] ?? null,
                    'source' => 'sync',
                ]);

                $stored ? $stats['messages']++ : $stats['skipped']++;
            }
        }

        if ($stats['conversations'] > 0 || $stats['messages'] > 0) {
            $this->broadcast->notifySyncComplete();
        }

        return $stats;
    }

    /**
     * Apply an ownership change performed inside the gateway UI (ConvyMes) to the CRM mirror.
     *
     * Idempotent: a replayed event, or an echo of a CRM-initiated assignment, changes nothing.
     * Unmapped gateway agents are ignored rather than guessed.
     *
     * @param  array<string,mixed>  $conversationPayload  gateway conversation fields
     * @return array{handled: bool, reason: string, owner_id: ?int, conversation: Conversation}
     */
    public function applyGatewayAssignment(array $conversationPayload, mixed $gatewayAgentId, array $meta = []): array
    {
        $conversation = $this->ingestConversation($conversationPayload + ['source' => 'webhook']);

        $hasAgent = !($gatewayAgentId === null || $gatewayAgentId === '');
        $userId = $hasAgent ? $this->crmUserIdForGatewayAgent($gatewayAgentId) : null;

        if ($hasAgent && $userId === null) {
            return [
                'handled' => false,
                'reason' => 'agent_not_mapped',
                'owner_id' => null,
                'conversation' => $conversation,
            ];
        }

        if ($conversation->owner_id === $userId) {
            return [
                'handled' => false,
                'reason' => 'already_owner',
                'owner_id' => $userId,
                'conversation' => $conversation,
            ];
        }

        $previousOwner = $conversation->owner_id;

        DB::transaction(function () use ($conversation, $userId, $gatewayAgentId, $previousOwner, $meta) {
            $conversation->fill([
                'owner_id' => $userId,
                'claimed_at' => $userId ? ($conversation->claimed_at ?: now()) : null,
                'status' => $userId
                    ? ($conversation->status === Conversation::STATUS_CLOSED ? Conversation::STATUS_OPEN : $conversation->status)
                    : Conversation::STATUS_UNASSIGNED,
            ])->save();

            ConversationEvent::create([
                'conversation_id' => $conversation->id,
                'type' => $this->assignmentEventType($previousOwner, $userId),
                'actor_id' => null,
                'from_owner_id' => $previousOwner,
                'to_owner_id' => $userId,
                'meta' => array_merge($meta, [
                    'source' => 'gateway',
                    'gateway_agent_id' => $gatewayAgentId,
                ]),
            ]);
        });

        $this->broadcast->notify($conversation->id, 'ownership');

        return [
            'handled' => true,
            'reason' => $this->assignmentEventType($previousOwner, $userId),
            'owner_id' => $userId,
            'conversation' => $conversation,
        ];
    }

    /** Same vocabulary as the CRM-initiated lifecycle: assigned / reassigned / released. */
    protected function assignmentEventType(?int $previousOwner, ?int $newOwner): string
    {
        if ($newOwner === null) {
            return ConversationEvent::TYPE_RELEASED;
        }

        return $previousOwner === null ? ConversationEvent::TYPE_ASSIGNED : ConversationEvent::TYPE_REASSIGNED;
    }

    /** Reverse of ConversationService::gatewayAgentId(): gateway agent id → CRM user id. */
    protected function crmUserIdForGatewayAgent(mixed $gatewayAgentId): ?int
    {
        if ($gatewayAgentId === null || $gatewayAgentId === '') {
            return null;
        }

        $map = Setting::get('integrations.convymes.agent_map', []);
        if (!is_array($map)) {
            return null;
        }

        foreach ($map as $crmUserId => $mappedAgentId) {
            if ((string) $mappedAgentId === (string) $gatewayAgentId) {
                return (int) $crmUserId;
            }
        }

        return null;
    }

    /** Gateway channel codes map 1:1; unknown channels are kept verbatim for forward compatibility. */
    public function normalizeChannel(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return match ($channel) {
            'fb', 'facebook', 'messenger', 'facebook_messenger' => Conversation::CHANNEL_FACEBOOK,
            'viber', 'viber_business' => Conversation::CHANNEL_VIBER,
            'line', 'line_official' => Conversation::CHANNEL_LINE,
            'outlook', 'office365', 'o365', 'microsoft_mail', 'email' => Conversation::CHANNEL_OUTLOOK,
            '' => 'unknown',
            default => $channel,
        };
    }

    protected function normalizeStatus(?string $status, ?string $fallback): string
    {
        $allowed = [
            Conversation::STATUS_UNASSIGNED,
            Conversation::STATUS_OPEN,
            Conversation::STATUS_PENDING,
            Conversation::STATUS_CLOSED,
        ];

        return in_array($status, $allowed, true) ? $status : ($fallback ?: Conversation::STATUS_UNASSIGNED);
    }

    /** Audit entry for ingest-time facts (ownership changes are recorded by ConversationService). */
    protected function recordEvent(Conversation $conversation, string $type, ?int $actorId, array $meta = []): ConversationEvent
    {
        return ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'type' => $type,
            'actor_id' => $actorId,
            'from_owner_id' => null,
            'to_owner_id' => $conversation->owner_id,
            'meta' => $meta ?: null,
        ]);
    }
}

