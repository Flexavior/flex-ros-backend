<?php

namespace Tests\Feature\Api;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $this->seed(\Database\Seeders\DocumentTemplateSeeder::class);
    }

    public function test_sales_user_sees_allowed_templates(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)
            ->getJson('/api/v1/documents/templates')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'title', 'category']]]);
    }

    public function test_unauthorized_user_cannot_download_restricted_template(): void
    {
        $this->seedRoles();
        Storage::disk('local')->put('document-templates/secret.txt', 'secret');
        $template = DocumentTemplate::create([
            'code' => 'secret-only',
            'title' => 'Secret',
            'category' => 'nda',
            'storage_path' => 'document-templates/secret.txt',
            'allowed_user_ids' => [99999],
            'is_active' => true,
        ]);

        $staff = User::where('email', 'staff@mss.test')->firstOrFail();

        $this->actingAs($staff)
            ->getJson("/api/v1/documents/templates/{$template->id}/download")
            ->assertForbidden();
    }
}
