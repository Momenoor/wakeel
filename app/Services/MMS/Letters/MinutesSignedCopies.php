<?php

namespace App\Services\MMS\Letters;

use App\Models\MinutesDelivery;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file sent on WhatsApp in reply to minutes sent for signature: taken as
 * the signed copy — filed with the matter's attachments, put in the
 * matter's OneDrive folder, the attendee marked as signed, and thanked.
 *
 * The reply is matched to the message it answers; a file sent without
 * replying goes with the latest minutes sent to that number.
 */
class MinutesSignedCopies
{
    /** How long after sending a file without a reply is still matched. */
    private const DAYS = 60;

    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
    ];

    public function __construct(
        private readonly WhatsAppCloud $whatsapp,
        private readonly MatterOneDriveFolders $folders,
    ) {}

    /**
     * One incoming WhatsApp message (as the webhook gives it). True when it
     * was a signed copy taken in.
     *
     * @param  array<string, mixed>  $message
     */
    public function receive(array $message): bool
    {
        $type = $message['type'] ?? null;
        $media = in_array($type, ['document', 'image'], true) ? ($message[$type] ?? null) : null;
        if (! is_array($media) || blank($media['id'] ?? null)) {
            return false;
        }

        $delivery = $this->delivery((string) ($message['from'] ?? ''), $message['context']['id'] ?? null);
        if (! $delivery) {
            Log::info('WhatsApp file not matched to minutes sent for signature', ['from' => $message['from'] ?? null]);

            return false;
        }

        // Meta sends a message again when it isn't sure it arrived.
        $messageId = (string) ($message['id'] ?? '');
        if ($messageId !== '' && in_array($messageId, $delivery->received_message_ids ?? [], true)) {
            return false;
        }

        $minutes = $delivery->minutes()->with('matter')->first();
        $file = $this->whatsapp->download((string) $media['id']);
        $mime = strtolower(trim(explode(';', $file['mime'] ?: (string) ($media['mime_type'] ?? ''))[0]));
        $extension = self::EXTENSIONS[$mime] ?? (pathinfo((string) ($media['filename'] ?? ''), PATHINFO_EXTENSION) ?: 'bin');

        $copies = count($delivery->signed_attachments ?? []);
        $name = MinutesService::fileName($minutes).' — '.__('signed by :name', ['name' => $delivery->name]).($copies ? ' ('.($copies + 1).')' : '').'.'.$extension;
        $path = 'attachments/minutes/'.$minutes->matter_id.'/signed/'.$minutes->number.'-'.Str::random(8).'.'.$extension;
        Storage::disk('public')->put($path, $file['data']);

        $attachment = $minutes->matter->attachments()->create([
            'user_id' => $delivery->sent_by,
            'type' => 'minutes_signed',
            'path' => $path,
            'name' => $name,
            'size' => strlen($file['data']),
            'extension' => $extension,
        ]);

        $first = $delivery->status !== MinutesDelivery::SIGNED;
        $delivery->update([
            'status' => MinutesDelivery::SIGNED,
            'signed_at' => $delivery->signed_at ?? now(),
            'signed_attachments' => [...($delivery->signed_attachments ?? []), $attachment->getKey()],
            'received_message_ids' => array_values(array_filter([...($delivery->received_message_ids ?? []), $messageId])),
        ]);

        $this->toOneDrive($delivery, $name, $file['data'], $mime ?: 'application/octet-stream');

        if ($first) {
            $this->thank($delivery);
            $this->tell($delivery, $minutes->number, $minutes->matter?->reference);
        }

        return true;
    }

    /**
     * The minutes sent to this number that the message answers — or, not
     * answering one, the latest sent to it.
     */
    private function delivery(string $from, ?string $context): ?MinutesDelivery
    {
        $sent = MinutesDelivery::query()->where('channel', MinutesDelivery::WHATSAPP)->where('status', '!=', MinutesDelivery::FAILED);

        if (filled($context) && ($answered = (clone $sent)->where('message_id', $context)->first())) {
            return $answered;
        }

        $phone = WhatsAppService::formatWhatsAppNumber($from);

        return $phone === null ? null : $sent
            ->where('address', $phone)
            ->where('sent_at', '>=', now()->subDays(self::DAYS))
            ->latest('sent_at')
            ->latest('id')
            ->first();
    }

    private function toOneDrive(MinutesDelivery $delivery, string $name, string $data, string $mime): void
    {
        try {
            $url = $this->folders->uploadSignedMinutes($delivery->minutes->matter, $name, $data, $mime);
            $delivery->update(['onedrive_url' => $url ?? $delivery->onedrive_url, 'onedrive_error' => $url ? null : __('The matter has no OneDrive folder.')]);
        } catch (\Throwable $e) {
            $delivery->update(['onedrive_error' => Str::limit($e->getMessage(), 1000)]);
            Log::warning('Signed minutes not put in OneDrive', ['delivery' => $delivery->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Thanks them — a plain reply, allowed as they just wrote.
     */
    private function thank(MinutesDelivery $delivery): void
    {
        $text = WhatsAppTemplate::default(WhatsAppTemplate::MINUTES_SIGNATURE)?->acknowledgement;
        if (blank($text)) {
            return;
        }

        try {
            $this->whatsapp->sendText($delivery->address, (string) $text);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp thank-you not sent', ['delivery' => $delivery->getKey(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Whoever sent the minutes hears that a signed copy came back.
     */
    private function tell(MinutesDelivery $delivery, int|string $number, ?string $reference): void
    {
        $user = $delivery->sent_by ? User::find($delivery->sent_by) : null;
        if (! $user) {
            return;
        }

        try {
            Notification::make()
                ->success()
                ->title(__('Signed minutes received'))
                ->body(__(':name sent back minutes (:number) of :matter signed.', ['name' => $delivery->name, 'number' => $number, 'matter' => $reference]))
                // The matter, on its minutes tab.
                ->actions(array_filter([
                    ($matterId = $delivery->minutes?->matter_id) ? Action::make('view')->label(__('View'))->url(MatterResource::getUrl('view', [
                        'record' => $matterId,
                        'relation' => array_search(MinutesRelationManager::class, MatterResource::getRelations(), true),
                    ], panel: 'mms'))->markAsRead() : null,
                ]))
                ->sendToDatabase($user);
        } catch (\Throwable $e) {
            Log::info('Signed minutes notification not sent', ['error' => $e->getMessage()]);
        }
    }
}
