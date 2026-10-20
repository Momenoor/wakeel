<?php

use App\Enums\BulkMailRecipientStatus;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BulkMailController;
use App\Http\Controllers\EndOfServiceGratuityClosingVoucherPrintController;
use App\Http\Controllers\IncentiveCalculationAssistantPrintController;
use App\Http\Controllers\IncentiveCalculationPrintController;
use App\Http\Controllers\LeaveRequestEmailActionController;
use App\Http\Controllers\LegacyAttachmentPathController;
use App\Http\Controllers\MatterReceivedNotificationController;
use App\Http\Controllers\PayrollJournalVoucherPrintController;
use App\Http\Controllers\SalaryAuthorizationFormPrintController;
use App\Http\Controllers\SignatureStampImageController;
use App\Http\Controllers\SignedAttachmentController;
use App\Http\Controllers\SplitPdfOneDriveController;
use App\Models\BulkMailRecipient;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Services\MMS\BulkMailService;
use App\Services\MMS\Letters\LetterDocx;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterPdf;
use App\Services\MMS\Letters\MinutesService;
use App\Support\EditorFonts;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

Route::get('/mail/unsubscribe/{token}', function ($token) {
    $recipient = BulkMailRecipient::where('unsubscribe_token', $token)->firstOrFail();
    $recipient->update(['status' => BulkMailRecipientStatus::Skipped]);

    return __('You have been successfully unsubscribed.');
})->name('bulk-mail.unsubscribe');

// The templates moved under Settings: an old link (a bookmark, a notification) still finds its page.
Route::get('/mms/templates/{path?}', fn (?string $path = null) => redirect('/mms/settings/'.$path.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301))
    ->where('path', '.*')
    ->name('templates.moved');

Route::middleware('auth')->group(function () {
    Route::get('bulk-mail/preview/{campaign}/{recipient}', [BulkMailController::class, '__invoke'])
        ->name('bulk-mail.preview');

    // An issued letter, rendered from its frozen copy.
    Route::get('letters/{letter}/pdf', function (MatterLetter $letter) {
        abort_unless($letter->matter !== null && auth()->user()->can('view', $letter->matter), 403);

        $pdf = (new LetterPdf(LetterIssuer::composerFor($letter)))->render();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            // Opens in the browser; the Arabic name needs the encoded form.
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', LetterIssuer::fileName($letter).'.pdf', 'letter-'.$letter->getKey().'.pdf'),
        ]);
    })->name('letters.pdf');

    Route::get('letters/{letter}/docx', function (MatterLetter $letter) {
        abort_unless($letter->matter !== null && auth()->user()->can('view', $letter->matter), 403);

        $path = (new LetterDocx(LetterIssuer::composerFor($letter)))->save(storage_path('app/temp/letter-'.$letter->getKey().'-'.uniqid().'.docx'));

        return response()->download($path, LetterIssuer::fileName($letter).'.docx')->deleteFileAfterSend();
    })->name('letters.docx');

    // A meeting's minutes, as letters are: its PDF (opened in the browser) and Word.
    Route::get('minutes/{minutes}/pdf', function (MatterMinutes $minutes) {
        abort_unless(auth()->user()->can('view', $minutes->matter), 403);

        $pdf = (new LetterPdf(MinutesService::composer($minutes)))->render();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', MinutesService::fileName($minutes).'.pdf', 'minutes-'.$minutes->getKey().'.pdf'),
        ]);
    })->name('minutes.pdf');

    Route::get('minutes/{minutes}/docx', function (MatterMinutes $minutes) {
        abort_unless(auth()->user()->can('view', $minutes->matter), 403);

        $path = (new LetterDocx(MinutesService::composer($minutes)))->save(storage_path('app/temp/minutes-'.$minutes->getKey().'-'.uniqid().'.docx'));

        return response()->download($path, MinutesService::fileName($minutes).'.docx')->deleteFileAfterSend();
    })->name('minutes.docx');

    // The live view the attendees watch (shared in the meeting), and what
    // it asks for every two seconds.
    Route::get('minutes/{minutes}/live', function (MatterMinutes $minutes) {
        abort_unless(auth()->user()->can('view', $minutes->matter), 403);

        $html = MinutesService::liveHtml($minutes);

        return view('minutes.live', [
            'title' => MinutesService::fileName($minutes),
            'html' => $html,
            // The text's own fingerprint: saves a second apart look the same by time.
            'version' => md5($html),
            'feed' => route('minutes.live.feed', $minutes),
            // Pusher, when set up: the page then asks for the text only when it changes.
            'echo' => config('filament.broadcasting.echo.broadcaster') === 'pusher' ? config('filament.broadcasting.echo') : null,
            'channel' => 'minutes.'.$minutes->getKey(),
            'layout' => MinutesService::liveLayout($minutes),
            'rtl' => (($minutes->template?->locale) ?: 'ar') !== 'en',
            // Into the meeting from the live view too.
            'join' => $minutes->joinUrl(),
            // Written in the template's fonts, as the PDF.
            'fonts' => EditorFonts::make([$minutes->template?->letter_font_id, $minutes->template?->arabic_font_id])->letterCss('.text'),
        ]);
    })->name('minutes.live');

    Route::get('minutes/{minutes}/live/feed', function (MatterMinutes $minutes) {
        abort_unless(auth()->user()->can('view', $minutes->matter), 403);

        $html = MinutesService::liveHtml($minutes);

        return response()->json([
            'version' => md5($html),
            'html' => $html,
            'final' => $minutes->isFinal(),
        ])->header('Cache-Control', 'no-store');
    })->name('minutes.live.feed');

    Route::get('bulk-mail/pdf/{recipient}', function (BulkMailRecipient $recipient) {
        abort_unless(auth()->user()->can('view', $recipient->campaign), 403);

        $path = app(BulkMailService::class)->ensurePdf($recipient);
        abort_if($path === null, 404);

        return Storage::disk(BulkMailService::DISK)->download($path, BulkMailService::pdfFileName($recipient));
    })->name('bulk-mail.pdf');

    Route::get('attachments/{attachment}/download', AttachmentController::class)->name('attachment.download')->middleware('auth');

    // The signed copy of a PDF attachment, made in the browser on the Sign PDF page: kept beside the original.
    Route::post('attachments/{attachment}/signed', SignedAttachmentController::class)->name('attachment.signed')->middleware('throttle:30,1');

    // A PDF from the user's own OneDrive, passed on as it comes for the Split PDF page to split in the browser.
    Route::get('split-pdf/onedrive/{item}', SplitPdfOneDriveController::class)->where('item', '[A-Za-z0-9!_.\-]{1,200}')->name('split-pdf.onedrive')->middleware('throttle:30,1');

    // A signature's or a stamp's image, for whoever may put it on a PDF.
    Route::get('signature-stamps/{signatureStamp}/image', SignatureStampImageController::class)->name('signature-stamps.image');

    // A file attached before the private disk existed, at its old address: once moved off the public disk
    // the web server finds nothing there and falls through to this — sent on to the file for who may see it.
    // (Its parameter is not called {path}: Laravel's own route for the "local" disk has that very address, and
    // the later of two routes of one address replaces the earlier.)
    Route::get('storage/{oldPath}', LegacyAttachmentPathController::class)->where('oldPath', '.*')->name('attachment.legacy');

    Route::get('incentive/calculations/{calculation}/print', IncentiveCalculationPrintController::class)
        ->name('incentive.calculation.print')
        ->middleware(['auth']);

    Route::get('incentive/calculations/{calculation}/print/{party}', IncentiveCalculationAssistantPrintController::class)
        ->name('incentive.calculation.print.assistant')
        ->middleware(['auth']);

    Route::get('payroll/runs/{run}/journal-voucher/print', PayrollJournalVoucherPrintController::class)
        ->name('payroll.run.journal-voucher.print')
        ->middleware(['auth']);

    Route::get('payroll/runs/{run}/salary-authorization-form/print', SalaryAuthorizationFormPrintController::class)
        ->name('payroll.run.salary-authorization-form.print')
        ->middleware(['auth']);

    Route::get('payroll/eosg-closing-voucher/{year}/print', EndOfServiceGratuityClosingVoucherPrintController::class)
        ->name('payroll.eosg-closing-voucher.print')
        ->whereNumber('year')
        ->middleware(['auth']);
});

