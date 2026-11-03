<?php

namespace App\Http\Controllers;

use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Services\MMS\Letters\MinutesLiveFiles;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\MMS\OneDriveClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files in a matter's OneDrive folders, for the live view of its minutes to show one over the minutes (a
 * document read out in the meeting): listed (MinutesLiveFiles), then one shown by OneDrive's own preview — a
 * short-lived link that renders a PDF, an Office document or an image in a frame — or, a PDF or an image
 * OneDrive has no preview for, passed on as it is. Only to whoever may view the matter: the list from the
 * folders they may see, a file from any folder of the matter (the office may have chosen it for every viewer),
 * and only one inside that folder — never by an address the browser sends.
 */
class MinutesLiveFilesController extends Controller
{
    public function __construct(private readonly MatterOneDriveExplorer $explorer, private readonly OneDriveClient $client) {}

    /** The files at the top of each folder and in the folders directly in it — or, with words (q), those found anywhere in it. */
    public function index(Request $request, MatterMinutes $minutes, MinutesLiveFiles $files): JsonResponse
    {
        self::allowed($request, $minutes);

        return response()->json($files->list($minutes, $request->user(), (string) $request->query('q', '')))->header('Cache-Control', 'no-store');
    }

    /** One file: the link that shows it in a frame (none where it cannot be shown), and its page in OneDrive. */
    public function show(Request $request, MatterMinutes $minutes, MatterOneDriveFolder $folder, string $item): JsonResponse
    {
        $file = $this->file($request, $minutes, $folder, $item, preview: true);

        return response()->json([
            'name' => $file['name'],
            'url' => $file['previewUrl'] ?? (self::passable($file) ? route('minutes.live.file.content', [$minutes, $folder, $item]) : null),
            'open' => $file['webUrl'],
        ])->header('Cache-Control', 'no-store');
    }

    /** A PDF or an image OneDrive has no preview for: passed on as it comes, a piece at a time, never held whole. */
    public function content(Request $request, MatterMinutes $minutes, MatterOneDriveFolder $folder, string $item): StreamedResponse
    {
        $file = $this->file($request, $minutes, $folder, $item, preview: false);
        abort_unless(self::passable($file), 404);

        try {
            $body = $this->client->stream((string) $file['downloadUrl']);
        } catch (RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        return new StreamedResponse(OneDriveClient::passOn($body), 200, [
            'Content-Type' => (string) $file['mime'],
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Whoever may view the matter. */
    private static function allowed(Request $request, MatterMinutes $minutes): void
    {
        abort_unless($minutes->matter !== null && $request->user()->can('view', $minutes->matter), 403);
    }

    /**
     * A file in one of the matter's folders, made already — not found, to the browser, whatever else it is.
     *
     * @return array{name: string, mime: ?string, webUrl: string, previewUrl: ?string, downloadUrl: ?string}
     */
    private function file(Request $request, MatterMinutes $minutes, MatterOneDriveFolder $folder, string $item, bool $preview): array
    {
        self::allowed($request, $minutes);
        abort_unless($folder->matter_id === $minutes->matter_id && $folder->isCreated(), 404);

        try {
            return $this->explorer->show($folder, $item, $preview);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    /**
     * A PDF or an image: the browser shows it as it is.
     *
     * @param  array{mime: ?string, downloadUrl: ?string}  $file
     */
    private static function passable(array $file): bool
    {
        return filled($file['downloadUrl']) && ($file['mime'] === 'application/pdf' || str_starts_with((string) $file['mime'], 'image/'));
    }
}
