<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Support\EmailSendFields;
use App\Models\LetterFont;
use App\Models\MatterLetter;
use App\Models\Party;
use App\Models\Setting;
use App\Services\MMS\Letters\SharedAttachments;
use App\Services\MMS\SenderMailer;
use App\Support\Branding;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Communication → Letters & emails: the letters and the emails
 * sent and kept — their numbering, who is copied in, the mailbox they go
 * from, how their PDFs look.
 */
class LettersSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?int $navigationSort = 23;

    public static function getNavigationLabel(): string
    {
        return __('Letters & emails');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    protected static function permission(): string
    {
        return ScreenPermissions::SETTINGS_LETTERS_TAB;
    }

    protected function defaults(): array
    {
        return [
            'letter_reference_format' => Setting::get('letter_reference_format', MatterLetter::DEFAULT_REFERENCE_FORMAT),
            'letter_cc_expert_types' => MatterLetter::ccExpertTypes(),
            EmailSendFields::DEFAULT_SENDER => Setting::get(EmailSendFields::DEFAULT_SENDER),
            SharedAttachments::LIMIT => Setting::get(SharedAttachments::LIMIT, SharedAttachments::DEFAULT_LIMIT),
            Branding::EMAIL_PDF => Setting::get(Branding::EMAIL_PDF, Branding::EMAIL_PDF_OUTLOOK),
            Branding::EMAIL_PDF_FONT => Setting::get(Branding::EMAIL_PDF_FONT),
            'leave_request_recipients' => (array) Setting::get('leave_request_recipients', []),
        ];
    }

    protected function groups(): array
    {
        return [EmailSendFields::DEFAULT_SENDER => 'mail', SharedAttachments::LIMIT => 'mail'];
    }

    protected function fields(): array
    {
        return [
            Section::make(__('Letters'))
                ->schema([
                    // How each matter letter is numbered.
                    TextInput::make('letter_reference_format')
                        ->label(__('Letter reference format'))
                        ->default(MatterLetter::DEFAULT_REFERENCE_FORMAT)
                        ->required()
                        ->maxLength(100)
                        ->rule('regex:/\{seq\}/')
                        ->validationMessages(['regex' => __('The format must include {seq}, so each letter on a matter gets its own reference.')])
                        ->helperText(__('{year} and {number}: the matter\'s; {seq}: the letter\'s number on the matter (required); {current_year}: the year it is issued. Letters already issued keep their reference.'))
                        ->columnSpanFull(),

                    // Who is copied in when a letter is emailed.
                    CheckboxList::make('letter_cc_expert_types')
                        ->label(__('Copied in on letter and minutes emails'))
                        ->options(fn () => Party::expertTypeOptions())
                        ->default(MatterLetter::DEFAULT_CC_EXPERT_TYPES)
                        ->columns(2)
                        ->helperText(__('The matter\'s experts of these kinds are put in CC whenever a letter or minutes are sent by email; the sender can still remove them. None ticked: no one.'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Sending'))
                ->schema([
                    // Letters, minutes and bulk mail start from this mailbox; chosen again each send.
                    Select::make(EmailSendFields::DEFAULT_SENDER)
                        ->label(__('Send letters, minutes and bulk mail from'))
                        ->options(fn () => SenderMailer::options(sharedOnly: true))
                        ->placeholder(__('As the system emails'))
                        ->helperText(__('The mailbox a letter, minutes or a bulk mail campaign starts from when sent; another can be chosen each time.'))
                        ->columnSpanFull(),
                    // Files over this go by a link to a folder in the matter's OneDrive folder (SharedAttachments).
                    TextInput::make(SharedAttachments::LIMIT)
                        ->label(__('Files sent as a link above (MB)'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(150)
                        ->required()
                        ->helperText(__('When a letter\'s or minutes\' email with its files would be over this, the files added are put in a folder of the matter\'s OneDrive folder, under the sent emails, and sent as a link anyone with it may open — the letter or minutes attached as usual. The mail providers take about 25 MB an email. The link goes where {{attachments.link}} is written in the covering email or the letter, else in a note at the end of the email.'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Email PDFs'))
                ->schema([
                    Radio::make(Branding::EMAIL_PDF)
                        ->label(__('Email PDFs'))
                        ->helperText(__('The look of the PDF kept of each email sent or received.'))
                        ->options(Branding::emailPdfOptions())
                        ->default(Branding::EMAIL_PDF_OUTLOOK)
                        ->columnSpanFull(),
                    Select::make(Branding::EMAIL_PDF_FONT)
                        ->label(__('Email PDF font'))
                        ->options(fn () => LetterFont::query()->orderBy('name')->pluck('name', 'id'))
                        ->placeholder(__('Calibri, when uploaded; otherwise the standard font'))
                        ->helperText(__('A font uploaded under Letter fonts. The emails kept as PDF are written in it.'))
                        ->columnSpanFull(),
                ]),

            // Who gets a new leave request by email (and is copied in on its decision).
            Section::make(__('Leave Requests'))
                ->icon(Heroicon::CalendarDays)
                ->schema([
                    TagsInput::make('leave_request_recipients')
                        ->label(__('Leave requests are sent to'))
                        ->placeholder(__('Add an email address'))
                        ->nestedRecursiveRules(['email'])
                        ->helperText(__('Empty: everyone who can approve leave requests.')),
                ]),
        ];
    }
}
