<?php

namespace App\Http\Controllers;

use App\Enums\UploadType;
use App\Models\SourceFile;
use App\Services\Upload\CancellationTemplate;
use App\Services\Upload\FileTypeRegistry;
use App\Services\Upload\UploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Upload tab (blueprint §J).
 *
 * The rule that shapes this controller: the user picks the exact file type from a
 * dropdown FIRST, then uploads. Nothing is auto-detected. The dropdown only offers
 * types the signed-in user may upload, and the server checks that permission again on
 * submit - at launch that means Admin only.
 */
class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    public function index(Request $request): View
    {
        $allowed = $this->allowedTypes($request);

        abort_if($allowed === [], 403, 'You do not have permission to upload files.');

        return view('uploads.index', [
            'allowedTypes' => $allowed,
            'definitions' => FileTypeRegistry::all(),
            'recent' => SourceFile::with('uploadedBy')->latest('id')->paginate(15),
            'overdue' => SourceFile::overdueTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $allowed = $this->allowedTypes($request);

        $validated = $request->validate([
            'upload_type' => ['required', Rule::in(array_keys($allowed))],
            'file' => ['required', 'file', 'max:51200'], // 50 MB
            // The single-PO export has no PO column, so it may need to be typed in (§C),
            // and no order date either, which turnaround needs (§L).
            'po_number' => ['nullable', 'string', 'max:64'],
            'order_date' => ['nullable', 'date'],
            // Noon's picking lists carry no real delivery date - only Noon's own
            // estimate - and turnaround is measured against the day it actually went
            // out (§Q, §L).
            'delivery_date' => ['nullable', 'date'],
        ], [
            'upload_type.in' => 'You do not have permission to upload that file type.',
        ]);

        $type = UploadType::from($validated['upload_type']);

        // Belt and braces: the dropdown was filtered, but never trust the form.
        abort_unless($request->user()->can($type->permission()), 403);

        $sourceFile = $this->uploads->handle(
            $request->file('file'),
            $type,
            $request->user(),
            [
                'po_number' => $validated['po_number'] ?? null,
                'order_date' => $validated['order_date'] ?? null,
                'delivery_date' => $validated['delivery_date'] ?? null,
            ],
        );

        return redirect()
            ->route('uploads.show', $sourceFile)
            ->with('status', $sourceFile->status->label());
    }

    public function show(Request $request, SourceFile $sourceFile): View
    {
        abort_if($this->allowedTypes($request) === [], 403);

        return view('uploads.show', [
            'file' => $sourceFile->load('uploadedBy'),
            'definition' => FileTypeRegistry::for($sourceFile->upload_type),
        ]);
    }

    /** Download the blank cancellations template - the only file the team builds (§T). */
    public function cancellationTemplate(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can(UploadType::AmazonCancellations->permission()), 403);

        $path = tempnam(sys_get_temp_dir(), 'operon_template_').'.xlsx';

        (new CancellationTemplate)->writeTo($path);

        return response()
            ->download($path, CancellationTemplate::filename())
            ->deleteFileAfterSend();
    }

    /** Re-download exactly what was uploaded, for auditing a rejected file. */
    public function download(Request $request, SourceFile $sourceFile): StreamedResponse
    {
        abort_unless($request->user()->can($sourceFile->upload_type->permission()), 403);
        abort_if(blank($sourceFile->stored_path), 404);

        $disk = Storage::disk(UploadService::disk());

        /*
         * The audit row outlives the file. On Laravel Cloud the container filesystem is
         * reset by every deployment, so a source file uploaded before the last deploy
         * still has its record and its imported data - what it no longer has is the
         * original workbook. Say so with a 404 rather than a 500.
         */
        abort_unless($disk->exists($sourceFile->stored_path), 404, 'The original file is no longer stored. Its imported data is unaffected.');

        return $disk->download($sourceFile->stored_path, $sourceFile->original_filename);
    }

    /**
     * The upload types this user may choose.
     *
     * @return array<string, string> value => label
     */
    private function allowedTypes(Request $request): array
    {
        $user = $request->user();

        return collect(UploadType::cases())
            ->filter(fn (UploadType $type) => $user->can($type->permission()))
            ->mapWithKeys(fn (UploadType $type) => [$type->value => $type->label()])
            ->all();
    }
}
