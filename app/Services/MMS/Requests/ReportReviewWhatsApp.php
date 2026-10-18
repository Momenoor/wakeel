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
 * changes. Approve is the decision at once. Reject and Needs changes ask for the details — text,
 * pictures or files, as many messages as they like — and a "Send decision" button closes them. The
 * decision lands on the request on the system as if made there by the reviewer (status, comment,
 * files, who and when), and the assistant is told.
 *
 * The buttons carry the delivery they belong to ("rr:<id>:<action>"), and an answer counts only from the
 * number the report was sent to: the webhook is signed by Meta, and nobody else's reply decides anything.
 */
class ReportReviewWhatsApp
{
    public const TYPES = [RequestType::REVIEW_REPORT, RequestType::CONFIRM_REPORT];

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
                    $mediaId ??= $this->whatsapp->upload($file['path'], $file['mime'], $file['name']);
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
     * @return array{path: string, mime: string, name: string}|null
     */
    private function reportFile(MatterRequest $request): ?array
    {
        $from = (int) (collect((array) ($request->extra['rounds'] ?? []))->last()['attachments_from'] ?? 0);
        $disk = Storage::disk('public');

        $attachment = $request->attachments()->where('id', '>', $from)->oldest('id')->get()
            ->first(fn ($attachment) => filled($attachment->path) && $disk->exists($attachment->path));

        return $attachment
            ? ['path' => $disk->path($attachment->path), 'mime' => (string) ($disk->mimeType($attachment->path) ?: 'application/pdf'), 'name' => (string) ($attachment->name ?: basename($attachment->path))]
            : null;
    }

    /**
     * What the template's parameters may hold: the matter's own placeholders, and these.
     *
     * @param  array{path: string, mime: string, name: string}|null  $file
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
     * review — a button pressed, or the text or files of a rejection being sent.
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
        return DB::transaction(fn (): bool => $this->pressedLocked($from, $deliveryId, $action, $message));
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

        match ($action) {
            'approve' => $this->decide($delivery, 'approved'),
            'reject', 'changes' => $this->ask($delivery, $action),
            'done' => $this->finish($delivery),
            'cancel' => $this->cancel($delivery),
        };

        return true;
    }

    /** The text or file of a rejection being sent. */
    private function collect(string $from, array $message): bool
    {
        // Pictures sent together reach the server as separate calls, at the same moment: each one reads
        // what was collected and writes it back with its own added — without a lock the last to write
        // wins and the others' files are lost. One at a time, then.
        return DB::transaction(fn (): bool => $this->collectLocked($from, $message));
    }

    private function collectLocked(string $from, array $message): bool
    {
        $delivery = ReportReviewDelivery::query()->where('phone', $from)->whereNotNull('awaiting')->latest('id')->lockForUpdate()->first();

        if (! $delivery || $this->seen($delivery, $message)) {
            return (bool) $delivery;
        }

        $texts = (array) $delivery->pending_texts;
        $files = (array) $delivery->pending_files;
        $type = $message['type'];

        if ($type === 'text') {
            $texts[] = trim((string) ($message['text']['body'] ?? ''));
        } else {
            $media = (array) ($message[$type] ?? []);

            if (blank($media['id'] ?? null)) {
                return true;
            }

            $file = $this->whatsapp->download((string) $media['id']);
            $mime = strtolower(trim(explode(';', $file['mime'] ?: (string) ($media['mime_type'] ?? ''))[0]));
            $extension = pathinfo((string) ($media['filename'] ?? ''), PATHINFO_EXTENSION) ?: (MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin');
            $name = filled($media['filename'] ?? null) ? MatterOneDriveExplorer::cleanName((string) $media['filename']) : $type.'.'.$extension;
            $path = 'requests-attachments/'.Str::random(8).'-'.$name;
            Storage::disk('public')->put($path, $file['data']);
            $files[] = $path;

            if (filled($media['caption'] ?? null)) {
                $texts[] = trim((string) $media['caption']);
            }
        }

        $delivery->update(['pending_texts' => array_values(array_filter($texts)), 'pending_files' => $files]);

        return true;
    }

    /** Reject or Needs changes pressed: what is wrong — text, pictures or files — then "Send decision". */
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

        if (blank($delivery->pending_texts) && blank($delivery->pending_files)) {
            $this->buttons($delivery, $this->about($delivery).__('Nothing received yet — send the text, pictures or files first, then press "Send decision".'));

            return;
        }

        $this->decide($delivery, $delivery->awaiting === 'reject' ? 'rejected' : 'needs_changes');
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
            'approved_comment' => trim(implode("\n", (array) $delivery->pending_texts)) ?: ($decision === 'approved' ? null : __('See the attached files.')),
            'attachments' => array_map(fn (string $path): array => ['path' => $path], (array) $delivery->pending_files),
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
            self::payload($delivery, 'done') => __('Send decision'),
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
