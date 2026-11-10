<?php

namespace App\Filament\Support;

use App\Enums\LetterSendChannel;
use App\Models\LetterSendRequest;
use App\Models\MatterLetter;
use App\Services\MMS\Letters\LetterSendRequests;
use App\Services\MMS\Letters\LetterWhatsApp;
use App\Support\Dates;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * A letter asked of the administrator to send (LetterSendRequests), on the screens: the Sending section an
 * assistant fills in when issuing, writing or editing a letter — the ways it should go, a note, the SMS to
 * copy — and the To send action whoever sends it works from, on the letter's row and on the dashboard.
 */
class LetterSendFields
{
    public const CHANNELS = 'send_channels';

    public const NOTE = 'send_note';

    public const SMS = 'send_sms_text';

    /** When issuing or writing a letter: an assistant's only. The SMS is made from the template once issued. */
    public static function askSection(): Section
    {
        return Section::make(__('Sending'))
            ->description(__('Your administrator is told to send the letter to its recipients by the ways ticked. Not used when you choose "Issue and send by email".'))
            ->visible(fn (): bool => LetterSendRequests::mustAsk(auth()->user()))
            ->columns(2)
            ->schema([
                self::channels()->default([LetterSendChannel::EMAIL->value])->required(),
                self::note(),
            ]);
    }

    /**
     * When editing a letter: an assistant's, and whoever asked for it to be sent — the ways, the SMS as it will
     * be copied (the template's, to change for this letter) and the note.
     */
    public static function editSection(MatterLetter $letter): Section
    {
        return Section::make(__('Sending'))
            ->description(__('Your administrator is told to send the letter to its recipients by the ways ticked — told again when the ways change.'))
            ->visible(fn (): bool => self::editable($letter))
            ->columns(2)
            ->schema([
                // Required while it is still the administrator's to send: a letter not sent, with no request yet.
                self::channels()->live()->required(fn (): bool => ! $letter->wasSent() && LetterSendRequests::mustAsk(auth()->user())),
                self::note(),
                Textarea::make(self::SMS)
                    ->label(__('SMS text'))
                    ->helperText(__('Shown to whoever sends the letter, to copy. Leave empty for the template\'s.'))
                    ->rows(3)
                    ->extraInputAttributes(['dir' => 'auto'])
                    ->visible(fn (Get $get): bool => in_array(LetterSendChannel::SMS->value, (array) $get(self::CHANNELS), true))
                    ->columnSpanFull(),
            ]);
    }

    /** @return array<string, mixed> the edit form's state for the section */
    public static function editState(MatterLetter $letter): array
    {
        $request = $letter->sendRequest;

        return [
            self::CHANNELS => $request?->channels ?? [],
            self::NOTE => $request?->note,
            self::SMS => $request?->sms_text ?: LetterSendRequests::smsText($letter),
        ];
    }

    /** Whether the edit form has the section for this user and letter. */
    public static function editable(MatterLetter $letter): bool
    {
        $user = auth()->user();

        return LetterSendRequests::mustAsk($user) || ($letter->sendRequest !== null && $letter->sendRequest->requested_by === $user?->getKey());
    }

