<?php

namespace Tests\Feature\Api;

use App\Events\InboxUpdated;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    protected function makeConversation(array $attrs = []): Conversation
    {
        return Conversation::create(array_merge([
            'channel' => Conversation::CHANNEL_VIBER,
            'external_id' => '1001',
            'customer_ref' => 'viber-user-1',
            'customer_name' => 'Test Customer',
            'status' => Conversation::STATUS_UNASSIGNED,
            'last_message_at' => now(),
        ], $attrs));
    }

    public function test_customer_service_role_is_seeded(): void
    {
        $this->seedRoles();

        $this->assertDatabaseHas('roles', ['code' => Role::CUSTOMER_SERVICE]);
        $this->assertDatabaseHas('users', ['email' => 'cs@mss.test']);
    }

    public function test_marketing_cannot_access_inbox(): void
    {
        $this->seedRoles();
        $marketing = User::where('email', 'marketing@mss.test')->first();

        $this->actingAs($marketing)
            ->getJson('/api/v1/inbox/conversations')
            ->assertStatus(403);
    }

    public function test_cs_agent_sees_unassigned_queue(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        $this->makeConversation();

        $this->actingAs($cs)
            ->getJson('/api/v1/inbox/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.customer_name', 'Test Customer');
    }

    public function test_cs_agent_can_claim_conversation(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        $conv = $this->makeConversation();

        $this->actingAs($cs)
            ->postJson("/api/v1/inbox/conversations/{$conv->id}/claim")
            ->assertOk()
            ->assertJsonPath('owner.id', $cs->id);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conv->id,
            'type' => ConversationEvent::TYPE_CLAIMED,
            'to_owner_id' => $cs->id,
        ]);
    }

    public function test_supervisor_can_assign_conversation(): void
    {
        $this->seedRoles();
        $supervisor = User::where('email', 'supervisor@mss.test')->first();
        $cs = User::where('email', 'cs@mss.test')->first();
        $conv = $this->makeConversation();

        $this->actingAs($supervisor)
            ->postJson("/api/v1/inbox/conversations/{$conv->id}/assign", ['user_id' => $cs->id])
            ->assertOk()
            ->assertJsonPath('owner.id', $cs->id);
    }

    public function test_cs_cannot_assign_to_others(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        $sales = User::where('email', 'sales@mss.test')->first();
        $conv = $this->makeConversation();

        $this->actingAs($cs)
            ->postJson("/api/v1/inbox/conversations/{$conv->id}/assign", ['user_id' => $sales->id])
            ->assertStatus(422);
    }

    public function test_webhook_ingests_message_with_valid_signature(): void
    {
        $this->seedRoles();
        config(['services.convymes.webhook_secret' => 'test-secret', 'broadcasting.default' => 'log']);
        Event::fake([InboxUpdated::class]);

        $payload = [
            'event' => 'message:in',
            'conversation' => [
                'id' => 42,
                'channel' => 'viber',
                'customerName' => 'Webhook User',
                'status' => 'unassigned',
            ],
            'message' => [
                'id' => 9001,
                'direction' => 'in',
                'text' => 'Hello from Viber',
                'createdAt' => now()->toIso8601String(),
            ],
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, 'test-secret');

        $this->call(
            'POST',
            '/api/v1/webhooks/convymes',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ConvyMes_Signature' => $signature],
            $body
        )->assertOk()->assertJsonPath('stored', true);

        $this->assertDatabaseHas('conversations', [
            'channel' => 'viber',
            'external_id' => '42',
            'customer_name' => 'Webhook User',
        ]);

        $this->assertDatabaseHas('conversation_messages', [
            'external_message_id' => '9001',
            'body' => 'Hello from Viber',
        ]);

        Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $e) => $e->reason === 'message');
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['services.convymes.webhook_secret' => 'test-secret']);

        $this->postJson('/api/v1/webhooks/convymes', [
            'event' => 'message:in',
            'conversation' => ['id' => 1, 'channel' => 'viber'],
            'message' => ['id' => 1, 'text' => 'hi'],
        ], ['X-ConvyMes-Signature' => 'bad-signature'])
            ->assertStatus(401);
    }

    public function test_webhook_dedupes_messages(): void
    {
        config(['services.convymes.webhook_secret' => 'test-secret']);
        $conv = $this->makeConversation(['external_id' => '55']);

        ConversationMessage::create([
            'conversation_id' => $conv->id,
            'channel' => 'viber',
            'direction' => 'in',
            'body' => 'Already here',
            'external_message_id' => '777',
            'sent_at' => now(),
        ]);

        $payload = [
            'event' => 'message:in',
            'conversation' => ['id' => 55, 'channel' => 'viber', 'customerName' => 'Dup'],
            'message' => ['id' => 777, 'direction' => 'in', 'text' => 'Already here', 'createdAt' => now()->toIso8601String()],
        ];
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, 'test-secret');

        $this->call(
            'POST',
            '/api/v1/webhooks/convymes',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ConvyMes_Signature' => $sig],
            $body
        )->assertOk()->assertJsonPath('stored', false);

        $this->assertEquals(1, ConversationMessage::where('external_message_id', '777')->count());
    }

    public function test_inbox_stats_returns_counters(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        $this->makeConversation();
        $this->makeConversation(['external_id' => '1002', 'owner_id' => $cs->id, 'status' => Conversation::STATUS_OPEN]);

        $this->actingAs($cs)
            ->getJson('/api/v1/inbox/stats')
            ->assertOk()
            ->assertJsonStructure(['total', 'unassigned', 'mine', 'by_channel', 'ownership']);
    }

    // ------------------------------------------------------------------
    // Gateway → CRM ownership mirroring (ConvyMes `conversation:assigned`)
    // ------------------------------------------------------------------

    /** Signed webhook call: HMAC over the raw body, exactly as crmRelay.js sends it. */
    protected function postSignedWebhook(array $payload, string $secret = 'test-secret'): \Illuminate\Testing\TestResponse
    {
        config(['services.convymes.webhook_secret' => $secret]);

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $secret);

        return $this->call(
            'POST',
            '/api/v1/webhooks/convymes',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ConvyMes_Signature' => $signature],
            $body
        );
    }

    protected function assignmentPayload(array $conversationOverrides = []): array
    {
        return [
            'event' => 'conversation:assigned',
            'conversation' => array_merge([
                'id' => 4242,
                'channel' => 'viber',
                'customerId' => 'viber-user-9',
                'customerName' => 'Gateway Assigned',
                'status' => 'open',
                'assignedTo' => null,
            ], $conversationOverrides),
            'previousAssignedTo' => null,
            'assignedBy' => 2,
        ];
    }

    public function test_gateway_assignment_mirrors_ownership(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        Setting::put('integrations.convymes.agent_map', [$cs->id => 77]);

        $this->postSignedWebhook($this->assignmentPayload(['assignedTo' => 77]))
            ->assertOk()
            ->assertJsonPath('stored', true)
            ->assertJsonPath('reason', 'assigned')
            ->assertJsonPath('owner_id', $cs->id);

        $conversation = Conversation::where('channel', 'viber')->where('external_id', '4242')->firstOrFail();

        $this->assertSame($cs->id, (int) $conversation->owner_id);
        $this->assertSame(Conversation::STATUS_OPEN, $conversation->status);
        $this->assertNotNull($conversation->claimed_at);

        $event = ConversationEvent::where('conversation_id', $conversation->id)
            ->where('type', ConversationEvent::TYPE_ASSIGNED)
            ->firstOrFail();

        $this->assertSame($cs->id, (int) $event->to_owner_id);
        $this->assertNull($event->from_owner_id);
        $this->assertNull($event->actor_id);
        $this->assertSame('gateway', $event->meta['source']);
        $this->assertSame(77, $event->meta['gateway_agent_id']);
    }

    public function test_gateway_assignment_ignores_unmapped_agent(): void
    {
        $this->seedRoles();
        Setting::put('integrations.convymes.agent_map', []);

        $this->postSignedWebhook($this->assignmentPayload(['assignedTo' => 999]))
            ->assertOk()
            ->assertJsonPath('stored', false)
            ->assertJsonPath('reason', 'agent_not_mapped');

        $conversation = Conversation::where('external_id', '4242')->firstOrFail();

        $this->assertNull($conversation->owner_id);
        // only the ingest event — no ownership event was invented for an unknown agent
        $this->assertSame(1, ConversationEvent::where('conversation_id', $conversation->id)->count());
    }

    public function test_gateway_assignment_is_idempotent(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        Setting::put('integrations.convymes.agent_map', [$cs->id => 77]);

        $payload = $this->assignmentPayload(['assignedTo' => 77]);

        $this->postSignedWebhook($payload)->assertOk()->assertJsonPath('stored', true);

        // replay (or the echo of a CRM-initiated push) must not duplicate the audit trail
        $this->postSignedWebhook($payload)
            ->assertOk()
            ->assertJsonPath('stored', false)
            ->assertJsonPath('reason', 'already_owner');

        $this->assertSame(1, ConversationEvent::where('type', ConversationEvent::TYPE_ASSIGNED)->count());
    }

    public function test_gateway_unassign_releases_ownership(): void
    {
        $this->seedRoles();
        $cs = User::where('email', 'cs@mss.test')->first();
        Setting::put('integrations.convymes.agent_map', [$cs->id => 77]);

        $this->postSignedWebhook($this->assignmentPayload(['assignedTo' => 77]))->assertOk();

        $this->postSignedWebhook($this->assignmentPayload(['assignedTo' => null, 'status' => 'unassigned']))
            ->assertOk()
            ->assertJsonPath('reason', 'released')
            ->assertJsonPath('owner_id', null);

        $conversation = Conversation::where('external_id', '4242')->firstOrFail();

        $this->assertNull($conversation->owner_id);
        $this->assertSame(Conversation::STATUS_UNASSIGNED, $conversation->status);

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversation->id,
            'type' => ConversationEvent::TYPE_RELEASED,
            'from_owner_id' => $cs->id,
            'to_owner_id' => null,
        ]);
    }

    public function test_gateway_assignment_rejects_invalid_payload(): void
    {
        $this->postSignedWebhook(['event' => 'conversation:assigned', 'conversation' => []])
            ->assertStatus(422);
    }
}
