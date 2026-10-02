<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Enums\LetterTemplateCategories;
use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Support\RichEditorDirection;
use App\Models\CalendarEvent;
use App\Models\EmailTemplate;
use App\Models\Letterhead;
use App\Models\LetterItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterParty;
use App\Services\MMS\Letters\Blocks\SavedSignatureBlock;
use App\Services\MMS\Letters\Blocks\SignatureBlock;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\LetterMeeting;
use App\Services\MMS\SenderMailer;
use App\Support\ScreenPermissions;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * A matter's letters, and issuing a new one: pick a template, tick who it
 * goes to, fill in the template's fields (items ticked from the library),
 * and it's numbered JPA/{year}/{number}/{n} and ready as PDF or Word.
 */
class LettersRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_LETTERS;
    }

    protected static string $relationship = 'letters';

    public static function getModelLabel(): string
    {
        return __('Letter');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Letters');
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Letters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->weight('bold')->searchable(),
                TextColumn::make('letter_date')->label(__('Date'))->date('d/m/Y')->sortable(),
                TextColumn::make('subject')->label(__('Subject'))->wrap()->searchable(),
                TextColumn::make('recipients.name')->label(__('To'))->listWithLineBreaks()->limitList(3)->expandableLimitedList(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->description(fn (MatterLetter $record) => $record->sent_at?->format('d/m/Y H:i')),
                TextColumn::make('template.name')->label(__('Template'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sentBy.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                $this->issueAction(),
                $this->writeAction(),
            ])
            ->recordActions([
                $this->previewAction(),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (MatterLetter $record) => route('letters.pdf', $record), shouldOpenInNewTab: true),
                Action::make('word')
                    ->label('Word')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (MatterLetter $record) => route('letters.docx', $record)),
                $this->emailAction(),
                ActionGroup::make([
                    $this->editAction(),
                    $this->deleteAction(),
                ]),
            ]);
    }

    /**
     * Email an issued letter: as an attachment under a covering email, or
     * as the email body; to everyone at once or separately.
     */
    private function emailAction(): Action
    {
        return Action::make('email')
            ->label(__('Send by email'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Send'))
            ->fillForm(fn (MatterLetter $record) => [
                // The mailbox this matter's letters last went from.
                'sender' => MatterLetter::query()->where('matter_id', $record->matter_id)->whereNotNull('sender_key')->latest('sent_at')->value('sender_key')
                    ?? array_key_first(SenderMailer::options()),
                'mode' => LetterMailer::ATTACHMENT,
                'email_template_id' => EmailTemplate::default()?->getKey(),
                'formats' => ['pdf'],
                'recipients' => $record->recipients->pluck('id')->all(),
                'separate' => false,
                // The matter's experts of the kinds System Settings names.
                'cc' => self::ccEmails($record->matter),
                ...app(LetterMailer::class)->draft($record, LetterMailer::ATTACHMENT, EmailTemplate::default()),
            ])
            ->schema(fn (MatterLetter $record) => [
                Select::make('sender')
                    ->label(__('Send from'))
                    ->options(SenderMailer::options())
                    ->required(),
                Radio::make('mode')
                    ->label(__('Send the letter'))
                    ->options([
                        LetterMailer::ATTACHMENT => __('As an attachment, with a covering email'),
                        LetterMailer::BODY => __('As the email itself'),
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::redraft($record, $get, $set)),
                Select::make('email_template_id')
                    ->label(__('Covering email'))
                    ->options(fn () => EmailTemplate::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('A short standard note'))
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::redraft($record, $get, $set))
                    ->visible(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT),
                CheckboxList::make('formats')
                    ->label(__('Attach as'))
                    ->options(['pdf' => 'PDF', 'docx' => 'Word'])
                    ->required(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT)
                    ->visible(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT)
                    ->columns(2),
                CheckboxList::make('recipients')
                    ->label(__('To'))
                    ->options($record->recipients->mapWithKeys(fn ($r) => [$r->id => trim($r->name.($r->role ? ' ('.$r->role.')' : ''))]))
                    ->descriptions($record->recipients->mapWithKeys(fn ($r) => [$r->id => implode(' · ', $r->allEmails()) ?: __('No email')]))
                    ->bulkToggleable()
                    ->live(),
                TagsInput::make('cc')
                    ->label(__('CC'))
                    ->placeholder('name@example.com')
                    ->helperText(__('The matter\'s experts chosen in System Settings are copied in; remove any you don\'t want.'))
                    ->nestedRecursiveRules(['email'])
                    ->live(),
                // Files of this send's own, beside the letter — whichever way it goes.
                FileUpload::make('attachments')
                    ->label(__('More attachments'))
                    ->helperText(__('Sent with the letter, for this email only.'))
                    ->multiple()
                    ->disk('local')
                    ->directory('letter-attachments')
                    ->storeFileNamesIn('attachment_names')
                    ->maxSize(20480)
                    ->live(),
                Toggle::make('separate')
                    ->label(__('A separate email to each recipient'))
                    ->helperText(__('Each then sees only their own address, and {{recipient.name}} greets them by name.'))
                    ->live(),

                // This email's own wording: starts from the template, with the
                // letter's details filled in; changed here, for this send only.
                Section::make(__('The email'))
                    ->description(__('Changes here are for this email only — the email template stays as it is.'))
                    ->schema([
                        TextInput::make('subject')
                            ->label(__('Subject'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),
                        RichEditor::make('body')
                            ->label(__('Email'))
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['bulletList', 'orderedList'],
                                ['alignStart', 'alignCenter', 'alignEnd'],
                                ['undo', 'redo'],
                            ])
                            ->tap(RichEditorDirection::apply(...))
                            ->extraInputAttributes(['dir' => LetterIssuer::composerFor($record)->isArabic() ? 'rtl' : 'ltr'])
                            ->live(onBlur: true)
                            ->visible(fn (Get $get) => $get('mode') === LetterMailer::ATTACHMENT),
                        Placeholder::make('as_body')
                            ->hiddenLabel()
                            ->content(__('The letter itself is the email. To change its wording, use Edit on the letter.'))
                            ->visible(fn (Get $get) => $get('mode') === LetterMailer::BODY),
                    ]),
                Section::make(__('Preview'))
                    ->collapsible()
                    ->schema([
                        Placeholder::make('email_preview')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => self::emailPreview($record, $get)),
                    ]),
            ])
            ->action(function (MatterLetter $record, array $data) {
                $result = app(LetterMailer::class)->send(
                    $record,
                    $data['sender'],
                    $data['mode'],
                    filled($data['email_template_id'] ?? null) ? EmailTemplate::find($data['email_template_id']) : null,
                    $data['formats'] ?? [],
                    array_map('intval', $data['recipients'] ?? []),
                    array_values($data['cc'] ?? []),
                    (bool) ($data['separate'] ?? false),
                    $data['subject'] ?? null,
                    ($data['mode'] ?? null) === LetterMailer::ATTACHMENT ? ($data['body'] ?? null) : null,
                    array_map(fn (string $path): array => [
                        'path' => Storage::disk('local')->path($path),
                        'name' => (string) ($data['attachment_names'][$path] ?? basename($path)),
                    ], array_values((array) ($data['attachments'] ?? []))),
                );

                // Sent: the uploads were for this email only.
                Storage::disk('local')->delete(array_values((array) ($data['attachments'] ?? [])));

                $notification = Notification::make()
                    ->title(__('Sent: :sent, failed: :failed, without email: :skipped', [
                        'sent' => $result['sent'],
                        'failed' => $result['failed'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->body($result['errors'] ? implode("\n", array_unique($result['errors'])) : null);

                match (true) {
                    $result['failed'] > 0 && $result['sent'] === 0 => $notification->danger(),
                    $result['failed'] > 0 || $result['skipped'] > 0 => $notification->warning(),
                    default => $notification->success(),
                };

                $notification->send();
            });
    }

    /**
     * Another covering email or way of sending chosen: start the subject
     * and email again from it.
     */
    private static function redraft(MatterLetter $record, Get $get, Set $set): void
    {
        $template = filled($get('email_template_id')) ? EmailTemplate::find($get('email_template_id')) : null;
        $draft = app(LetterMailer::class)->draft($record, (string) $get('mode'), $template);

        $set('subject', $draft['subject']);
        $set('body', $draft['body']);
    }

    /**
     * The emails of the matter's experts copied in on its letters — the
     * kinds ticked in System Settings (the assistants, unless changed) —
     * from their party, or the account they sign in with.
     *
     * @return list<string>
     */
    private static function ccEmails(?Matter $matter): array
    {
        $types = MatterLetter::ccExpertTypes();

        if (! $matter || $types === []) {
            return [];
        }

        return $matter->matterParties()
            ->with('party.user')
            ->where('role', 'expert')
            ->whereIn('type', $types)
            ->get()
            ->flatMap(fn (MatterParty $assistant): array => array_filter((array) ($assistant->party?->email ?: $assistant->party?->user?->email)))
            ->filter(fn ($email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The email as it will go out — to the first ticked recipient when each
     * gets their own.
     */
    private static function emailPreview(MatterLetter $record, Get $get): HtmlString
    {
        $mode = (string) ($get('mode') ?: LetterMailer::ATTACHMENT);
        $ticked = $record->recipients->whereIn('id', array_map('intval', $get('recipients') ?? []))->values();
        $separate = (bool) $get('separate');
        $template = filled($get('email_template_id')) ? EmailTemplate::find($get('email_template_id')) : null;
        $body = $get('body');

        $preview = app(LetterMailer::class)->preview(
            $record,
            $mode,
            $template,
            $separate ? $ticked->first() : null,
            $get('subject'),
            $mode === LetterMailer::ATTACHMENT && is_string($body) ? $body : null,
        );

        $addresses = fn ($recipient) => trim($recipient->name.' <'.implode(', ', $recipient->allEmails()).'>', ' <>');
        $to = $separate
            ? ($ticked->first() ? $addresses($ticked->first()).($ticked->count() > 1 ? ' — '.__('and a separate email to each of the other :count', ['count' => $ticked->count() - 1]) : '') : '')
            : $ticked->map($addresses)->implode(' · ');

        $name = LetterIssuer::fileName($record);
        $attachments = [
            ...($mode === LetterMailer::ATTACHMENT
                ? array_map(fn (string $format) => $name.'.'.$format, array_values(array_intersect(['pdf', 'docx'], $get('formats') ?? [])))
                : []),
            // The files added, by the names they were uploaded with.
            ...array_map(
                fn ($file): string => $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : basename((string) $file),
                array_values((array) ($get('attachments') ?? [])),
            ),
        ];

        return new HtmlString(view('filament.mms.letters.email-preview', [
            ...$preview,
            'to' => $to,
            'cc' => implode(', ', $get('cc') ?? []),
            'attachments' => $attachments,
        ])->render());
    }

    /**
     * The letter as it prints, on its letterhead — then print it, download
     * it, change it or send it from there.
     */
    private function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('Preview'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (MatterLetter $record) => __('Letter :reference', ['reference' => $record->reference]))
            ->modalWidth('6xl')
            ->modalContent(fn (MatterLetter $record) => view('filament.mms.letters.preview', [
                // A fresh copy each time, so a change shows at once.
                'url' => route('letters.pdf', $record).'?v='.$record->updated_at?->timestamp,
                'title' => $record->reference,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->extraModalFooterActions(fn (MatterLetter $record): array => [
                Action::make('printPdf')
                    ->label(__('Open to print'))
                    ->icon('heroicon-o-printer')
                    ->url(route('letters.pdf', $record), shouldOpenInNewTab: true),
                Action::make('downloadWord')
                    ->label('Word')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(route('letters.docx', $record)),
                Action::make('editFromPreview')
                    ->label(__('Edit'))
                    ->icon('heroicon-o-pencil')
                    ->color('gray')
                    ->action(fn () => $this->replaceMountedAction('editLetter', context: ['table' => true, 'recordKey' => (string) $record->getKey()])),
                Action::make('emailFromPreview')
                    ->label(__('Send by email'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->action(fn () => $this->replaceMountedAction('email', context: ['table' => true, 'recordKey' => (string) $record->getKey()])),
            ]);
    }

    /**
     * Delete a letter. Filament's own Delete is switched off on the matter's
     * View page (its tabs are read-only there), so this is the letters' own.
     */
    private function deleteAction(): Action
    {
        return Action::make('deleteLetter')
            ->label(__('Delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (MatterLetter $record) => __('Delete letter :reference', ['reference' => $record->reference]))
            ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
            ->action(function (MatterLetter $record): void {
                $record->delete();

                Notification::make()->success()->title(__('Letter :reference deleted', ['reference' => $record->reference]))->send();
            });
    }

    /**
     * "لعناية السيد/ … المحترم" under the addressees, when filled in.
     */
    private static function attentionField(): TextInput
    {
        return TextInput::make('attention')
            ->label(__('For the attention of'))
            ->placeholder(__('Name'))
            ->maxLength(255)
            ->helperText(__('Printed under the addressees as "لعناية السيد/ … المحترم". Leave empty for none.'));
    }

    /**
     * Change an issued letter: its date, letterhead, attention line,
     * recipients and wording — this letter's only, the template stays as it
     * is. Its reference stays as issued.
     */
    private function editAction(): Action
    {
        return Action::make('editLetter')
            ->label(__('Edit'))
            ->icon('heroicon-o-pencil')
            ->modalHeading(fn (MatterLetter $record) => __('Edit letter :reference', ['reference' => $record->reference]))
            ->modalWidth('4xl')
            ->fillForm(fn (MatterLetter $record): array => [
                'letter_date' => $record->letter_date?->toDateString(),
                'letterhead_id' => $record->letterhead_id,
                'attention' => $record->attention,
                'body' => (string) $record->body,
                // Ticked as when issued: the matter's parties it went to,
                // and anyone typed in under "Other recipients".
                ...self::recipientsState($record),
            ])
            ->schema(fn (MatterLetter $record): array => [
                Section::make()
                    ->columns(2)
                    ->schema([
                        DatePicker::make('letter_date')->label(__('Letter date'))->required(),
                        Select::make('letterhead_id')
                            ->label(__('Letterhead'))
                            ->options(fn () => Letterhead::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder(__('The template\'s letterhead')),
                    ]),
                self::recipientsSection($record->matter, separately: false),
                Section::make(__('The letter'))
                    ->description(__('Changes here are for this letter only — the template stays as it is.'))
                    ->collapsible()
                    ->schema([
                        self::bodyEditor(fn () => LetterComposer::catalog($record->template, $record->template?->inputs), fn () => LetterIssuer::composerFor($record)->isArabic()),
                    ]),
            ])
            ->action(function (MatterLetter $record, array $data): void {
                app(LetterIssuer::class)->revise(
                    $record,
                    self::chosenRecipients($record->matter, $data, LetterIssuer::composerFor($record)->isArabic()),
                    Carbon::parse($data['letter_date']),
                    filled($data['letterhead_id'] ?? null) ? Letterhead::find($data['letterhead_id']) : null,
                    $data['attention'] ?? null,
                    $data['body'] ?? null,
                );

                Notification::make()->success()->title(__('Letter :reference updated', ['reference' => $record->reference]))->send();
            });
    }

    /**
     * Who the letter goes to: the matter's parties ticked, others typed in,
     * one letter to all or one each (when issuing), and who it's for the
     * attention of.
     */
    private static function recipientsSection(Matter $matter, bool $separately = true): Section
    {
        // Listed in the interface's language; the letter gets them in its own (issue()).
        $candidates = LetterComposer::candidates($matter, app()->getLocale() !== 'en');

        return Section::make(__('Recipients'))
            ->schema([
                CheckboxList::make('recipients')
                    ->label(__('Recipients'))->hiddenLabel()
                    ->options(collect($candidates)->map(fn ($c) => trim($c['name'].($c['role'] ? ' ('.$c['role'].')' : '')))->all())
                    ->descriptions(collect($candidates)->map(fn ($c) => trim(implode(' · ', [...$c['emails'], ...$c['phones']])
                        .($c['representatives'] ? ' — '.__('With :names', ['names' => collect($c['representatives'])->pluck('name')->implode('، ')]) : ''), ' —'))->all())
                    ->default([])
                    ->bulkToggleable()
                    ->live()
                    ->columns(2),
                // A party goes with its representatives: by default "ووكيله
                // القانوني" on its line; ticked here, each named on their own.
                CheckboxList::make('name_representatives')
                    ->label(__('Name the representatives on their own lines'))
                    ->helperText(__('Otherwise the letter says "ووكيله القانوني" with the party. Either way the email goes to the representatives too.'))
                    ->options(fn (Get $get): array => collect($candidates)
                        ->only(array_map('intval', $get('recipients') ?? []))
                        ->filter(fn (array $c): bool => $c['representatives'] !== [])
                        ->map(fn (array $c): string => $c['name'])
                        ->all())
                    ->visible(fn (Get $get): bool => collect($candidates)->only(array_map('intval', $get('recipients') ?? []))->contains(fn (array $c): bool => $c['representatives'] !== []))
                    ->default([])
                    ->columns(2),
                Repeater::make('extra_recipients')
                    ->label(__('Other recipients'))
                    ->addActionLabel(__('Add recipient'))
                    ->defaultItems(0)
                    ->live()
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required(),
                        TextInput::make('role')->label(__('Capacity')),
                        TagsInput::make('emails')->label(__('Emails'))->nestedRecursiveRules(['email']),
                        TagsInput::make('phones')->label(__('Phones')),
                    ]),
                // Several recipients: one letter to all of them, or each
                // their own letter (own reference, only them on it).
                Toggle::make('separately')
                    ->label(__('Issue a separate letter to each recipient'))
                    ->helperText(__('Off: one letter addressed to all of them. On: each recipient gets their own letter, with its own reference.'))
                    ->default(false)
                    ->visible(fn (Get $get): bool => $separately && count($get('recipients') ?? []) + count($get('extra_recipients') ?? []) > 1),
                self::attentionField(),
            ]);
    }

    /**
     * The recipients ticked and typed in, each party's capacity ("المدعي"
     * / "Plaintiff") in the letter's language.
     *
     * Each party goes with its representatives ("name_representatives":
     * named on their own lines); a representative ticked as well as their
     * party isn't added twice.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function chosenRecipients(Matter $matter, array $data, bool $arabic): array
    {
        $candidates = LetterComposer::candidates($matter, $arabic);
        $ticked = collect($data['recipients'] ?? [])->map(fn ($id) => (int) $id)->filter(fn (int $id) => isset($candidates[$id]));
        $named = array_map('intval', $data['name_representatives'] ?? []);

        return [
            ...$ticked
                // A representative whose party is ticked already goes with it.
                ->reject(fn (int $id) => filled($candidates[$id]['of']) && $ticked->contains((int) $candidates[$id]['of']))
                ->map(fn (int $id) => [...$candidates[$id], 'name_representatives' => in_array($id, $named, true)])
                ->values()->all(),
            ...collect($data['extra_recipients'] ?? [])->map(fn ($r) => [
                'name' => (string) $r['name'],
                'role' => $r['role'] ?? null,
                'emails' => array_values($r['emails'] ?? []),
                'phones' => array_values($r['phones'] ?? []),
            ])->values()->all(),
        ];
    }

    /**
     * A letter's recipients as the form ticks them: each one who came from
     * the matter's parties ticked again, the rest as "Other recipients".
     *
     * @return array{recipients: list<int>, name_representatives: list<int>, extra_recipients: list<array{name: string, role: ?string, emails: list<string>}>}
     */
    private static function recipientsState(MatterLetter $letter): array
    {
        $candidates = LetterComposer::candidates($letter->matter);
        $ticked = [];
        $named = [];
        $extra = [];

        foreach ($letter->recipients as $recipient) {
            // The matter party it was: the same party, not ticked already.
            $id = filled($recipient->recipient_id)
                ? collect($candidates)->search(fn (array $c, int $id): bool => (int) $c['party_id'] === (int) $recipient->recipient_id && ! in_array($id, $ticked, true))
                : false;

            if ($id !== false) {
                $ticked[] = $id;

                if ($recipient->name_representatives) {
                    $named[] = $id;
                }

                continue;
            }

            $extra[] = [
                'name' => (string) $recipient->name,
                'role' => $recipient->role,
                'emails' => $recipient->emails ?: array_values(array_filter([$recipient->email])),
                'phones' => $recipient->phones ?? [],
            ];
        }

        return ['recipients' => $ticked, 'name_representatives' => $named, 'extra_recipients' => $extra];
    }

    /**
     * The letter's wording, as in the template editor: the same toolbar and
     * the same {{placeholders}} menu.
     *
     * @param  \Closure(): array<string, string>  $mergeTags
     * @param  \Closure(): bool  $arabic
     */
    private static function bodyEditor(\Closure $mergeTags, \Closure $arabic): RichEditor
    {
        return RichEditor::make('body')
            ->hiddenLabel()
            ->required()
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'textColor', 'highlight'],
                ['h2', 'h3', 'bulletList', 'orderedList', 'horizontalRule', 'table'],
                ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
                ['mergeTags', 'customBlocks'],
                ['undo', 'redo'],
            ])
            ->mergeTags($mergeTags)
            ->customBlocks([SavedSignatureBlock::class, SignatureBlock::class])
            ->tap(RichEditorDirection::apply(...))
            ->extraInputAttributes(fn () => ['dir' => $arabic() ? 'rtl' : 'ltr', 'style' => 'min-height: 24rem;']);
    }

    /**
     * A letter written here and now, without a template: the same
     * recipients, letterhead and date as any other, its own wording.
     */
    private function writeAction(): Action
    {
        return Action::make('write')
            ->label(__('Write a letter'))
            ->icon('heroicon-o-document-plus')
            ->color('gray')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel(__('Issue'))
            ->fillForm(fn (): array => [
                'locale' => 'ar',
                'letter_date' => now()->toDateString(),
                'letterhead_id' => Letterhead::default()?->getKey(),
                'recipients' => [],
                'body' => self::freeLetterBody(true),
                ...self::meetingDefaults(),
            ])
            ->schema(fn (): array => [
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextInput::make('subject')
                            ->label(__('Subject'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Radio::make('locale')
                            ->label(__('Language'))
                            ->options(['ar' => __('Arabic'), 'en' => __('English')])
                            ->inline()
                            ->required()
                            ->live()
                            // Untouched wording follows the language.
                            ->afterStateUpdated(function (?string $state, ?string $old, Get $get, Set $set) {
                                if ($get('body') === self::freeLetterBody($old !== 'en')) {
                                    $set('body', self::freeLetterBody($state !== 'en'));
                                }
                            }),
                        DatePicker::make('letter_date')
                            ->label(__('Letter date'))
                            ->required(),
                        Select::make('letterhead_id')
                            ->label(__('Letterhead'))
                            ->options(fn () => Letterhead::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder(__('The default letterhead'))
                            ->columnSpan(2),
                    ]),
                self::recipientsSection($this->getOwnerRecord()),
                Section::make(__('The letter'))
                    ->description(__('{{recipients}} places the addressees and {{signature}} the signature; the menu has the matter\'s other details.'))
                    ->schema([
                        self::bodyEditor(fn () => LetterComposer::catalog(), fn (): bool => true),
                    ]),
                ...$this->meetingFields(null, ownDateAndTime: true),
            ])
            ->action(fn (array $data, Action $action) => $this->issueOrStop($data, $action));
    }

    /**
     * Where a free letter starts: addressees, greeting, a space to write
     * in, closing and signature.
     */
    public static function freeLetterBody(bool $arabic): string
    {
        return $arabic
            ? '<p>{{recipients}}</p><p>تحية طيبة وبعد،</p><p><strong>الموضوع: {{subject}}</strong></p><p></p><p>وتفضلوا بقبول فائق الاحترام والتقدير،</p><p>{{signature}}</p>'
            : '<p>{{recipients}}</p><p>Dear Sir/Madam,</p><p><strong>Subject: {{subject}}</strong></p><p></p><p>Yours faithfully,</p><p>{{signature}}</p>';
    }

    private function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('Issue letter'))
            ->icon('heroicon-o-pencil-square')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel(__('Issue'))
            ->schema(fn () => $this->issueForm($this->getOwnerRecord()))
            ->action(fn (array $data, Action $action) => $this->issueOrStop($data, $action));
    }

    /**
     * Issued — or, when its Teams meeting can't be made, not: it would go
     * out without its link.
     *
     * @param  array<string, mixed>  $data
     */
    private function issueOrStop(array $data, Action $action): void
    {
        try {
            $this->notifyIssued($this->issue($this->getOwnerRecord(), $data));
        } catch (\RuntimeException $e) {
            if (empty($data['create_meeting'])) {
                throw $e;
            }

            Notification::make()->danger()->title(__('The Teams meeting could not be created'))->body($e->getMessage())->persistent()->send();
            $action->halt();
        }
    }

    /**
     * @param  list<MatterLetter>  $letters
     */
    private function notifyIssued(array $letters): void
    {
        if (count($letters) > 1) {
            Notification::make()
                ->success()
                ->title(__(':count letters issued, one for each recipient', ['count' => count($letters)]))
                ->body(new HtmlString(collect($letters)->map(fn (MatterLetter $letter) => e($letter->reference.' — '.$letter->recipients->pluck('name')->implode(', ')))->implode('<br>')))
                ->send();

            return;
        }

        $letter = $letters[0];

        Notification::make()
            ->success()
            ->title(__('Letter :reference issued', ['reference' => $letter->reference]))
            ->actions([
                Action::make('pdf')->label('PDF')->url(route('letters.pdf', $letter), shouldOpenInNewTab: true),
                Action::make('word')->label('Word')->url(route('letters.docx', $letter)),
            ])
            ->send();
    }

    /**
     * @return list<mixed>
     */
    private function issueForm(Matter $matter): array
    {
        return [
            Section::make()
                ->columns(3)
                ->schema([
                    Select::make('letter_template_id')
                        ->label(__('Template'))
                        // Only the templates for this matter's type (and those for every type).
                        ->options(fn () => LetterTemplate::query()->forMatterType($matter->type_id)->where('category', '!=', LetterTemplateCategories::MINUTES->value)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($state, Set $set) => $this->prefill($matter, $state ? LetterTemplate::find($state) : null, $set))
                        ->columnSpan(2),
                    DatePicker::make('letter_date')
                        ->label(__('Letter date'))
                        ->default(now())
                        ->required(),
                    Select::make('letterhead_id')
                        ->label(__('Letterhead'))
                        ->options(fn () => Letterhead::query()->orderBy('name')->pluck('name', 'id'))
                        ->placeholder(__('The template\'s letterhead'))
                        ->columnSpan(3),
                ]),

            self::recipientsSection($matter),

            Section::make(__('Letter details'))
                ->visible(fn (Get $get) => filled($get('letter_template_id')))
                ->schema(fn (Get $get) => $this->inputFields($matter, LetterTemplate::find($get('letter_template_id'))))
                ->columns(2),
        ];
    }

    /**
     * The template's own fields, as form components under inputs.*.
     *
     * @return list<mixed>
     */
    private function inputFields(Matter $matter, ?LetterTemplate $template): array
    {
        $fields = [];
        // Filled by the meeting made on issue, when it is.
        $meetingLinks = LetterMeeting::fields($template)['links'] ?? [];

        foreach ($template?->inputs ?? [] as $input) {
            $key = $input['key'] ?? null;
            if (blank($key)) {
                continue;
            }

            $name = 'inputs.'.$key;
            $label = $input['label'] ?? $key;
            $required = ! empty($input['required']);

            if (($input['type'] ?? 'text') === 'items') {
                $fields[] = Group::make([
                    CheckboxList::make($name)
                        ->label($label)
                        ->options(fn () => LetterItem::query()->forGroup($input['group'] ?? null, $matter->type_id)->pluck('text', 'id'))
                        ->bulkToggleable()
                        ->searchable()
                        ->required($required),
                    Textarea::make('extra.'.$key)
                        ->label(__('More lines for this list (one per line)'))
                        ->rows(2),
                ])->columnSpanFull();

                continue;
            }

            $fields[] = (match ($input['type'] ?? 'text') {
                'textarea' => Textarea::make($name)->rows(3)->columnSpanFull(),
                'date' => DatePicker::make($name),
                'time' => TimePicker::make($name)->seconds(false),
                'url' => TextInput::make($name)->url()->columnSpanFull(),
                'number' => TextInput::make($name)->numeric(),
                'select' => Select::make($name)->options(array_combine($input['options'] ?? [], $input['options'] ?? []) ?: []),
                default => TextInput::make($name),
            })->label($label)->required($required)
                ->hidden(fn (Get $get): bool => in_array($key, $meetingLinks, true) && (bool) $get('create_meeting'));
        }

        return [...$fields, ...$this->meetingFields($template)];
    }

    /**
     * For a template with a meeting link: make the meeting now — on the
     * matter's calendar and in Outlook, with Teams — and put its link in
     * the letter.
     *
     * @return list<mixed>
     */
    private function meetingFields(?LetterTemplate $template, bool $ownDateAndTime = false): array
    {
        $fields = LetterMeeting::fields($template);

        if ((! $fields && ! $ownDateAndTime) || ! app(LetterMeeting::class)->available()) {
            return [];
        }

        // The template has no date and time for it: asked here.
        $askWhen = $ownDateAndTime || blank($fields['date'] ?? null) || blank($fields['time'] ?? null);
        $creating = fn (Get $get): bool => (bool) $get('create_meeting');

        return [
            Section::make()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Toggle::make('create_meeting')
                        ->label(__('Create a Teams meeting in Outlook and put its link in the letter'))
                        ->helperText($askWhen
                            ? __('On this matter\'s calendar. The link goes where the letter has :placeholder.', ['placeholder' => '{'.'{meeting.link}'.'}'])
                            : __('On the meeting date and time above, on this matter\'s calendar. The link goes where the template has its link field or :placeholder.', ['placeholder' => '{'.'{meeting.link}'.'}']))
                        ->live()
                        ->columnSpanFull(),
                    ...($askWhen ? [
                        DatePicker::make('meeting_date')
                            ->label(__('Meeting date'))
                            ->required($creating)
                            ->visible($creating),
                        TimePicker::make('meeting_time')
                            ->label(__('Meeting time'))
                            ->seconds(false)
                            ->required($creating)
                            ->visible($creating),
                    ] : []),
                    TextInput::make('meeting_minutes')
                        ->label(__('Duration (minutes)'))
                        ->numeric()->minValue(15)->maxValue(480)
                        ->default(60)
                        ->visible(fn (Get $get): bool => (bool) $get('create_meeting')),
                    Toggle::make('invite_recipients')
                        ->label(__('Send the Outlook invitation to the recipients too'))
                        ->helperText(__('Off: the meeting is in your calendar only; the letter carries the link.'))
                        ->inline(false)
                        ->visible(fn (Get $get): bool => (bool) $get('create_meeting')),
                ]),
        ];
    }

    /**
     * The meeting section's starting values.
     *
     * @return array<string, mixed>
     */
    private static function meetingDefaults(): array
    {
        return ['create_meeting' => false, 'meeting_minutes' => 60, 'invite_recipients' => false, 'meeting_date' => null, 'meeting_time' => null];
    }

    /**
     * Meeting fields filled from the matter's next calendar event: a field
     * whose key contains "meeting" gets its date, time or Teams link.
     */
    private function prefill(Matter $matter, ?LetterTemplate $template, Set $set): void
    {
        if (! $template) {
            return;
        }

        $event = CalendarEvent::query()
            ->where('matter_id', $matter->getKey())
            ->where('start_datetime', '>=', now())
            ->orderBy('start_datetime')
            ->first();

        if ($template->letterhead_id) {
            $set('letterhead_id', $template->letterhead_id);
        }

        // The fields that appear with the template start with a value. Left
        // unset, the browser doesn't reliably send what's then picked —
        // the meeting switch showed on, and reached the server off.
        foreach (self::meetingDefaults() as $field => $value) {
            $set($field, $value);
        }

        foreach ($template->inputs ?? [] as $input) {
            $key = $input['key'] ?? '';

            // A list of items starts as an empty list (unset, its checkboxes
            // shared one true/false value: ticking one ticked them all);
            // every other field as empty.
            if ($key !== '') {
                $set('inputs.'.$key, ($input['type'] ?? null) === 'items' ? [] : null);
            }
            if (! $event || ! str_contains($key, 'meeting') && ! str_contains($key, 'teams')) {
                continue;
            }

            match ($input['type'] ?? null) {
                'date' => $set('inputs.'.$key, $event->start_datetime?->toDateString()),
                'time' => $set('inputs.'.$key, $event->start_datetime?->format('H:i')),
                'url' => $set('inputs.'.$key, $event->online_meeting_url),
                default => null,
            };
        }
    }

    /**
     * The letter — or, issued separately, one letter per recipient.
     *
     * @param  array<string, mixed>  $data
     * @return list<MatterLetter>
     */
    public function issue(Matter $matter, array $data): array
    {
        // Written here without a template: its own wording, subject and language.
        $template = filled($data['letter_template_id'] ?? null)
            ? LetterTemplate::findOrFail($data['letter_template_id'])
            : new LetterTemplate([
                'locale' => ($data['locale'] ?? 'ar') === 'en' ? 'en' : 'ar',
                'subject' => (string) ($data['subject'] ?? ''),
                'body' => LetterComposer::normalizeMergeTags((string) ($data['body'] ?? '')),
            ]);
        $recipients = self::chosenRecipients($matter, $data, $template->locale !== 'en');

        $inputs = $data['inputs'] ?? [];
        foreach ($data['extra'] ?? [] as $key => $lines) {
            $more = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $lines))));
            $inputs[$key] = [...array_values((array) ($inputs[$key] ?? [])), ...$more];
        }

        // The meeting it invites to, made first: its link goes in the letter.
        if (! empty($data['create_meeting'])) {
            $fields = LetterMeeting::fields($template) ?? ['links' => []];
            $start = filled($data['meeting_date'] ?? null) && filled($data['meeting_time'] ?? null)
                ? Carbon::parse($data['meeting_date'].' '.$data['meeting_time'], config('app.timezone'))
                : null;

            $attendees = ! empty($data['invite_recipients'])
                ? collect($recipients)->flatMap(fn (array $r) => collect([...($r['emails'] ?? []), ...collect($r['representatives'] ?? [])->flatMap(fn ($rep) => $rep['emails'] ?? [])->all()])
                    ->map(fn (string $email) => ['email' => $email, 'name' => $r['name']]))
                    ->unique('email')->values()->all()
                : [];

            $event = app(LetterMeeting::class)->create($matter, $template, $inputs, (int) ($data['meeting_minutes'] ?? 60), $attendees, auth()->id(), $start);
            $meetingLink = (string) $event->online_meeting_url;

            // Every place the template has for it, and {{meeting.link}}.
            foreach ($fields['links'] as $key) {
                $inputs[$key] = $meetingLink;
            }
            $inputs[LetterComposer::MEETING_LINK] = $meetingLink;
            $inputs[LetterComposer::MEETING_START] = $event->start_datetime->format('Y-m-d H:i');
        }

        $date = filled($data['letter_date'] ?? null) ? Carbon::parse($data['letter_date']) : now();
        $letterhead = filled($data['letterhead_id'] ?? null) ? Letterhead::find($data['letterhead_id']) : null;
        $issuer = app(LetterIssuer::class);

        // Separately: the same letter for each recipient alone.
        $groups = ! empty($data['separately']) && count($recipients) > 1
            ? array_map(fn (array $recipient): array => [$recipient], $recipients)
            : [$recipients];

        $letters = array_map(
            fn (array $group): MatterLetter => $issuer->issue($template, $matter, $group, $inputs, $date, $letterhead, auth()->id(), $data['attention'] ?? null),
            $groups,
        );

        // Uses the meeting's link, but no meeting was made: say so.
        if (! isset($meetingLink) && LetterMeeting::usesMeetingLink((string) $template->body)) {
            Notification::make()
                ->warning()
                ->title(__('This letter has :placeholder, but no Teams meeting was created', ['placeholder' => '{'.'{meeting.link}'.'}']))
                ->body(__('Switch on "Create a Teams meeting" when issuing it, or take the placeholder out of the text.'))
                ->persistent()
                ->send();
        }

        // Made, but with nowhere in the letter to show: say so, and how.
        if (isset($meetingLink) && ! str_contains((string) $letters[0]->rendered_html, e($meetingLink))) {
            Notification::make()
                ->warning()
                ->title(__('The Teams meeting was created, but this letter has no place for its link'))
                ->body(__('Add :placeholder to the template\'s text where the link should go. The meeting is in the calendar: :link', [
                    'placeholder' => '{'.'{meeting.link}'.'}',
                    'link' => $meetingLink,
                ]))
                ->persistent()
                ->send();
        }

        return $letters;
    }
}
