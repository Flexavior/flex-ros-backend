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
