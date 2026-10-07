<?php

namespace App\Domain\Microsoft;

use App\Domain\Inbox\ConversationIngestService;
use App\Domain\Inbox\InboxBroadcastService;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Customer;
use App\Models\User;
use RuntimeException;

class GraphMailService
{
    public function __construct(
        protected GraphTokenService $tokens,
        protected ConversationIngestService $ingest,
        protected InboxBroadcastService $broadcast,
        protected InternalTeamsNotifier $teams,
    ) {
    }

    public function sendToLead(User $sender, Lead $lead, string $subject, string $body): array
    {
        if (!$lead->email) {
            throw new RuntimeException('Lead has no email address.');
        }

        return $this->send($sender, $lead->email, $lead->name, $subject, $body, [
            'lead_id' => $lead->id,
        ]);
    }

    public function sendToCustomer(User $sender, Customer $customer, string $subject, string $body): array
    {
        if (!$customer->email) {
            throw new RuntimeException('Customer has no email address.');
        }

        return $this->send($sender, $customer->email, $customer->name, $subject, $body, [
            'customer_id' => $customer->id,
        ]);
    }

    /** @param array{lead_id?: int, customer_id?: int} $link */
    protected function send(User $sender, string $toEmail, ?string $toName, string $subject, string $body, array $link): array
    {
        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => nl2br(e($body)),
                ],
                'toRecipients' => [[
                    'emailAddress' => [
                        'address' => $toEmail,
                        'name' => $toName,
                    ],
                ]],
            ],
            'saveToSentItems' => true,
        ];

        $this->tokens->graph($sender)->post('/me/sendMail', $payload)->throw();

        $conversation = $this->ingest->ingestConversation([
            'channel' => Conversation::CHANNEL_OUTLOOK,
            'external_id' => 'outbound-'.md5($toEmail.'|'.$subject.'|'.now()->timestamp),
            'customer_ref' => $toEmail,
            'customer_name' => $toName,
            'status' => Conversation::STATUS_OPEN,
            'owner_id' => $sender->id,
            'source' => 'graph_send',
        ]);

        if (!empty($link['lead_id'])) {
            $conversation->update(['lead_id' => $link['lead_id']]);
        }
        if (!empty($link['customer_id'])) {
            $conversation->update(['customer_id' => $link['customer_id']]);
        }

        $message = $this->ingest->ingestMessage([
            'channel' => Conversation::CHANNEL_OUTLOOK,
            'conversation_external_id' => $conversation->external_id,
            'direction' => 'out',
            'text' => $body,
            'sender_name' => $sender->name,
            'sender_ref' => (string) $sender->id,
            'external_message_id' => 'sent-'.uniqid(),
            'sent_at' => now(),
            'source' => 'graph_send',
        ]);

        $this->teams->notify(
            'Email sent from CRM',
            sprintf('%s emailed %s — %s', $sender->name, $toEmail, $subject)
        );

        return [
            'ok' => true,
            'conversation_id' => $conversation->id,
            'message_id' => $message?->id,
        ];
    }
}
