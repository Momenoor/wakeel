<?php

namespace App\Filament\Mms\Resources\MailSenders;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Forms\Components\RichText;
use App\Filament\Mms\Resources\MailSenders\Pages\ManageMailSenders;
use App\Filament\Shared\Clusters\Settings;
use App\Models\MailSender;
use App\Services\MMS\MailboxDetector;
use App\Services\MMS\SenderMailer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Mailboxes to send letters and bulk mail from: a cPanel mailbox (SMTP) or
 * a Microsoft 365 one (Microsoft Graph). Each can be tested from here.
 */
class MailSenderResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = MailSender::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAtSymbol;

    protected static ?int $navigationSort = 20;

    protected static ?string $cluster = Settings::class;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public static function getModelLabel(): string
    {
        return __('Mail sender');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Mail senders');
    }

    public static function graphConfigured(): bool
    {
        return filled(config('mail.mailers.microsoft-graph.tenant_id'))
            && filled(config('mail.mailers.microsoft-graph.client_id'))
            && filled(config('mail.mailers.microsoft-graph.client_secret'));
    }

    public static function form(Schema $schema): Schema
    {
        $smtp = fn (Get $get) => $get('driver') === MailSender::SMTP;

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    // The address first: leaving it works out where the
                    // mailbox lives and fills in the rest (MailboxDetector).
                    TextInput::make('address')
                        ->label(__('Email address'))
                        ->email()
                        ->required()
                        ->placeholder('noreply@example.com')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Get $get, Set $set) {
                            if (blank($state) || ! filter_var($state, FILTER_VALIDATE_EMAIL)) {
                                return;
                            }

                            if (blank($get('key'))) {
                                $set('key', Str::slug(Str::before((string) $state, '@'), '_'));
                            }

                            $found = app(MailboxDetector::class)->detect((string) $state);
                            $set('driver', $found['driver']);
                            $set('detection', $found['note']);

                            if ($found['driver'] === MailSender::SMTP) {
                                $set('host', $get('host') ?: $found['host']);
                                $set('port', $get('port') ?: $found['port']);
                                $set('encryption', $get('encryption') ?: $found['encryption']);
                                $set('username', $get('username') ?: $state);
                            }
                        }),
                    Radio::make('driver')
                        ->label(__('Hosted on'))
                        ->options([
                            MailSender::MICROSOFT => __('Microsoft 365'),
                            MailSender::SMTP => __('cPanel / other mail server (SMTP)'),
                        ])
                        ->default(MailSender::MICROSOFT)
                        ->required()
                        ->live(),
                    Hidden::make('detection')->dehydrated(false),
                    TextEntry::make('detection_note')
                        ->hiddenLabel()
                        ->visible(fn (Get $get) => filled($get('detection')))
                        ->state(fn (Get $get) => $get('detection'))
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('info')
                        ->columnSpanFull(),
                    TextInput::make('name')
                        ->label(__('Sender name'))
                        ->required()
                        ->placeholder('JPA Emirates'),
                    TextInput::make('key')
                        ->label(__('Code'))
                        ->required()
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->helperText(__('Short unique name, e.g. noreply.')),
                    Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
                    Toggle::make('keep_all_inbox')
                        ->label(__('Keep every email of this inbox'))
                        ->default(false)
                        ->inline(false)
                        ->helperText(__("Off: only replies and emails from a matter's parties are kept from this mailbox. On: every other email too (except the office's own) goes to Unfiled emails, to file on a matter or set aside.")),
                    // Only for this: everything in it is an instruction to file, never correspondence.
                    Toggle::make('files_forwards')
                        ->label(__('Filing mailbox'))
                        ->default(false)
                        ->inline(false)
                        ->helperText(__('For emails whose matter cannot be told: someone in the office forwards the email here with the matter number (1200/2026) in the subject or first line, or in the address (name+1200-2026@…), and the original is filed on that matter as it came from its own sender. Keep it for this alone.')),

                    TextEntry::make('graph_status')
                        ->hiddenLabel()
                        ->visible(fn (Get $get) => $get('driver') === MailSender::MICROSOFT)
                        ->state(fn () => static::graphConfigured()
                            ? __('Sent through Microsoft Graph with the app registration in .env. No password is needed here. The mailbox must exist in your Microsoft 365 tenant.')
                            : __('Microsoft Graph is not set up yet: add MICROSOFT_GRAPH_TENANT_ID, MICROSOFT_GRAPH_CLIENT_ID and MICROSOFT_GRAPH_CLIENT_SECRET to .env (an Azure app registration with the Mail.Send application permission).'))
                        ->helperText(__('With the User.Read.All permission as well, the app can tell for certain whether an address is a Microsoft 365 or a cPanel mailbox.'))
                        ->color(fn () => static::graphConfigured() ? 'gray' : 'danger')
                        ->columnSpanFull(),
                ]),

            Section::make(__('Mail server'))
                ->visible($smtp)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('host')->label(__('SMTP Host'))->placeholder('mail.example.com')->required($smtp),
                    TextInput::make('port')->label(__('SMTP Port'))->numeric()->default(587)->required($smtp),
                    Select::make('encryption')
                        ->label(__('Encryption'))
                        ->options(['tls' => 'TLS', 'ssl' => 'SSL', 'none' => __('None')])
                        ->default('tls'),
                    TextInput::make('username')->label(__('SMTP Username'))->placeholder(__('Usually the email address')),
                    TextInput::make('password')
                        ->label(__('SMTP Password'))
                        ->password()
                        ->revealable()
                        // Blank on edit keeps the saved one.
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (Get $get, ?MailSender $record) => $get('driver') === MailSender::SMTP && ! $record?->password),
                ]),

            Section::make(__('Signature'))
                ->description(__('Added under bulk mail sent from this mailbox.'))
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    // Its logos side by side, as in the email (the editor shows pictures in the line).
                    RichText::make('signature')->hiddenLabel()->email()->contentDirection('ltr'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('name')->label(__('Sender name'))->weight('bold')->searchable()
                    ->description(fn (MailSender $record) => $record->address),
                // Shared, or someone's own (set on their user's page).
                TextColumn::make('user.name')
                    ->label(__('Mailbox'))
                    ->badge()
                    ->state(fn (MailSender $record): string => $record->user ? __('Personal — :name', ['name' => $record->user->display_name ?: $record->user->name]) : __('Shared'))
                    ->color(fn (MailSender $record): string => $record->user ? 'warning' : 'success'),
                TextColumn::make('driver')
                    ->label(__('Hosted on'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === MailSender::MICROSOFT ? 'Microsoft 365' : 'SMTP')
                    ->color(fn (string $state) => $state === MailSender::MICROSOFT ? 'info' : 'gray'),
                TextColumn::make('key')->label(__('Code')),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                IconColumn::make('keep_all_inbox')->label(__('Keeps every email'))->boolean(),
                IconColumn::make('files_forwards')->label(__('Filing mailbox'))->boolean(),
            ])
            ->recordActions([
                Action::make('test')
                    ->label(__('Send test email'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->schema([
                        TextInput::make('to')->label(__('Send to'))->email()->required()->default(fn () => auth()->user()?->email),
                    ])
                    ->action(function (MailSender $record, array $data) {
                        try {
                            SenderMailer::using($record->toSender(), fn () => Mail::raw(
                                __('This is a test email from :name (:address) sent by :app.', ['name' => $record->name, 'address' => $record->address, 'app' => config('app.name')]),
                                fn ($message) => $message->to($data['to'])->subject(__('Test email')),
                            ));

                            Notification::make()->success()->title(__('Test email sent to :to', ['to' => $data['to']]))->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title(__('The test email failed'))->body($e->getMessage())->persistent()->send();
                        }
                    }),
                // Someone's own: changed on their user's page (their work account), not here.
                EditAction::make()->modalWidth('5xl')->visible(fn (MailSender $record): bool => $record->user_id === null),
                DeleteAction::make()->visible(fn (MailSender $record): bool => $record->user_id === null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMailSenders::route('/'),
        ];
    }
}
