<?php

namespace App\Domain\Microsoft;

use App\Domain\Inbox\ConversationIngestService;
use App\Models\MicrosoftConnection;
use App\Models\User;
/**
 * Pulls a Graph message by id and mirrors it into CRM inbox (outlook channel).
 */
class OutlookIngestService
{
    public function __construct(
        protected GraphTokenService $tokens,
        protected ConversationIngestService $ingest,
        protected InternalTeamsNotifier $teams,
    ) {
    }

    public function ingestRemoteMessage(User $user, string $messageId): void
    {
        $response = $this->tokens->graph($user)->get('/me/messages/'.$messageId);
        if (!$response->ok()) {
            return;
        }

        $this->ingestGraphMessage($response->json(), $user);
    }

    /** @param array<string,mixed> $msg */
    public function ingestGraphMessage(array $msg, User $user): void
    {
        $from = $msg['from']['emailAddress']['address'] ?? 'unknown';
        $fromName = $msg['from']['emailAddress']['name'] ?? $from;
        $conversationId = $msg['conversationId'] ?? $msg['id'];
        $direction = ($msg['isDraft'] ?? false) ? null : 'in';

        if ($direction === null) {
            return;
        }

        // Skip messages sent by the connected user (outbound already logged on send)
        $connection = MicrosoftConnection::where('user_id', $user->id)->first();
        if ($connection?->mailbox_upn && strcasecmp($from, $connection->mailbox_upn) === 0) {
            return;
        }

        $this->ingest->ingestConversation([
            'channel' => \App\Models\Conversation::CHANNEL_OUTLOOK,
            'external_id' => (string) $conversationId,
            'customer_ref' => $from,
            'customer_name' => $fromName,
            'owner_id' => $user->id,
            'source' => 'graph_webhook',
        ]);

        $stored = $this->ingest->ingestMessage([
            'channel' => \App\Models\Conversation::CHANNEL_OUTLOOK,
            'conversation_external_id' => (string) $conversationId,
            'external_message_id' => (string) ($msg['id'] ?? ''),
            'direction' => 'in',
            'text' => $msg['bodyPreview'] ?? strip_tags($msg['body']['content'] ?? ''),
            'sender_name' => $fromName,
            'sender_ref' => $from,
            'sent_at' => $msg['receivedDateTime'] ?? now(),
            'source' => 'graph_webhook',
        ]);

        if ($stored) {
            $this->teams->notify(
                'New Outlook message in CRM inbox',
                sprintf('From %s — %s', $fromName, $msg['subject'] ?? '(no subject)')
            );
        }
    }
}