/*
 * Assistant confirms or disputes the assigning date from an email link.
 *
 * These are deliberately OUTSIDE the auth group: the recipient clicks from their
 * inbox and is usually not logged in. They were previously nested inside it,
 * contradicting their own comments, so every emailed link bounced to the login
 * screen. The signature IS the authentication here, which is why the POST is
 * signed too — without it, anyone could flip any request to DISPUTED by ID.
 */
Route::prefix('admin/matter/{matter}/received-date')
    ->middleware('signed')
    ->group(function () {
        Route::get('accept/{matterRequest}', [MatterReceivedNotificationController::class, 'accept'])
            ->name('matter.received.accept');

        Route::get('dispute/{matterRequest}', [MatterReceivedNotificationController::class, 'disputeForm'])
            ->name('matter.received.dispute');

        Route::post('dispute/{matterRequest}', [MatterReceivedNotificationController::class, 'disputeSubmit'])
            ->name('matter.received.dispute.submit');
    });

/*
 * Approve/reject a leave request straight from the notification email, with
 * no login — the recipient (Redha, or whoever is cc'd) is very often reading
 * this on a phone, outside any session. As with the matter flow above, the
 * signature IS the authentication, which is why this sits outside the auth
 * group entirely.
 *
 * GET and POST share one route (and one signed URL): GET only renders a
 * confirm page, POST is what actually decides the request. Outlook's Safe
 * Links (and equivalents) visit every link in a message to scan it, often
 * before the recipient opens it at all — a plain GET that mutated on the spot
 * meant the scanner itself approved or rejected requests before a human ever
 * clicked.
 */
Route::prefix('leave-requests/{leaveRequest}/email-action')
    ->middleware('signed')
    ->group(function () {
        Route::match(['get', 'post'], 'approve', [LeaveRequestEmailActionController::class, 'approve'])
            ->name('leave-request.email-action.approve');

        Route::match(['get', 'post'], 'reject', [LeaveRequestEmailActionController::class, 'reject'])
            ->name('leave-request.email-action.reject');
    });
