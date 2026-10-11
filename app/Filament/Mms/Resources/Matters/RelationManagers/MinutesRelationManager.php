<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Enums\LetterTemplateCategories;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Mms\Resources\Matters\Pages\RecordMinutes;
use App\Filament\Support\EmailSendFields;
use App\Filament\Support\RichEditorDirection;
use App\Models\Attachment;
use App\Models\CalendarEvent;
use App\Models\EmailTemplate;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\MatterMinutes;
use App\Models\MinutesDelivery;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\Letters\MinutesSender;
use App\Services\MMS\Letters\MinutesService;
use App\Services\MMS\SenderMailer;
use App\Services\WhatsAppCloud;
use App\Support\EmailGrouping;
use App\Support\Honorific;
use App\Support\ScreenPermissions;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * A matter's meeting minutes (محاضر): prepared with the questions to ask —
 * on the matter's calendar meeting, its date, time and Teams link — then
 * filled in at the meeting: who attended (their ID numbers and phones),
 * the answers, the template's own fields (deadlines…). Numbered per
 * matter, previewed and downloaded like letters; finalised, its PDF is
 * filed with the matter's attachments.
 */
class MinutesRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    protected static string $relationship = 'minutes';

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_MINUTES;
    }

    public static function getModelLabel(): string
    {
        return __('Minutes');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Meeting minutes');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Meeting minutes');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('No.'))->formatStateUsing(fn ($state) => '('.$state.')')->weight('bold')->sortable(),
                TextColumn::make('meeting_at')->label(__('Meeting'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('template.name')->label(__('Template'))->wrap(),
                TextColumn::make('answered')
                    ->label(__('Answered'))
                    ->state(fn (MatterMinutes $record) => $record->progress()['answered'].' / '.$record->progress()['total']),
                TextColumn::make('present')
                    ->label(__('Attended'))
                    ->state(fn (MatterMinutes $record) => collect($record->attendees ?? [])->where('present', true)->count()),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === MatterMinutes::FINAL ? __('Final') : __('Draft'))
                    ->color(fn (string $state) => $state === MatterMinutes::FINAL ? 'success' : 'warning'),
                TextColumn::make('signed')
                    ->label(__('Signed'))
                    ->state(fn (MatterMinutes $record) => self::signedCount($record))
                    ->placeholder('—'),
            ])
            ->headerActions([$this->newAction()])
            ->recordActions([
                $this->recordAction(),
                Action::make('live')
                    ->label(__('Live view'))
                    ->icon('heroicon-o-presentation-chart-bar')
                    ->color('gray')
                    ->visible(fn (MatterMinutes $record): bool => ! $record->isFinal())
                    ->url(fn (MatterMinutes $record) => route('minutes.live', $record), shouldOpenInNewTab: true),
                $this->previewAction(),
                $this->sendAction(),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (MatterMinutes $record) => route('minutes.pdf', $record), shouldOpenInNewTab: true),
                ActionGroup::make([
                    Action::make('word')
                        ->label('Word')
                        ->icon('heroicon-o-document-text')
                        ->url(fn (MatterMinutes $record) => route('minutes.docx', $record)),
                    $this->signaturesAction(),
                    $this->finaliseAction(),
                    $this->reopenAction(),
                    $this->deleteAction(),
                ]),
            ]);
    }

    /**
     * The letterhead new minutes start with: the template's own, else the
     * default one.
     */
    private static function letterheadFor(mixed $templateId): ?int
    {
        $own = filled($templateId) ? LetterTemplate::query()->whereKey($templateId)->value('letterhead_id') : null;

        return $own ? (int) $own : Letterhead::default()?->getKey();
    }

    private function canChange(): bool
    {
        return auth()->user()?->can('update', $this->getOwnerRecord()) ?? false;
    }

    /**
     * New minutes: the template, the meeting (from the matter's calendar,
     * or a date and time), and the questions to ask.
     */
    private function newAction(): Action
    {
        $matter = $this->getOwnerRecord();

        return Action::make('newMinutes')
            ->label(__('New minutes'))
            ->icon('heroicon-o-clipboard-document-list')
            ->modalWidth('6xl')
            ->visible(fn (): bool => $this->canChange())
            ->fillForm(function () use ($matter): array {
                // The matter's next meeting, else its latest.
                $event = CalendarEvent::query()->where('matter_id', $matter->getKey())->where('start_datetime', '>=', now()->startOfDay())->orderBy('start_datetime')->first()
                    ?? CalendarEvent::query()->where('matter_id', $matter->getKey())->latest('start_datetime')->first();

                // The default minutes template, and its letterhead (else the default one).
                $templateId = LetterTemplate::query()->where('category', LetterTemplateCategories::MINUTES->value)->where('is_active', true)
                    ->orderByDesc('is_default')->oldest('id')->value('id');

                return [
                    'letter_template_id' => $templateId,
                    'letterhead_id' => self::letterheadFor($templateId),
                    'calendar_event_id' => $event?->getKey(),
                    'meeting_at' => $event?->start_datetime?->format('Y-m-d H:i:s'),
                    'questions' => [],
                ];
            })
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('letter_template_id')
                            ->label(__('Template'))
                            ->options(fn () => LetterTemplate::query()->where('category', LetterTemplateCategories::MINUTES->value)->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->live()
                            // Another template: its letterhead.
                            ->afterStateUpdated(fn ($state, Set $set) => $set('letterhead_id', self::letterheadFor($state)))
                            ->columnSpanFull(),
                        Select::make('calendar_event_id')
                            ->label(__('The meeting'))
                            ->options(fn () => CalendarEvent::query()->where('matter_id', $matter->getKey())->latest('start_datetime')->limit(50)->get()
                                ->mapWithKeys(fn (CalendarEvent $event) => [$event->getKey() => $event->start_datetime?->format('d/m/Y H:i').' — '.$event->title]))
                            ->placeholder(__('Not on the calendar'))
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set) => $set('meeting_at', CalendarEvent::find($state)?->start_datetime?->format('Y-m-d H:i:s'))),
                        DateTimePicker::make('meeting_at')
                            ->label(__('Meeting date and time'))
                            ->seconds(false)
                            ->required(),
                        Select::make('letterhead_id')
                            ->label(__('Letterhead'))
                            ->options(fn () => Letterhead::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder(__('The template\'s letterhead')),
                    ]),
                Section::make(__('Questions to ask'))
                    ->description(__('Prepared now; at the meeting each gets its answer, and more can be added.'))
                    ->schema([
                        Repeater::make('questions')
                            ->hiddenLabel()
                            ->schema([Textarea::make('text')->hiddenLabel()->rows(2)->required()])
                            ->addActionLabel(__('Add question'))
                            ->reorderable(),
                    ]),
            ])
            ->action(function (array $data) use ($matter): void {
                $event = filled($data['calendar_event_id'] ?? null) ? CalendarEvent::find($data['calendar_event_id']) : null;

                $minutes = $matter->minutes()->create([
                    'letter_template_id' => $data['letter_template_id'],
                    'letterhead_id' => $data['letterhead_id'] ?? null,
                    'calendar_event_id' => $event?->getKey(),
                    'number' => MatterMinutes::nextNumber($matter),
                    'meeting_at' => Carbon::parse($data['meeting_at']),
                    'meeting_link' => $event?->online_meeting_url,
                    'items' => collect($data['questions'] ?? [])->pluck('text')->filter(fn ($text) => filled($text))
                        ->map(fn ($text) => ['type' => 'question', 'text' => trim((string) $text), 'answer' => null])->values()->all(),
                    'status' => MatterMinutes::DRAFT,
                    'created_by' => auth()->id(),
                ]);

                Notification::make()->success()->title(__('Minutes (:number) prepared', ['number' => $minutes->number]))->send();
            });
    }

    /**
     * At the meeting: who attended, the answers, the template's own items —
     * on a page of its own, in steps (RecordMinutes).
     */
    private function recordAction(): Action
    {
        return Action::make('recordMeeting')
            ->label(__('Record the meeting'))
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (MatterMinutes $record): bool => ! $record->isFinal() && $this->canChange())
            ->url(fn (MatterMinutes $record): string => RecordMinutes::getUrl(['record' => $this->getOwnerRecord(), 'minutes' => $record]));
    }

    private function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('Preview'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (MatterMinutes $record) => __('Minutes (:number)', ['number' => $record->number]))
            ->modalWidth('7xl')
            ->modalContent(fn (MatterMinutes $record) => view('filament.mms.letters.preview', [
                'url' => route('minutes.pdf', $record).'?v='.$record->updated_at?->timestamp,
                'title' => MinutesService::fileName($record),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    private function finaliseAction(): Action
    {
        return Action::make('finalise')
            ->label(__('Finalise'))
            ->icon('heroicon-o-lock-closed')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Its wording is kept as it is now and its PDF filed with the matter\'s attachments. It can be reopened to correct it.'))
            ->visible(fn (MatterMinutes $record): bool => ! $record->isFinal() && $this->canChange())
            ->fillForm(fn (): array => ['ended_at' => now()->format('Y-m-d H:i:s')])
            ->schema([
                DateTimePicker::make('ended_at')
                    ->label(__('Meeting ended at'))
                    ->helperText(__('Fills {{minutes.end_time}} and {{minutes.end_date}}.'))
                    ->seconds(false)
                    ->required(),
            ])
            ->action(function (MatterMinutes $record, array $data): void {
                app(MinutesService::class)->finalise($record, auth()->id(), Carbon::parse($data['ended_at']));

                Notification::make()->success()->title(__('Minutes (:number) finalised and filed with the attachments', ['number' => $record->number]))->send();
            });
    }

    /**
     * "2 / 3": of the attendees sent the minutes, how many sent them back
     * signed. Nothing when none was sent.
     */
    private static function signedCount(MatterMinutes $record): ?string
    {
        $sent = $record->deliveries->where('status', '!=', MinutesDelivery::FAILED);

        return $sent->isEmpty() ? null
            : $sent->where('status', MinutesDelivery::SIGNED)->pluck('name')->unique()->count().' / '.$sent->pluck('name')->unique()->count();
    }

    /**
     * Finalised minutes to the attendees, to sign and send back: by email
     * and/or WhatsApp, each as ticked.
     */
    private function sendAction(): Action
    {
        return Action::make('sendForSignature')
            ->label(__('Send to attendees'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalWidth('7xl')
            ->modalSubmitActionLabel(__('Send'))
            ->visible(fn (MatterMinutes $record): bool => $record->isFinal() && $this->canChange())
            ->fillForm(function (MatterMinutes $record): array {
                $arabic = MinutesService::composer($record)->isArabic();
                $template = EmailTemplate::default(EmailTemplate::MINUTES_SIGNATURE, $arabic ? 'ar' : 'en');

                return [
                    'recipients' => MinutesSender::recipients($record),
                    'sender' => array_key_first(SenderMailer::options()),
                    // …and the email template's own.
                    'cc' => EmailSendFields::startingCc($record->matter, $template?->getKey()),
                    'grouping' => EmailGrouping::SEPARATE,
                    'email_template_id' => $template?->getKey(),
                    'subject' => $template?->subject ?? MinutesSender::defaultSubject($arabic),
                    'body' => $template?->body ?? MinutesSender::defaultBody($arabic),
                    'whatsapp_template_id' => WhatsAppTemplate::default(WhatsAppTemplate::MINUTES_SIGNATURE)?->getKey(),
                ];
            })
            ->schema(fn (MatterMinutes $record): array => [
                Repeater::make('recipients')
                    ->label(__('Attendees'))
                    ->addActionLabel(__('Add recipient'))
                    ->columns(12)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->columnSpan(3),
                        TagsInput::make('emails')->label(__('Emails'))->nestedRecursiveRules(['email'])->columnSpan(3),
                        TextInput::make('phone')->label('WhatsApp')->tel()->columnSpan(2),
                        Toggle::make('by_email')->label(__('By email'))->inline(false)->columnSpan(2),
                        Toggle::make('by_whatsapp')->label(__('By WhatsApp'))->inline(false)->columnSpan(2),
                        Hidden::make('party_id'),
                        // The main party it goes with (sent by party).
                        Hidden::make('group'),
                    ]),
                Section::make(__('Email'))
                    ->collapsible()
                    ->schema([
                        Select::make('sender')->label(__('Send from'))->options(SenderMailer::options()),
                        EmailSendFields::grouping(),
                        EmailSendFields::cc(),
                        // From Templates → Email templates ("Minutes for
                        // signature"); another one chosen starts the email again.
                        Select::make('email_template_id')
                            ->label(__('Email template'))
                            ->options(fn () => EmailTemplate::options(EmailTemplate::MINUTES_SIGNATURE))
                            ->placeholder(__('The standard email'))
                            ->live()
                            ->afterStateUpdated(function (?string $state, $old, Get $get, Set $set) use ($record): void {
                                $template = filled($state) ? EmailTemplate::find($state) : null;
                                // Its own CC in place of the one before's.
                                $set('cc', EmailTemplate::swapCc((array) ($get('cc') ?? []), $old, $state));
                                $arabic = MinutesService::composer($record)->isArabic();
                                $set('subject', $template?->subject ?? MinutesSender::defaultSubject($arabic));
                                $set('body', $template?->body ?? MinutesSender::defaultBody($arabic));
                            }),
                        TextInput::make('subject')->label(__('Subject'))->required()->maxLength(255),
                        RichEditor::make('body')
                            ->label(__('Email'))
                            ->helperText(__(':placeholder greets each by name; the minutes PDF is attached.', ['placeholder' => '{{recipient.name}}']))
                            ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                            ->tap(RichEditorDirection::apply(...))
                            ->extraInputAttributes(['dir' => MinutesService::composer($record)->isArabic() ? 'rtl' : 'ltr']),
                        // Files of this send's own, beside the minutes.
                        EmailSendFields::attachments('minutes-attachments', __('Sent by email with the minutes, for this send only.')),
                        EmailSendFields::oneDriveFiles($record->matter),
                    ]),
                Section::make('WhatsApp')
                    ->collapsible()
                    ->description(WhatsAppCloud::configured() ? null : __('WhatsApp is not set up (WHATSAPP_PHONE_ID and WHATSAPP_TOKEN in .env).'))
                    ->schema([
                        Select::make('whatsapp_template_id')
                            ->label(__('WhatsApp template'))
                            ->options(fn () => WhatsAppTemplate::query()->where('is_active', true)->where('purpose', WhatsAppTemplate::MINUTES_SIGNATURE)->pluck('name', 'id'))
                            ->live(),
                        Placeholder::make('whatsapp_preview')
                            ->label(__('Preview'))
                            ->content(function (Get $get) use ($record) {
                                $template = WhatsAppTemplate::find($get('whatsapp_template_id'));
                                $first = collect($get('recipients') ?? [])->first();

                                return $template
                                    ? new HtmlString('<div dir="auto" style="white-space: pre-line;">'.e($template->preview($template->parameterValues([
                                        ...MinutesService::composer($record)->values(),
                                        ...Honorific::values((string) ($first['name'] ?? ''), MinutesService::composer($record)->isArabic()),
                                    ]))).'</div>')
                                    : '—';
                            }),
                    ]),
            ])
            ->action(function (MatterMinutes $record, array $data): void {
                $result = app(MinutesSender::class)->send(
                    $record,
                    array_values((array) ($data['recipients'] ?? [])),
                    $data['sender'] ?? null,
                    $data['subject'] ?? null,
                    $data['body'] ?? null,
                    filled($data['whatsapp_template_id'] ?? null) ? WhatsAppTemplate::find($data['whatsapp_template_id']) : null,
                    auth()->id(),
                    EmailSendFields::uploaded($data, $record->matter),
                    array_values((array) ($data['cc'] ?? [])),
                    $data['grouping'] ?? EmailGrouping::SEPARATE,
                );

                EmailSendFields::forget($data);

                $notification = Notification::make()
                    ->title(__('Sent: :sent, failed: :failed', ['sent' => $result['sent'], 'failed' => $result['failed']]))
                    ->body($result['errors'] ? implode("\n", $result['errors']) : null);

                match (true) {
                    $result['sent'] === 0 => $notification->danger(),
                    $result['failed'] > 0 => $notification->warning(),
                    default => $notification->success(),
                };

                $notification->send();
            });
    }

    /**
     * Who the minutes went to, how, and who sent them back signed — with
     * the signed copies and their OneDrive place.
     */
    private function signaturesAction(): Action
    {
        return Action::make('signatures')
            ->label(__('Signatures'))
            ->icon('heroicon-o-check-badge')
            ->visible(fn (MatterMinutes $record): bool => $record->deliveries->isNotEmpty())
            ->modalHeading(fn (MatterMinutes $record) => __('Minutes (:number)', ['number' => $record->number]).' — '.__('Signatures'))
            ->modalWidth('6xl')
            ->modalContent(fn (MatterMinutes $record) => view('filament.mms.minutes.signatures', [
                'deliveries' => $record->deliveries()->latest('id')->get(),
                'attachments' => Attachment::query()->whereIn('id', $record->deliveries->flatMap(fn ($d) => $d->signed_attachments ?? []))->get()->keyBy('id'),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    private function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('Reopen'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (MatterMinutes $record): bool => $record->isFinal() && $this->canChange())
            ->action(fn (MatterMinutes $record) => $record->update(['status' => MatterMinutes::DRAFT]));
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteMinutes')
            ->label(__('Delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('The PDF already filed with the attachments stays there.'))
            ->visible(fn (): bool => $this->canChange())
            ->action(fn (MatterMinutes $record) => $record->delete());
    }
}
