<?php

namespace App\Services\MMS\Requests;

use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\MatterRequest;
use App\Models\ReportReviewDelivery;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterOneDriveExplorer;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * A preliminary or final report reviewed on WhatsApp.
 *
 * When an assistant sends a Review Report or Confirm Report request, each of their reviewers (set on
 * the assistant, Parties) is sent the report on WhatsApp, with three buttons: Approve, Reject, Needs
 * changes. Each asks for its comment — text, pictures or files, as many messages as they like, none
 * needed to approve — and a button closes it ("Approve", or "Send decision"). The
 * decision lands on the request on the system as if made there by the reviewer (status, comment,
 * files, who and when), and the assistant is told.
 *
 * The buttons carry the delivery they belong to ("rr:<id>:<action>"), and an answer counts only from the
 * number the report was sent to: the webhook is signed by Meta, and nobody else's reply decides anything.
 */
class ReportReviewWhatsApp
{
    public const TYPES = [RequestType::REVIEW_REPORT, RequestType::CONFIRM_REPORT];

    /** Tries of a locked step: a deadlock (pictures sent together, each its own call) is tried again. */
    private const ATTEMPTS = 3;

    public function __construct(private readonly WhatsAppCloud $whatsapp) {}

    public static function supports(MatterRequest $request): bool
    {
        return in_array($request->type, self::TYPES, true);
    }

    /**
     * The assistant's reviewers that WhatsApp can reach.
     *
     * @return Collection<int, User>
     */
    public static function reviewersOf(MatterRequest $request): Collection
    {
        $assistant = $request->requestBy?->party;

        return $assistant
            ? $assistant->reportReviewers()->get()->filter(fn (User $user): bool => self::phoneOf($user) !== null)->values()
            : collect();
    }

    private static function phoneOf(User $user): ?string
    {
        return WhatsAppService::formatWhatsAppNumber($user->phone);
    }

