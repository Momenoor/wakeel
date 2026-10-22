<?php

namespace App\Filament\Shared\Users;

use App\Filament\Forms\Components\RichText;
use App\Filament\Support\AssistantSettingsFields;
use App\Models\User;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserForm as PluginUserForm;

/**
 * A user's page (Users → Edit), all of what is theirs in one place: their account; their work account — the
 * Microsoft 365 account their OneDrive, calendar and own mailbox are in, whether that mailbox is read and
 * filed and sent from, their signature, whether they may send from the office's shared mailboxes; and, for an
 * assistant, their OneDrive folders, reviewers and administrators (kept on their party).
 */
class UserForm extends PluginUserForm
{
    public static function configure(Schema $schema): Schema
    {
        // Two columns, each its sections one under another: the account and, for an assistant, theirs; the work account.
        return $schema->columns(2)->components([
            Grid::make(1)->columnSpan(1)->schema([
                Section::make(__('Account'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->columns(2)
                    ->schema([...static::getDefaultComponents(), ...static::$schema]),
                Section::make(__('Assistant'))
                    ->icon(Heroicon::OutlinedCloud)
                    ->description(__('Where this assistant\'s matter folders are made in OneDrive, who reviews their reports, and who handles their matters with them.'))
                    ->columns(2)
                    // Its own place in the form: "party" is the account's party picker.
                    ->relationship('party')
                    ->statePath('assistant')
                    ->visible(fn (?User $record): bool => (bool) $record?->party?->isAssistant())
                    ->schema(AssistantSettingsFields::make(withAccount: false)),
            ]),
            Grid::make(1)->columnSpan(1)->schema([self::workAccount()]),
        ]);
    }

    private static function workAccount(): Section
    {
        return Section::make(__('Work account'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->description(__('One Microsoft 365 account for their OneDrive, calendar and own mailbox. Microsoft 365 is reached with the office\'s app registration: no password is needed.'))
            ->columns(2)
            ->schema([
                TextInput::make('ms_account')
                    ->label(__('Microsoft 365 account'))
                    ->email()
                    ->maxLength(255)
                    ->placeholder(fn (?User $record): ?string => $record?->email)
                    ->helperText(__('Leave empty to use the email they sign in with.'))
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->columnSpanFull(),
                Toggle::make('mailbox_reads')
                    ->label(__('Read and file its emails'))
                    ->helperText(__('Emails about a matter are filed on it, as with the office\'s mailboxes.'))
                    ->live(),
                Radio::make('mailbox_keeps_all')
                    ->label(__('Which emails'))
                    ->options([0 => __('Only those about a matter'), 1 => __('Every email')])
                    ->descriptions([
                        0 => __('The rest are left unread in the mailbox.'),
                        1 => __('Those that cannot be told wait in their own Unfiled emails, for them alone, to file or set aside.'),
                    ])
                    ->default(0)
                    ->formatStateUsing(fn ($state): int => (int) (bool) $state)
                    ->dehydrateStateUsing(fn ($state): bool => (bool) $state)
                    ->visible(fn (Get $get): bool => (bool) $get('mailbox_reads'))
                    ->columnSpanFull(),
                Toggle::make('mailbox_sends')
                    ->label(__('Send from it'))
                    ->helperText(__('In their "Send from" list only — no one else\'s.'))
                    ->live(),
                Toggle::make('shared_senders')
                    ->label(__('May send from the office\'s shared mailboxes'))
                    ->default(true)
                    ->columnSpanFull(),
                RichText::make('mail_signature')
                    ->label(__('Signature'))
                    ->helperText(__('At the end of every email sent from their mailbox. Empty: the office\'s.'))
                    ->email()
                    ->contentDirection('ltr')
                    ->visible(fn (Get $get): bool => (bool) $get('mailbox_sends'))
                    ->columnSpanFull(),
            ]);
    }
}
