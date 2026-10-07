<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inbox\ConversationIngestService;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives real-time events pushed from ConvyMes (crmRelay.js).
 * No Sanctum auth — verified via HMAC signature instead.
 */
class ConvymesWebhookController extends Controller
{
    public function __invoke(Request $request, ConversationIngestService $ingest)
    {
        $this->verifySignature($request);

        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');

        return match ($event) {
            'message:in', 'message:out' => $this->handleMessage($ingest, $payload),
            'conversation:assigned' => $this->handleAssignment($ingest, $payload),
            default => response()->json(['ok' => true, 'ignored' => $event ?: 'unknown']),
        };
    }

    protected function handleMessage(ConversationIngestService $ingest, array $payload): Response
    {
        $conversation = $payload['conversation'] ?? [];
        $message = $payload['message'] ?? [];

        if (empty($conversation['id']) || empty($message)) {
            return response()->json(['ok' => false, 'error' => 'Invalid message payload'], 422);
        }

        $direction = ($message['direction'] ?? 'in') === 'out' ? 'out' : 'in';

        $ingest->ingestConversation([
            'channel' => $conversation['channel'] ?? 'unknown',
            'external_id' => (string) $conversation['id'],
            'customer_name' => $conversation['customerName'] ?? null,
            'status' => $conversation['status'] ?? null,
            'source' => 'webhook',
        ]);

        $stored = $ingest->ingestMessage([
            'channel' => $conversation['channel'] ?? 'unknown',
            'conversation_external_id' => (string) $conversation['id'],
            'external_message_id' => isset($message['id']) ? (string) $message['id'] : null,
            'direction' => $direction,
            'text' => $message['text'] ?? null,
            'sent_at' => $message['createdAt'] ?? now(),
            'source' => 'webhook',
        ]);

        return response()->json([
            'ok' => true,
            'stored' => $stored !== null,
            'message_id' => $stored?->id,
        ]);
    }

    /**
     * Gateway-side ownership change (ConvyMes `conversation:assigned`).
     * Always answers 200 (except for structurally invalid payloads) so the relay never retries forever.
     */
    protected function handleAssignment(ConversationIngestService $ingest, array $payload): Response
    {
        $conversation = $payload['conversation'] ?? [];

        if (empty($conversation['id'])) {
            return response()->json(['ok' => false, 'error' => 'Invalid assignment payload'], 422);
        }

        $result = $ingest->applyGatewayAssignment(
            conversationPayload: [
                'channel' => $conversation['channel'] ?? 'unknown',
                'external_id' => (string) $conversation['id'],
                'customer_ref' => $conversation['customerId'] ?? null,
                'customer_name' => $conversation['customerName'] ?? null,
                'status' => $conversation['status'] ?? null,
            ],
            gatewayAgentId: $conversation['assignedTo'] ?? null,
            meta: [
                'gateway_event' => 'conversation:assigned',
                'previous_gateway_agent_id' => $payload['previousAssignedTo'] ?? null,
                'gateway_actor_id' => $payload['assignedBy'] ?? null,
            ],
        );

        return response()->json([
            'ok' => true,
            'stored' => $result['handled'],
            'reason' => $result['reason'],
            'owner_id' => $result['owner_id'],
            'conversation_id' => $result['conversation']->id,
        ]);
    }

    protected function verifySignature(Request $request): void
    {
        $secret = Setting::get('integrations.convymes.webhook_secret')
            ?: config('services.convymes.webhook_secret');

        if (!$secret) {
            abort(503, 'ConvyMes webhook secret is not configured.');
        }

        $signature = $request->header('X-ConvyMes-Signature');
        if (!$signature) {
            abort(401, 'Missing webhook signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }
    }
}
