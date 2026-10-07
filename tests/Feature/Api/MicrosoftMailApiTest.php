<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\MicrosoftConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftMailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_send_lead_email_requires_microsoft_connection(): void
    {
        $this->seedRoles();
        config([
            'microsoft.client_id' => 'test-client',
            'microsoft.client_secret' => 'secret',
            'microsoft.tenant_id' => 'tenant',
        ]);

        $sales = User::where('email', 'sales@mss.test')->first();
        $lead = Lead::factory()->create([
            'owner_id' => $sales->id,
            'email' => 'prospect@example.com',
        ]);

        $this->actingAs($sales)
            ->postJson("/api/v1/leads/{$lead->id}/email", [
                'subject' => 'Hello',
                'body' => 'Test body',
            ])
            ->assertStatus(422);
    }

    public function test_send_lead_email_via_graph(): void
    {
        $this->seedRoles();
        config([
            'microsoft.client_id' => 'test-client',
            'microsoft.client_secret' => 'secret',
            'microsoft.tenant_id' => 'tenant',
            'microsoft.graph_base' => 'https://graph.microsoft.com/v1.0',
            'broadcasting.default' => 'null',
        ]);

        Http::fake([
            'graph.microsoft.com/*' => Http::response(['id' => 'msg-1'], 202),
        ]);

        $sales = User::where('email', 'sales@mss.test')->first();
        MicrosoftConnection::create([
            'user_id' => $sales->id,
            'mailbox_upn' => 'sales@company.com',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $lead = Lead::factory()->create([
            'owner_id' => $sales->id,
            'email' => 'prospect@example.com',
        ]);

        $this->actingAs($sales)
            ->postJson("/api/v1/leads/{$lead->id}/email", [
                'subject' => 'Proposal',
                'body' => 'Please review.',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMail'));
    }

    public function test_microsoft_status_endpoint(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)
            ->getJson('/api/v1/integrations/microsoft/status')
            ->assertOk()
            ->assertJsonStructure(['configured', 'connected']);
    }
}
