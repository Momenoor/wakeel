<?php

namespace App\Http\Controllers;

use App\Models\SignatureStamp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mime\MimeTypes;

/**
 * A signature's or a stamp's image, for whoever may put it on a PDF: its owner, or anyone allowed the
 * office's when it is shared. Kept by that person's browser (its address changes with the image), and read
 * from where it is stored (Azure: about a second a request) once a day at most — a page of signatures was
 * four seconds an image.
 */
class SignatureStampImageController extends Controller
{
    public function __invoke(Request $request, SignatureStamp $signatureStamp): Response
    {
        // The model's own rule rather than the gate: a super admin passes every gate, yet someone else's
        // own signature is still not theirs to sign with.
        abort_unless($signatureStamp->isUsableBy($request->user()), 403);
        abort_if(blank($signatureStamp->path), 404);

        $image = Cache::remember(
            'signature-stamp-image.'.$signatureStamp->getKey().'.'.$signatureStamp->updated_at?->getTimestamp(),
            now()->addDay(),
            fn (): ?string => ($contents = rescue(fn () => $signatureStamp->storage()->get($signatureStamp->path), null, report: false)) !== null ? base64_encode($contents) : null,
        );
        abort_if($image === null, 404);

        $extension = strtolower(pathinfo($signatureStamp->path, PATHINFO_EXTENSION));

        return response(base64_decode($image), 200, [
            'Content-Type' => MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'image/png',
            'Content-Disposition' => 'inline; filename="signature-'.$signatureStamp->getKey().'.'.$extension.'"',
            // Its address carries the time it last changed (SignatureStamp::imageUrl): kept until then.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
