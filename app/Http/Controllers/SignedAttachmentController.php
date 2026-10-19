<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentCategory;
use App\Filament\Support\AttachmentFields;
use App\Filament\Support\SignPdfAction;
use App\Models\Attachment;
use App\Models\Contracts\AttachmentOwner;
use App\Services\Attachments\AttachmentService;
use App\Services\SignatureStamps\SigningLog;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The signed copy of a PDF attachment, as the Sign PDF page made it in the browser: kept as a NEW
 * attachment on the same record, in the same category (or General where that one is not allowed) — the
 * original is never touched. Only for whoever may open the original, sign PDFs and add files to its record.
 */
class SignedAttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment, AttachmentService $service): JsonResponse
    {
        Gate::authorize('view', $attachment);
        abort_unless($request->user()->can(ScreenPermissions::SIGN_PDF) && SignPdfAction::isPdf($attachment), 403);

        $owner = $attachment->attachable;
        abort_unless($owner instanceof AttachmentOwner, 404);
        abort_unless(AttachmentFields::canAttach($owner), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('attachments.max_kb')],
            'pages' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*' => ['integer'],
        ]);

        $contents = (string) file_get_contents($request->file('file')->getRealPath());

        // What came back must still be a PDF — whatever the browser called it.
        if (! str_contains(substr($contents, 0, 1024), '%PDF-')) {
            throw ValidationException::withMessages(['file' => __('This is not a PDF file.')]);
        }

        try {
            $signed = $service->store(
                $owner,
                $contents,
                $this->category($attachment, $owner),
                self::signedName((string) ($attachment->name ?: $attachment->original_name)),
                $request->user(),
                __('Signed copy of #:id', ['id' => $attachment->getKey()]),
                'user',
                'application/pdf',
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        SigningLog::record($request->user(), (string) $attachment->name, (int) $request->integer('pages'), (array) $request->input('items', []), $signed);

        // Shown on the page the signer goes back to.
        Notification::make()
            ->success()
            ->title(__('Signed copy saved'))
            ->body($signed->name)
            ->actions([
                Action::make('download')
                    ->label(__('Download'))
                    ->url($signed->url())
                    ->button(),
            ])
            ->persistent()
            ->send();

        return response()->json([
            'id' => $signed->getKey(),
            'name' => $signed->name,
            'download' => $signed->url(),
        ], 201);
    }

    /** "Contract.pdf" → "Contract - signed.pdf". */
    public static function signedName(string $name): string
    {
        $base = preg_replace('/\.pdf$/i', '', trim($name)) ?: 'document';

        return $base.' - signed.pdf';
    }

    /** The original's category when the record still allows it, else General (or the record's first). */
    private function category(Attachment $attachment, AttachmentOwner $owner): AttachmentCategory
    {
        $allowed = $owner->attachmentCategories();

        foreach ([$attachment->category, AttachmentCategory::GENERAL] as $category) {
            if ($category !== null && in_array($category, $allowed, true)) {
                return $category;
            }
        }

        return $allowed[0] ?? AttachmentCategory::GENERAL;
    }
}
