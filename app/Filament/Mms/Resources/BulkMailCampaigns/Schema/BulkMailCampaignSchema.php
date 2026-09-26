<?php

namespace App\Filament\Mms\Resources\BulkMailCampaigns\Schema;

use App\Enums\BulkMailCampaignStatus;
use App\Models\BulkMailCampaign;
use App\Models\Matter;
use App\Services\MMS\BulkMailPlaceholders;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\HtmlString;

class BulkMailCampaignSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('bulk_mail.sections.details'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('bulk_mail.fields.name'))
                        ->required(),
                    TextInput::make('subject')
                        ->label(__('bulk_mail.fields.subject'))
                        ->required()
                        ->hint(__('bulk_mail.hints.subject')),
                    RichEditor::make('body')
                        ->label(__('bulk_mail.fields.body'))
                        ->required()
                        ->hint(__('bulk_mail.hints.body'))

                        ->columnSpanFull()
                        ->live(),

                    Select::make('from_sender_key')
                        ->label(__('bulk_mail.fields.from_sender'))
                        ->options(collect(Config::get('mail_senders.senders'))->mapWithKeys(fn ($s, $k) => [$k => $s['name']]))
                        ->required()
                        ->live(),

                    TextEntry::make('sender_signature')
                        ->label(__('bulk_mail.fields.signature_preview'))
                        ->state(fn ($get) => new HtmlString(Config::get("mail_senders.senders.{$get('from_sender_key')}.signature", '')))
                        ->visible(fn ($get) => filled($get('from_sender_key'))),
                ])->columns(2),

            Section::make(__('bulk_mail.sections.recipients'))
                ->schema([
                    TagsInput::make('cc_emails')
                        ->label(__('bulk_mail.fields.cc_emails'))
                        ->placeholder('email@example.com'),
                    TagsInput::make('bcc_emails')
                        ->label(__('bulk_mail.fields.bcc_emails'))
                        ->placeholder('email@example.com'),
                ])->columns(2),

            Section::make(__('bulk_mail.sections.placeholders'))
                ->schema([
                    // Optional: a campaign about one matter gets all its
                    // details as {{matter.*}} placeholders; left empty, it's
                    // a general mailing without them.
                    Select::make('matter_id')
                        ->label(__('Matter'))
                        ->relationship('matter', 'number', fn ($query) => $query->with('court')->latest('year')->latest('number'))
                        ->getOptionLabelFromRecordUsing(fn (Matter $matter) => $matter->reference.($matter->court ? ' — '.$matter->court->name : ''))
                        ->searchable(['number', 'year'])
                        ->nullable()
                        ->live()
                        ->helperText(__('Leave empty for a general mail not related to a matter.')),

                    TextEntry::make('available_placeholders')
                        ->label(__('Available placeholders'))
                        ->state(fn ($get, ?BulkMailCampaign $record) => self::placeholderGuide(
                            filled($get('matter_id')) ? Matter::find($get('matter_id')) : null,
                            $record,
                        )),

                    TagsInput::make('placeholders')
                        ->label(__('bulk_mail.fields.placeholders'))
                        ->hint(__('bulk_mail.hints.placeholders')),
                ]),

            Section::make(__('bulk_mail.sections.attachment'))
                ->schema([
                    Toggle::make('has_attachment')
                        ->label(__('bulk_mail.fields.has_attachment'))
                        ->live(),
                    FileUpload::make('attachment_path')
                        ->label(__('bulk_mail.fields.attachment'))
                        ->disk('public')
                        ->multiple()
                        ->previewable()
                        ->downloadable()
                        ->directory('mail_attachments')
                        ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->maxSize(10240)
                        ->visible(fn ($get) => $get('has_attachment')),
                ]),

            Section::make(__('bulk_mail.sections.schedule'))
                ->schema([
                    TextInput::make('daily_send_limit')
                        ->label(__('bulk_mail.fields.daily_limit'))
                        ->numeric()
                        ->default(50)
                        ->required(),
                    DateTimePicker::make('scheduled_at')
                        ->label(__('bulk_mail.fields.scheduled_at'))
                        ->hint(__('bulk_mail.hints.scheduled_at')),
                    Select::make('status')
                        ->label(__('bulk_mail.fields.status'))
                        ->options(BulkMailCampaignStatus::class)
                        ->required()
                        ->default(BulkMailCampaignStatus::Draft),
                ])->columns(3),
        ]);
    }

    /**
     * What can go in {{…}}: the recipient's own fields, the columns their
     * import brought in, and — with a matter chosen — every matter
     * placeholder next to its value for that matter.
     */
    private static function placeholderGuide(?Matter $matter, ?BulkMailCampaign $campaign): HtmlString
    {
        $code = fn (string $key): string => '<code style="font-size:0.8em;padding:1px 4px;border-radius:4px;background:rgba(127,127,127,0.15)" dir="ltr">{{'.e($key).'}}</code>';
        $row = fn (string $key, string $label): string => '<div style="display:flex;gap:8px;align-items:baseline;padding:2px 0">'.$code($key).'<span style="opacity:0.75">'.e($label).'</span></div>';

        $html = '<div style="font-weight:600;margin-bottom:4px">'.e(__('Recipient')).'</div>';
        $html .= $row('name', __('Recipient name')).$row('email', __('Recipient email'));

        $imported = collect($campaign?->recipients()->whereNotNull('placeholders')->limit(50)->pluck('placeholders'))
            ->flatMap(fn ($placeholders) => array_keys((array) $placeholders))
            ->unique();

        foreach ($imported as $key) {
            $html .= $row($key, __('From the imported file'));
        }

        $html .= '<div style="opacity:0.75;margin-top:4px">'.e(__('Any other column in the imported Excel file becomes a placeholder too — "Claim Amount" fills {{claim_amount}}.')).'</div>';

        if (! $matter) {
            return new HtmlString($html.'<div style="margin-top:10px;opacity:0.75">'.e(__('Choose a matter to add its details as placeholders.')).'</div>');
        }

        $values = BulkMailPlaceholders::forMatter($matter);
        $html .= '<div style="font-weight:600;margin:10px 0 4px">'.e(__('Matter')).' '.e($matter->reference).'</div>';

        foreach ([...BulkMailPlaceholders::matterCatalog(), ...array_fill_keys(array_keys(array_diff_key($values, BulkMailPlaceholders::matterCatalog())), __('Custom field'))] as $key => $label) {
            $value = $values[$key] ?? '';
            $html .= $row($key, $label.': '.($value !== '' ? $value : '—'));
        }

        return new HtmlString($html);
    }
}
