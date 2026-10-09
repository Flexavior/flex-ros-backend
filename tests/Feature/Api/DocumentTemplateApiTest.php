<?php

namespace Tests\Feature\Api;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedAll(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $this->seed(\Database\Seeders\DocumentTemplateSeeder::class);
    }

    public function test_staff_can_list_and_download_sales_library(): void
    {
        $this->seedAll();
        $staff = User::where('email', 'staff@mss.test')->first();

        $this->actingAs($staff)
            ->getJson('/api/v1/documents/templates')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'library', 'title']]]);

        $template = DocumentTemplate::where('library', 'sales')->first();
        $this->actingAs($staff)
            ->get("/api/v1/documents/templates/{$template->id}/download")
            ->assertOk();
    }

    public function test_senior_staff_can_upload_to_sales_library(): void
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);

        $senior = User::where('email', 'senior.staff@mss.test')->first();
        $file = UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf');

        $this->actingAs($senior)
            ->postJson('/api/v1/documents/templates', [
                'title' => 'New Proposal',
                'category' => 'proposal',
                'library' => 'sales',
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonPath('data.library', 'sales');
    }

    public function test_marketing_role_can_upload_to_marketing_library_only(): void
    {
        Storage::fake('local');
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);

        $marketing = User::where('email', 'marketing@mss.test')->first();
        $file = UploadedFile::fake()->create('brochure.pdf', 50, 'application/pdf');

        $this->actingAs($marketing)
            ->postJson('/api/v1/documents/templates', [
                'title' => 'Campaign Brochure',
                'category' => 'brochure',
                'library' => 'marketing',
                'file' => $file,
            ])
            ->assertCreated();

        $this->actingAs($marketing)
            ->postJson('/api/v1/documents/templates', [
                'title' => 'Bad',
                'category' => 'proposal',
                'library' => 'sales',
                'file' => $file,
            ])
            ->assertForbidden();
    }

    public function test_admin_cannot_access_operational_crm_dashboard(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($admin)
            ->getJson('/api/v1/dashboard/metrics')
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/v1/documents/templates')
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/overview')
            ->assertOk();
    }
}
