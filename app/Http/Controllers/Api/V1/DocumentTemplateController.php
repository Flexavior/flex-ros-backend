<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\CrmConfigLimits;
use App\Domain\Documents\DocumentLibraryAccess;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentTemplateController extends Controller
{
    public function capabilities(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'can_access' => DocumentLibraryAccess::userMayAccessCrmModule($user),
            'upload_libraries' => DocumentLibraryAccess::uploadLibrariesFor($user),
            'categories' => DocumentLibraryAccess::CATEGORIES,
            'libraries' => DocumentLibraryAccess::LIBRARIES,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $library = $request->query('library');

        $query = DocumentTemplate::where('is_active', true)
            ->orderBy('library')
            ->orderBy('category')
            ->orderBy('title');

        if ($library) {
            $request->validate(['library' => Rule::in(DocumentLibraryAccess::LIBRARIES)]);
            abort_unless(DocumentLibraryAccess::userMayViewLibrary($user, (string) $library), 403);
            $query->where('library', $library);
        }

        $templates = $query->get()->filter(fn (DocumentTemplate $t) => $t->userMayDownload($user))->values();

        return response()->json([
            'data' => $templates->map(fn (DocumentTemplate $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'category' => $t->category,
                'library' => $t->library,
                'uploaded_by' => $t->uploadedBy?->only(['id', 'name']),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'category' => ['required', 'string', Rule::in(DocumentLibraryAccess::CATEGORIES)],
            'library' => ['required', 'string', Rule::in(DocumentLibraryAccess::LIBRARIES)],
            'file' => 'required|file|max:'.CrmConfigLimits::DOCUMENT_UPLOAD_MAX_KB,
        ]);

        abort_unless(DocumentLibraryAccess::userMayUploadToLibrary($user, $data['library']), 403);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $code = Str::slug($data['title']).'-'.Str::lower(Str::random(6));
        $relative = 'document-templates/'.$data['library'].'/'.$code.'.'.$ext;
        Storage::disk('local')->putFileAs(
            'document-templates/'.$data['library'],
            $file,
            $code.'.'.$ext
        );

        $template = DocumentTemplate::create([
            'code' => $code,
            'title' => $data['title'],
            'category' => $data['category'],
            'library' => $data['library'],
            'storage_path' => $relative,
            'uploaded_by' => $user->id,
            'allowed_user_ids' => null,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => $template->load('uploadedBy:id,name'),
        ], 201);
    }

    public function download(Request $request, DocumentTemplate $documentTemplate): StreamedResponse
    {
        abort_unless($documentTemplate->userMayDownload($request->user()), 403);

        $path = $documentTemplate->storage_path;
        abort_unless(Storage::disk('local')->exists($path), 404, 'Template file missing on server.');

        $filename = Str::slug($documentTemplate->title).'.'.pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($path, $filename);
    }
}
