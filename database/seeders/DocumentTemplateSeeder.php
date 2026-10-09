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
        $dir = 'document-templates';
        Storage::disk('local')->makeDirectory($dir);

        $salesId = User::where('email', 'sales@mss.test')->value('id');

        $templates = [
            ['code' => 'nda-standard', 'title' => 'Standard NDA', 'category' => 'nda', 'file' => 'nda-standard.txt'],
            ['code' => 'mou-partnership', 'title' => 'MoU — Partnership', 'category' => 'mou', 'file' => 'mou-partnership.txt'],
            ['code' => 'contract-services', 'title' => 'Master Services Contract', 'category' => 'contract', 'file' => 'contract-services.txt'],
            ['code' => 'proposal-template', 'title' => 'Sales Proposal', 'category' => 'proposal', 'file' => 'proposal-template.txt'],
        ];

        foreach ($templates as $t) {
            $path = $dir.'/'.$t['file'];
            if (!Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, $this->placeholderBody($t['title']));
            }

            DocumentTemplate::updateOrCreate(
                ['code' => $t['code']],
                [
                    'title' => $t['title'],
                    'category' => $t['category'],
                    'storage_path' => $path,
                    'allowed_user_ids' => $salesId ? [$salesId] : [],
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