    /**
     * What the form asked for, kept: the letter asked of the administrator to send.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(MatterLetter $letter, array $data): ?LetterSendRequest
    {
        $user = auth()->user();

        return $user ? app(LetterSendRequests::class)->ask($letter, (array) ($data[self::CHANNELS] ?? []), $user, $data[self::NOTE] ?? null, $data[self::SMS] ?? null) : null;
    }

    /**
     * To send: what was asked — by whom, the ways, the note — the recipients' numbers and addresses, the SMS to
     * copy, and each way ticked once the letter has gone by it. On a letter's row (the record a MatterLetter)
     * and on the dashboard (a LetterSendRequest).
     */
    public static function action(): Action
    {
        $request = fn (Model $record): ?LetterSendRequest => $record instanceof LetterSendRequest ? $record : $record->sendRequest;

        return Action::make('toSend')
            ->label(__('To send'))
            ->icon('heroicon-o-megaphone')
            ->color('warning')
            ->visible(fn (Model $record): bool => LetterSendRequests::mayHandle(auth()->user(), $request($record)))
            ->modalHeading(fn (Model $record): string => __('Send letter :reference', ['reference' => $request($record)?->letter?->reference]))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (Model $record): array => ['sent' => $request($record)?->doneChannels() ?? []])
            ->schema(fn (Model $record): array => self::handleSchema($request($record)))
            ->extraModalFooterActions(fn (Model $record): array => [
                Action::make('letterPdf')
                    ->label(__('The letter (PDF)'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(route('letters.pdf', $request($record)?->matter_letter_id), shouldOpenInNewTab: true),
            ])
            ->action(function (Model $record, array $data) use ($request): void {
                $asked = $request($record);
                $before = $asked->doneChannels();

                foreach (array_diff(LetterSendChannel::clean((array) ($data['sent'] ?? [])), $before) as $channel) {
                    app(LetterSendRequests::class)->done($asked, LetterSendChannel::from($channel), auth()->user());
                }

                Notification::make()->success()
                    ->title($asked->isOpen() ? __('Saved') : __('Letter :reference marked as sent', ['reference' => $asked->letter?->reference]))
                    ->send();
            });
    }

    /**
     * Send by WhatsApp: the letter's PDF to the recipients' numbers ticked, by the approved template
     * (LetterWhatsApp) — shown while WhatsApp and that template are set up and a recipient has a number. On a
     * letter's row and on the dashboard; WhatsApp is ticked by itself on what was asked of the administrator.
     */
    public static function whatsappAction(): Action
    {
        $letter = fn (Model $record): ?MatterLetter => $record instanceof LetterSendRequest ? $record->letter : $record;

        return Action::make('whatsapp')
            ->label(__('Send by WhatsApp'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            ->visible(fn (Model $record): bool => LetterWhatsApp::available() && ($of = $letter($record)) !== null && LetterWhatsApp::numbers($of) !== [])
            ->modalHeading(fn (Model $record): string => __('Send letter :reference by WhatsApp', ['reference' => $letter($record)?->reference]))
            ->modalDescription(__('Each number ticked gets the approved WhatsApp message with the letter\'s PDF. The letter is then marked as sent, and WhatsApp is ticked on what was asked of the administrator.'))
            ->modalSubmitActionLabel(__('Send'))
            ->fillForm(fn (Model $record): array => ['numbers' => array_map('strval', array_keys(LetterWhatsApp::numbers($letter($record))))])
            ->schema(fn (Model $record): array => [
                CheckboxList::make('numbers')
                    ->label(__('Send to'))
                    ->options(LetterWhatsApp::numbers($letter($record)))
                    ->required(),
                TextEntry::make('message')
                    ->label(__('Message'))
                    ->state(LetterWhatsApp::template()?->preview(LetterWhatsApp::template()->parameterValues(LetterWhatsApp::values($letter($record), (string) $letter($record)->recipients->first()?->name))))
                    ->extraAttributes(['dir' => 'auto', 'class' => 'whitespace-pre-line']),
            ])
            ->action(function (Model $record, array $data) use ($letter): void {
                $result = app(LetterWhatsApp::class)->send($letter($record), (array) ($data['numbers'] ?? []), auth()->user());

                $notification = Notification::make()
                    ->title(trans_choice('{0} Nothing was sent on WhatsApp|{1} Sent to one number on WhatsApp|[2,*] Sent to :count numbers on WhatsApp', $result['sent'], ['count' => $result['sent']]))
                    ->body($result['errors'] !== [] ? implode("\n", $result['errors']) : null);

                ($result['sent'] === 0 ? $notification->danger() : ($result['failed'] > 0 ? $notification->warning() : $notification->success()))->send();
            });
    }

    /** @return list<mixed> */
    private static function handleSchema(?LetterSendRequest $request): array
    {
        if ($request === null) {
            return [];
        }

        $letter = $request->letter;
        $recipients = $letter?->recipients ?? collect();
        $done = (array) $request->done;

        return [
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('asked_by')
                        ->label(__('Asked by'))
                        ->state(trim(($request->requester?->display_name ?: $request->requester?->name ?: '—').' · '.$request->created_at?->format(Dates::dateTimeFormat()))),
                    TextEntry::make('ways')
                        ->label(__('To be sent by'))
                        ->state(array_map(fn (string $channel): string => LetterSendChannel::from($channel)->label(), (array) $request->channels))
                        ->badge()
                        ->color('warning'),
                    TextEntry::make('subject')->label(__('Subject'))->state($letter?->subject)->columnSpanFull(),
                    TextEntry::make('note')->label(__('Note'))->state($request->note)->visible(filled($request->note))->columnSpanFull(),
                    TextEntry::make('recipients')
                        ->label(__('Recipients'))
                        ->state($recipients->map(fn ($recipient): string => trim($recipient->name.' — '.implode(' · ', [...array_filter((array) $recipient->phones), ...$recipient->allEmails()]), ' —'))->all())
                        ->listWithLineBreaks()
                        ->columnSpanFull(),
                ]),
            Section::make(__('SMS text'))
                ->description(__('Copy it and send it from your phone or SMS service: the system does not send SMS.'))
                ->visible($request->asks(LetterSendChannel::SMS))
                ->schema([
                    TextEntry::make('sms_text')
                        ->hiddenLabel()
                        ->state($request->sms_text)
                        ->copyable()
                        ->copyMessage(__('Copied'))
                        ->extraAttributes(['dir' => 'auto']),
                    TextEntry::make('sms_numbers')
                        ->label(__('Mobile numbers'))
                        ->state($recipients->flatMap(fn ($recipient): array => array_filter((array) $recipient->phones))->unique()->implode(', '))
                        ->copyable()
                        ->copyMessage(__('Copied'))
                        ->placeholder(__('The recipients have no phone number.')),
                ]),
            CheckboxList::make('sent')
                ->label(__('Sent by'))
                ->helperText(__('Tick each way once the letter has gone by it. Sending it by email or by WhatsApp from the system ticks that way by itself.'))
                ->options(array_intersect_key(LetterSendChannel::options(), array_flip((array) $request->channels)))
                ->descriptions(collect($done)->map(fn (array $entry): string => Dates::dateTime($entry['at'] ?? null))->all())
                // Done is done: not taken back from here.
                ->disableOptionWhen(fn (string $value): bool => isset($done[$value]))
                ->columns(3),
        ];
    }

    private static function channels(): CheckboxList
    {
        return CheckboxList::make(self::CHANNELS)
            ->label(__('To be sent by'))
            ->options(LetterSendChannel::options())
            ->columns(3);
    }

    private static function note(): Textarea
    {
        return Textarea::make(self::NOTE)
            ->label(__('Note to the administrator'))
            ->rows(2)
            ->maxLength(1000)
            ->extraInputAttributes(['dir' => 'auto']);
    }
}
