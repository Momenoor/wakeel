<?php

namespace App\Services\MMS\Letters;

use App\Enums\LetterSendChannel;
use App\Enums\NotificationType;
use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Models\LetterSendRequest;
use App\Models\MatterLetter;
use App\Models\Party;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\MatterPeople;
use App\Services\Notifications\Notifier;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppService;
use App\Support\CompanyContact;
use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A letter an assistant issues and does not send themselves is their administrator's to send: asked for here
 * with the ways it should go (LetterSendChannel) — the administrators told in the bell (and by whatever else
 * they chose for "A letter … for me to send"), the office's own WhatsApp number by the approved "A letter to
 * send" template — then each way marked done as it is sent (an email sent from the system marks Email by
 * itself), and whoever asked told once all are.
 */
class LetterSendRequests
{
    /** Whether what this user issues goes to an administrator to send: an assistant's does. */
    public static function mustAsk(?User $user): bool
    {
        return $user !== null && RequestMemo::remember('letter-send.must-ask.'.$user->getKey(), fn (): bool => (bool) $user->party?->isAssistant());
    }

    /**
     * Asked (or asked again, the ways changed): one request a letter. Nothing when no way is given. The
     * administrators are told when it is new, or its ways changed and some are still to do.
     *
     * @param  iterable<mixed>  $channels
     */
    public function ask(MatterLetter $letter, iterable $channels, User $by, ?string $note = null, ?string $sms = null): ?LetterSendRequest
    {
        $channels = LetterSendChannel::clean($channels);

        if ($channels === []) {
            return null;
        }

        $request = LetterSendRequest::query()->firstOrNew(['matter_letter_id' => $letter->getKey()]);
        $changed = ! $request->exists || $request->channels !== $channels;
        $done = array_intersect_key((array) $request->done, array_flip($channels));
        $pending = array_diff($channels, array_keys($done));

        $request->fill([
            'matter_id' => $letter->matter_id,
            'requested_by' => $request->requested_by ?: $by->getKey(),
            'channels' => $channels,
            'done' => $done ?: null,
            'note' => filled($note) ? trim((string) $note) : null,
            'sms_text' => in_array(LetterSendChannel::SMS->value, $channels, true) ? (filled($sms) ? trim((string) $sms) : self::smsText($letter)) : null,
            'completed_at' => $pending === [] ? ($request->completed_at ?? now()) : null,
        ])->save();

        $letter->setRelation('sendRequest', $request);

        if ($changed && $pending !== []) {
            $this->tell($request, $letter, $by);
        }

        return $request;
    }

    /**
     * A way done — by hand (Mark as sent) or, for Email, by the letter going out from the system. Nothing when
     * the letter was not asked to be sent that way, or it is done already. Whoever asked is told once every
     * way is done, unless they did the last themselves.
     */
    public function done(LetterSendRequest|MatterLetter $of, LetterSendChannel $channel, ?User $by): void
    {
        $request = $of instanceof MatterLetter ? $of->sendRequest()->first() : $of;

        if (! $request || ! $request->isOpen() || ! $request->asks($channel) || isset($request->done[$channel->value])) {
            return;
        }

        $request->done = [...(array) $request->done, $channel->value => ['by' => $by?->getKey(), 'at' => now()->toIso8601String()]];
        $request->completed_at = $request->pendingChannels() === [] ? now() : null;
        $request->save();

        if ($request->isOpen() || $request->requested_by === null || $request->requested_by === $by?->getKey()) {
            return;
        }

        $letter = $request->letter;
        Notifier::send(
            NotificationType::LETTER_SENT_FOR_ME,
            User::query()->whereKey($request->requested_by)->get(),
            __('Letter :reference was sent', ['reference' => $letter?->reference]),
            __('Matter :matter — :subject. Sent by: :ways.', ['matter' => self::matterLabel($letter), 'subject' => $letter?->subject, 'ways' => LetterSendChannel::labels($request->channels)]),
            self::url($request),
        );
    }

    /**
     * The SMS that goes with a letter: its template's own (Letter templates → SMS text), its placeholders
     * filled from the letter — or, with none, a line naming the letter and its matter in the letter's language.
     */
    public static function smsText(MatterLetter $letter): string
    {
        $text = trim((string) $letter->template?->sms_template);

        if ($text === '') {
            $text = $letter->locale === 'en'
                ? '{{company.name}}: letter no. {{reference}} — {{subject}}'
                : '{{company.name}}: خطاب رقم {{reference}} — {{subject}}';
        }

        try {
            $values = array_map(fn ($value): string => trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')), LetterIssuer::composerFor($letter)->values());
        } catch (Throwable $e) {
            Log::warning('A letter\'s SMS text filled without its placeholders', ['letter' => $letter->getKey(), 'error' => $e->getMessage()]);
            $values = [];
        }

        $values = [...$values, 'reference' => (string) $letter->reference, 'subject' => (string) $letter->subject];

        return trim((string) preg_replace('/[ \t]+/u', ' ', strip_tags(BulkMailPlaceholders::apply($text, $values))));
    }

    /**
     * The open requests a user has to do with: every one for a super admin; else those of the assistants they
     * administer, and their own.
     *
     * @return Builder<LetterSendRequest>
     */
    public static function openFor(User $user): Builder
    {
        return LetterSendRequest::query()
            ->whereNull('completed_at')
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->whereIn('requested_by', [$user->getKey(), ...self::administeredUserIds($user)]));
    }

