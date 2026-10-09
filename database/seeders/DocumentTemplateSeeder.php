<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $salesUser = User::where('email', 'sales@mss.test')->first();
        $marketingUser = User::where('email', 'marketing@mss.test')->first();

        $templates = [
            ['code' => 'nda-standard', 'title' => 'Standard NDA', 'category' => 'nda', 'library' => 'sales', 'file' => 'sales/nda-standard.txt'],
            ['code' => 'mou-partnership', 'title' => 'MoU — Partnership', 'category' => 'mou', 'library' => 'sales', 'file' => 'sales/mou-partnership.txt'],
            ['code' => 'contract-services', 'title' => 'Master Services Contract', 'category' => 'contract', 'library' => 'sales', 'file' => 'sales/contract-services.txt'],
            ['code' => 'proposal-template', 'title' => 'Sales Proposal', 'category' => 'proposal', 'library' => 'sales', 'file' => 'sales/proposal-template.txt'],
            ['code' => 'brochure-product', 'title' => 'Product Brochure', 'category' => 'brochure', 'library' => 'marketing', 'file' => 'marketing/brochure-product.txt'],
        ];

        foreach ($templates as $t) {
            $path = 'document-templates/'.$t['file'];
            Storage::disk('local')->makeDirectory(dirname($path));
            if (!Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, $this->placeholderBody($t['title']));
            }

            $uploader = $t['library'] === 'marketing' ? $marketingUser : $salesUser;

            DocumentTemplate::updateOrCreate(
                ['code' => $t['code']],
                [
                    'title' => $t['title'],
                    'category' => $t['category'],
                    'library' => $t['library'],
                    'storage_path' => $path,
                    'uploaded_by' => $uploader?->id,
                    'allowed_user_ids' => null,
                    'is_active' => true,
                ]
            );
        }
    }

    private function placeholderBody(string $title): string
    {
        return "{$title}\n\nReplace this file with your organisation's approved template (Word/PDF) on the server.\n";
    }
}