    /**
     * The report to each reviewer. Nothing when it is not a report request, WhatsApp is not set up, no
     * template for it is, or the assistant has no reviewers — the request goes as before.
     *
     * @return array{sent: int, failed: int, reached: list<int>} the ids of the reviewers it reached
     */
    public function send(MatterRequest $request): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'reached' => []];
        $template = WhatsAppTemplate::default(WhatsAppTemplate::REPORT_REVIEW);
        $reviewers = self::reviewersOf($request);

        if (! self::supports($request) || ! $template || $reviewers->isEmpty() || ! WhatsAppCloud::configured()) {
            return $result;
        }

        $request->loadMissing(['matter', 'requestBy']);
        $round = 1 + count((array) ($request->extra['rounds'] ?? []));
        $file = $this->reportFile($request);
        $mediaId = null;

        foreach ($reviewers as $reviewer) {
            $delivery = ReportReviewDelivery::create([
                'matter_request_id' => $request->getKey(),
                'user_id' => $reviewer->getKey(),
                'phone' => self::phoneOf($reviewer),
                'round' => $round,
            ]);

            try {
                if ($template->header === 'document' && $file !== null) {
                    $mediaId ??= $this->whatsapp->upload((string) $file['attachment']->storage()->get($file['attachment']->path), $file['mime'], $file['name']);
                }

                $messageId = $this->whatsapp->sendTemplate(
                    $delivery->phone,
                    $template->meta_name,
                    $template->language,
                    $template->parameterValues($this->values($request, $reviewer, $file)),
                    $template->header === 'document' && $mediaId !== null ? ['id' => $mediaId, 'filename' => $file['name']] : null,
                    [self::payload($delivery, 'approve'), self::payload($delivery, 'reject'), self::payload($delivery, 'changes')],
                );

                $delivery->update(['message_id' => $messageId, 'sent_at' => now()]);
                $result['sent']++;
                $result['reached'][] = $reviewer->getKey();
            } catch (Throwable $e) {
                $delivery->update(['status' => ReportReviewDelivery::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
                Log::warning('Report not sent to its reviewer on WhatsApp', ['request' => $request->getKey(), 'reviewer' => $reviewer->getKey(), 'error' => $e->getMessage()]);
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * The report of the current round (the request's own files first sent, or those added when it was
     * sent again): its first file, which goes in the message header.
     *
     * @return array{attachment: Attachment, mime: string, name: string}|null
     */
    private function reportFile(MatterRequest $request): ?array
    {
        $from = (int) (collect((array) ($request->extra['rounds'] ?? []))->last()['attachments_from'] ?? 0);

        $attachment = $request->attachments()->where('id', '>', $from)->oldest('id')->get()
            ->first(fn ($attachment) => $attachment->fileExists());

        // Read from wherever it is kept (here, the public disk for a file not moved yet, or Azure) when it is sent.
        return $attachment
            ? ['attachment' => $attachment, 'mime' => (string) ($attachment->storage()->mimeType($attachment->path) ?: 'application/pdf'), 'name' => (string) ($attachment->name ?: basename($attachment->path))]
            : null;
    }

    /**
     * What the template's parameters may hold: the matter's own placeholders, and these.
     *
     * @param  array{attachment: Attachment, mime: string, name: string}|null  $file
     * @return array<string, string>
     */
    private function values(MatterRequest $request, User $reviewer, ?array $file): array
    {
        return [
            ...BulkMailPlaceholders::forMatter($request->matter),
            'reviewer' => (string) ($reviewer->display_name ?: $reviewer->name),
            'assistant' => (string) ($request->requestBy?->display_name ?: $request->requestBy?->name),
            'request_type' => (string) $request->type->getLabel(),
            'request_number' => (string) $request->getKey(),
            'matter' => $request->matter->number.'/'.$request->matter->year,
            // One line, not too long: Meta refuses a parameter with line breaks or tabs.
            'comment' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $request->comment))), 300) ?: '—',
            'file' => (string) ($file['name'] ?? '—'),
        ];
    }

    public static function payload(ReportReviewDelivery $delivery, string $action): string
    {
        return 'rr:'.$delivery->getKey().':'.$action;
    }

    // ---------------------------------------------------------------- what comes back

    /**
     * One incoming WhatsApp message (as the webhook gives it). True when it was part of a report
     * review — a button pressed, or the text or files of a decision's comment being sent.
     *
     * @param  array<string, mixed>  $message
     */
    public function receive(array $message): bool
    {
        $type = $message['type'] ?? null;
        $from = (string) ($message['from'] ?? '');

        $pressed = match ($type) {
            'button' => $message['button']['payload'] ?? null,
            'interactive' => $message['interactive']['button_reply']['id'] ?? null,
            default => null,
        };

        if (is_string($pressed) && preg_match('/^rr:(\d+):(approve|reject|changes|done|cancel)$/', $pressed, $m)) {
            return $this->pressed($from, (int) $m[1], $m[2], $message);
        }

        return in_array($type, ['text', 'image', 'document'], true) && $this->collect($from, $message);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function pressed(string $from, int $deliveryId, string $action, array $message): bool
    {
        // "Send decision" waits for the files still being taken in; two presses at once decide once.
        return DB::transaction(fn (): bool => $this->pressedLocked($from, $deliveryId, $action, $message), self::ATTEMPTS);
    }

    private function pressedLocked(string $from, int $deliveryId, string $action, array $message): bool
    {
        $delivery = ReportReviewDelivery::with(['request.matter', 'user'])->lockForUpdate()->find($deliveryId);

        // Only from the number it was sent to.
        if (! $delivery || $delivery->phone !== $from || ! $delivery->request) {
            return false;
        }

        if ($this->seen($delivery, $message)) {
            return true;
        }

        $request = $delivery->request;

        if (! in_array($request->status, [RequestStatus::PENDING, RequestStatus::DISPUTED], true)) {
            $this->reply($delivery, __('This request has already been decided.'));

            return true;
        }

        // The report was sent again since: its buttons are on the newer message.
        if ($delivery->round < 1 + count((array) ($request->extra['rounds'] ?? []))) {
            $this->reply($delivery, $this->about($delivery).__('This report was sent again after changes: please use the latest message.'));

            return true;
        }

        // One press decides: Approve and Reject at once, with whatever was sent before it (held()); Needs changes
        // at once too when something was, else it asks what to change.
        $held = in_array($action, ['approve', 'reject', 'changes'], true) && $this->takeHeld($delivery);

        match (true) {
            $action === 'approve' => $this->decide($delivery, 'approved'),
            $action === 'reject' => $this->decide($delivery, 'rejected'),
            $action === 'changes' && $held => $this->decide($delivery, 'needs_changes'),
            $action === 'changes' => $this->ask($delivery, $action),
            $action === 'done' => $this->finish($delivery),
            default => $this->cancel($delivery),
        };

        return true;
    }

    /**
     * A text, picture or file a reviewer sends before pressing a decision — not one being asked for (receive()),
     * nor a signed copy of minutes (MinutesSignedCopies, asked first): held for the next decision they press on a
     * report still waiting for them, and said once. False when they have none waiting.
     *
     * @param  array<string, mixed>  $message
     */
    public function hold(array $message): bool
    {
        $from = (string) ($message['from'] ?? '');

        if (! in_array($message['type'] ?? null, ['text', 'image', 'document'], true) || $from === '') {
            return false;
        }

        return DB::transaction(function () use ($from, $message): bool {
            $delivery = ReportReviewDelivery::query()
                ->where('phone', $from)
                ->where('status', ReportReviewDelivery::SENT)
                ->whereNull('awaiting')
                ->whereHas('request', fn ($q) => $q->whereIn('status', [RequestStatus::PENDING, RequestStatus::DISPUTED]))
                ->with('request.matter')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $delivery) {
                return false;
            }

            if ($this->seen($delivery, $message)) {
                return true;
            }

            $first = blank($delivery->pending_texts) && blank($delivery->pending_files);
            $this->keep($delivery, $message);

            if ($first) {
                $this->reply($delivery, __('Received. It goes with the next decision you press on a report: Approve, Reject or Needs changes.'));
            }

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * What the reviewer sent before pressing (held()), on any report of theirs, moved onto this one — whether
     * anything was.
     */
    private function takeHeld(ReportReviewDelivery $delivery): bool
    {
        $texts = [];
        $files = [];

        ReportReviewDelivery::query()
            ->where('phone', $delivery->phone)
            ->where('status', ReportReviewDelivery::SENT)
            ->whereNull('awaiting')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (ReportReviewDelivery $held) use (&$texts, &$files, $delivery): void {
                $texts = [...$texts, ...(array) $held->pending_texts];
                $files = [...$files, ...(array) $held->pending_files];

                if (! $held->is($delivery) && (filled($held->pending_texts) || filled($held->pending_files))) {
                    $held->update(['pending_texts' => [], 'pending_files' => []]);
                }
            });

        $delivery->forceFill(['pending_texts' => $texts, 'pending_files' => $files]);

        return $texts !== [] || $files !== [];
    }

    /** The text or file of a decision's comment being sent. */
    private function collect(string $from, array $message): bool
    {
        // Pictures sent together reach the server as separate calls, at the same moment: each one reads
        // what was collected and writes it back with its own added — without a lock the last to write
        // wins and the others' files are lost. One at a time, then — and, as two arriving at once can still
        // deadlock on the lock itself, the one MySQL turns back is tried again (ATTEMPTS).
        return DB::transaction(fn (): bool => $this->collectLocked($from, $message), self::ATTEMPTS);
    }

    private function collectLocked(string $from, array $message): bool
    {
        $delivery = ReportReviewDelivery::query()->where('phone', $from)->whereNotNull('awaiting')->latest('id')->lockForUpdate()->first();

        if (! $delivery || $this->seen($delivery, $message)) {
            return (bool) $delivery;
        }

        $this->keep($delivery, $message);

        return true;
    }

    /**
     * A text, picture or file added to what a delivery holds for its decision — a picture's or file's caption with
     * the texts, the file kept in a folder of its own under the name it was sent with.
     *
     * @param  array<string, mixed>  $message
     */
    private function keep(ReportReviewDelivery $delivery, array $message): void
    {
        $texts = (array) $delivery->pending_texts;
        $files = (array) $delivery->pending_files;
        $type = $message['type'];

        if ($type === 'text') {
            $texts[] = trim((string) ($message['text']['body'] ?? ''));
        } else {
            $media = (array) ($message[$type] ?? []);

            if (blank($media['id'] ?? null)) {
                return;
            }

            $file = $this->whatsapp->download((string) $media['id']);
            $mime = strtolower(trim(explode(';', $file['mime'] ?: (string) ($media['mime_type'] ?? ''))[0]));
            $extension = pathinfo((string) ($media['filename'] ?? ''), PATHINFO_EXTENSION) ?: (MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin');
            $name = filled($media['filename'] ?? null) ? MatterOneDriveExplorer::cleanName((string) $media['filename']) : $type.'.'.$extension;
            // A folder of its own: the file keeps the name it was sent with when it is attached.
            $path = 'requests-attachments/'.Str::uuid().'/'.$name;
            Storage::disk('public')->put($path, $file['data']);
            $files[] = $path;

            if (filled($media['caption'] ?? null)) {
                $texts[] = trim((string) $media['caption']);
            }
        }

        $delivery->update(['pending_texts' => array_values(array_filter($texts)), 'pending_files' => $files]);
    }

    /** Needs changes pressed with nothing sent before it: what has to change — text, pictures or files — then its button. */
    private function ask(ReportReviewDelivery $delivery, string $action): void
    {
        // What they send next is for this request: one begun before is cancelled (and said), never mixed in.
        ReportReviewDelivery::query()->where('phone', $delivery->phone)->whereKeyNot($delivery->getKey())->whereNotNull('awaiting')->with('request.matter')->get()
            ->each(function (ReportReviewDelivery $other) use ($delivery): void {
                $other->update(['awaiting' => null, 'pending_texts' => [], 'pending_files' => []]);
                $this->reply($other, $this->about($other).__('Cancelled, because you started the decision on request #:id. Press its button again to come back to this one.', ['id' => $delivery->request_id ?? $delivery->matter_request_id]));
            });

        $delivery->update(['awaiting' => $action, 'pending_texts' => [], 'pending_files' => []]);

        $this->buttons($delivery, $this->about($delivery).__('Send what has to change — text, pictures or files, in as many messages as you like — then press "Send decision".'));
    }

    /** "Request #12 — matter 123/2026" first, so a reviewer with several to decide knows which one a message is about. */
    private function about(ReportReviewDelivery $delivery): string
    {
        $request = $delivery->request;

        return __('Request #:id — matter :matter', ['id' => $delivery->matter_request_id, 'matter' => $request?->matter ? $request->matter->number.'/'.$request->matter->year : '—'])."\n";
    }

    private function finish(ReportReviewDelivery $delivery): void
    {
        if ($delivery->awaiting === null) {
            return;
        }

        // Approved with no comment; a rejection says what is wrong.
        if ($delivery->awaiting !== 'approve' && blank($delivery->pending_texts) && blank($delivery->pending_files)) {
            $this->buttons($delivery, $this->about($delivery).__('Nothing received yet — send the text, pictures or files first, then press "Send decision".'));

            return;
        }

        $this->decide($delivery, match ($delivery->awaiting) {
            'approve' => 'approved',
            'reject' => 'rejected',
            default => 'needs_changes',
        });
    }

    private function cancel(ReportReviewDelivery $delivery): void
    {
        $delivery->update(['awaiting' => null, 'pending_texts' => [], 'pending_files' => []]);
        $this->reply($delivery, $this->about($delivery).__('Cancelled. Press a button on the report to decide.'));
    }

    /**
     * The decision on the system, made as the reviewer: the same as pressing the button there.
     *
     * @param  'approved'|'rejected'|'needs_changes'  $decision
     */
    private function decide(ReportReviewDelivery $delivery, string $decision): void
    {
        $request = $delivery->request;
        $service = RequestServiceFactory::make($request);
        $data = [
            'approved_comment' => trim(implode("\n", (array) $delivery->pending_texts)) ?: match (true) {
                $decision === 'approved' => null,
                filled($delivery->pending_files) => __('See the attached files.'),
                default => __('Rejected on WhatsApp.'),
            },
            'attachments' => array_map(fn (string $path): array => ['path' => $path, 'source' => 'whatsapp'], (array) $delivery->pending_files),
        ];

        $this->actingAs($delivery->user, fn () => match ($decision) {
            'approved' => $service->approve([...$data, 'has_substantive_changes' => false]),
            'rejected' => $service->reject($data),
            'needs_changes' => $service->requestChanges($data),
        });

        $delivery->update(['status' => ReportReviewDelivery::ANSWERED, 'decision' => $decision, 'awaiting' => null, 'answered_at' => now(), 'pending_texts' => null, 'pending_files' => null]);

        // The other reviewers' messages no longer decide anything.
        ReportReviewDelivery::query()->where('matter_request_id', $request->getKey())->whereKeyNot($delivery->getKey())
            ->where('status', ReportReviewDelivery::SENT)->update(['status' => ReportReviewDelivery::CLOSED, 'awaiting' => null]);

        $this->reply($delivery, match ($decision) {
            'approved' => __('Request #:id approved. Thank you.', ['id' => $request->getKey()]),
            'rejected' => __('Request #:id rejected, and the assistant told. Thank you.', ['id' => $request->getKey()]),
            'needs_changes' => __('Request #:id sent back for changes, and the assistant told. Thank you.', ['id' => $request->getKey()]),
        });
    }

    /** Meta sends a message again when it isn't sure it arrived. */
    private function seen(ReportReviewDelivery $delivery, array $message): bool
    {
        $id = (string) ($message['id'] ?? '');

        if ($id === '') {
            return false;
        }

        if (in_array($id, (array) $delivery->received_message_ids, true)) {
            return true;
        }

        $delivery->update(['received_message_ids' => [...(array) $delivery->received_message_ids, $id]]);

        return false;
    }

    private function buttons(ReportReviewDelivery $delivery, string $text): void
    {
        $this->quietly($delivery, fn () => $this->whatsapp->sendButtons($delivery->phone, $text, [
            self::payload($delivery, 'done') => $delivery->awaiting === 'approve' ? __('Approve') : __('Send decision'),
            self::payload($delivery, 'cancel') => __('Cancel'),
        ]));
    }

    private function reply(ReportReviewDelivery $delivery, string $text): void
    {
        $this->quietly($delivery, fn () => $this->whatsapp->sendText($delivery->phone, $text));
    }

    /** A reply is a courtesy: one that cannot go is logged, never stops the decision. */
    private function quietly(ReportReviewDelivery $delivery, Closure $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('WhatsApp reply to a report reviewer not sent', ['delivery' => $delivery->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /** The webhook has nobody signed in: the decision is made as the reviewer, for that moment only. */
    private function actingAs(User $user, Closure $work): void
    {
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            $work();
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetGuards();
        }
    }
}