    /** Whether the user may mark a request's ways as sent: who asked, who administers them, a super admin. */
    public static function mayHandle(?User $user, ?LetterSendRequest $request): bool
    {
        return $user !== null && $request !== null && $request->isOpen()
            && ($request->requested_by === $user->getKey() || in_array($request->requested_by, self::administeredUserIds($user), true) || $user->isSuperAdmin());
    }

    /** @return list<int> the users of the assistants someone administers — read once a request */
    private static function administeredUserIds(User $user): array
    {
        return RequestMemo::remember('letter-send.administered.'.$user->getKey(), function () use ($user): array {
            $parties = $user->administeredPartyIds();

            return $parties === [] ? [] : Party::query()->whereIn('id', $parties)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        });
    }

    /** Where a request is seen and dealt with: the matter's Letters tab. */
    public static function url(LetterSendRequest $request): string
    {
        return MatterResource::getUrl('view', ['record' => $request->matter_id, 'tab' => 'letters'], panel: 'mms');
    }

    public static function matterLabel(?MatterLetter $letter): string
    {
        $matter = $letter?->matter;

        return $matter ? $matter->number.'/'.$matter->year : '—';
    }

    /**
     * Whose it is to send: the administrators of whoever asked — or, when they have none, of the matter's
     * assistants — never whoever asked.
     *
     * @return Collection<int, User>
     */
    private static function administrators(LetterSendRequest $request, User $by): Collection
    {
        $own = $by->party?->administrators()->get() ?? collect();

        return ($own->isNotEmpty() ? $own : MatterPeople::administrators([(int) $request->matter_id]))
            ->reject(fn (User $user): bool => $user->is($by))
            ->values();
    }

    private function tell(LetterSendRequest $request, MatterLetter $letter, User $by): void
    {
        $ways = LetterSendChannel::labels($request->pendingChannels());
        $matter = self::matterLabel($letter);
        $name = (string) ($by->display_name ?: $by->name);
        $url = self::url($request);

        Notifier::send(
            NotificationType::LETTER_TO_SEND,
            self::administrators($request, $by),
            __('A letter to send: :reference', ['reference' => $letter->reference]),
            __('Matter :matter — :subject. Issued by :name, to be sent by: :ways.', ['matter' => $matter, 'subject' => $letter->subject, 'name' => $name, 'ways' => $ways])
                .(filled($request->note) ? ' '.$request->note : ''),
            $url,
        );

        $this->tellOffice([
            'matter' => $matter,
            'letter' => (string) $letter->reference,
            'subject' => (string) $letter->subject,
            'recipients' => $letter->recipients()->pluck('name')->implode('، '),
            'ways' => $ways,
            'assistant' => $name,
            'link' => $url,
        ], $request);
    }

    /**
     * The office's own WhatsApp number (Settings → General) told, by the approved "A letter to send" template
     * — a plain message reaches a number only within a day of its last one to us. Nothing without the template,
     * the number or WhatsApp set up. Sent once the page has answered.
     *
     * @param  array<string, string>  $values
     */
    private function tellOffice(array $values, LetterSendRequest $request): void
    {
        $template = WhatsAppTemplate::default(WhatsAppTemplate::LETTER_TO_SEND);
        $phone = WhatsAppService::formatWhatsAppNumber(CompanyContact::whatsapp());

        if (! $template || $phone === null || ! WhatsAppCloud::configured()) {
            return;
        }

        // Meta refuses a parameter with line breaks, tabs or a long run of spaces.
        $values = array_map(fn (string $text): string => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))), 300, '…') ?: '—', $values);
        $requestId = $request->getKey();

        defer(function () use ($template, $phone, $values, $requestId): void {
            try {
                app(WhatsAppCloud::class)->sendTemplate($phone, $template->meta_name, $template->language, $template->parameterValues($values));
            } catch (Throwable $e) {
                Log::warning('The office not told on WhatsApp of a letter to send', ['request' => $requestId, 'error' => $e->getMessage()]);
            }
        });
    }
}
