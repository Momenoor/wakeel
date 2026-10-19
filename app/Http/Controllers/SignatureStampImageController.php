<?php

namespace App\Http\Controllers;

use App\Models\SignatureStamp;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A signature's or a stamp's image, for whoever may put it on a PDF: its owner, or anyone allowed the
 * office's when it is shared. Never cached anywhere but in that person's browser.
 */
class SignatureStampImageController extends Controller
{
    public function __invoke(Request $request, SignatureStamp $signatureStamp): StreamedResponse
    {
        // The model's own rule rather than the gate: a super admin passes every gate, yet someone else's
        // own signature is still not theirs to sign with.
        abort_unless($signatureStamp->isUsableBy($request->user()), 403);
        abort_unless(filled($signatureStamp->path) && $signatureStamp->storage()->exists($signatureStamp->path), 404);

        return $signatureStamp->storage()->response($signatureStamp->path, 'signature-'.$signatureStamp->getKey().'.'.pathinfo($signatureStamp->path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
