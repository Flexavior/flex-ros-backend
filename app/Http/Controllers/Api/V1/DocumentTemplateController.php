<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentTemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = DocumentTemplate::where('is_active', true)
            ->orderBy('category')
            ->orderBy('title')
            ->get();

        $user = $request->user();

        $visible = $templates->filter(fn (DocumentTemplate $t) => $t->userMayDownload($user))->values();

        return response()->json([
            'data' => $visible->map(fn (DocumentTemplate $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'category' => $t->category,
            ]),
        ]);
    }

    public function download(Request $request, DocumentTemplate $documentTemplate): StreamedResponse
    {
        abort_unless($documentTemplate->userMayDownload($request->user()), 403);

        $path = $documentTemplate->storage_path;
        abort_unless(Storage::disk('local')->exists($path), 404, 'Template file missing on server.');

        $filename = $documentTemplate->code.'.'.pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($path, $filename);
    }
}
