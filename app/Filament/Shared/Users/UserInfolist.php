<?php

namespace App\Filament\Shared\Users;

use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use TomatoPHP\FilamentUsers\Filament\Resources\Users\Schemas\UserInfolist as PluginUserInfolist;

/**
 * A user's page as it reads (Users → View), laid out as their edit page: two columns, each its sections
 * one under another — their account and, for an assistant, their OneDrive folders, reviewers and
 * administrators; their work account — the Microsoft 365 account, their own mailbox and what it does.
 */
class UserInfolist extends PluginUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make(__('Account'))
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->columns(2)
                        ->schema([
                            ...static::getDefaultComponents(),
                            ...static::$schema,
                            TextEntry::make('display_name')->label(__('Display name'))->placeholder('—'),
                            TextEntry::make('roles.name')->label(__('Roles'))->badge()->placeholder('—'),
                        ]),
                    Section::make(__('Assistant'))
                        ->icon(Heroicon::OutlinedCloud)
                        ->columns(2)
                        ->visible(fn (User $record): bool => (bool) $record->party?->isAssistant())
                        ->schema([
                            TextEntry::make('party.onedrive_path')->label(__('Folder for matters in their OneDrive'))->placeholder(__('The top of their OneDrive')),
                            TextEntry::make('party.onedrive_finished_path')->label(__('Folder for finished matters in their OneDrive'))->placeholder('—'),
                            TextEntry::make('party.reportReviewers.name')->label(__('Report reviewers (WhatsApp)'))->badge()->placeholder('—'),
                            TextEntry::make('party.administrators.name')->label(__('Administrators of their matters'))->badge()->placeholder('—'),
                        ]),
                ]),
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make(__('Work account'))
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->columns(2)
                        ->schema([
                            TextEntry::make('ms_account')
                                ->label(__('Microsoft 365 account'))
                                ->state(fn (User $record): ?string => $record->msAccount())
                                ->extraAttributes(['dir' => 'ltr'])
                                ->columnSpanFull(),
                            IconEntry::make('mailbox_reads')->label(__('Read and file its emails'))->boolean(),
                            TextEntry::make('mailbox_keeps_all')
                                ->label(__('Which emails'))
                                ->state(fn (User $record): string => $record->mailbox_keeps_all ? __('Every email') : __('Only those about a matter'))
                                ->visible(fn (User $record): bool => $record->mailbox_reads),
                            IconEntry::make('mailbox_sends')->label(__('Send from it'))->boolean(),
                            IconEntry::make('shared_senders')->label(__('May send from the office\'s shared mailboxes'))->boolean(),
                            TextEntry::make('unfiled_all')
                                ->label(__('In Unfiled emails, from the shared mailboxes'))
                                ->state(fn (User $record): string => $record->seesAllUnfiled() ? __('All of them') : __('Those that may be about their matters'))
                                ->visible(fn (User $record): bool => (bool) $record->shared_senders),
                            TextEntry::make('mail_signature')
                                ->label(__('Signature'))
                                ->state(fn (User $record): ?HtmlString => filled($record->mail_signature) ? new HtmlString((string) $record->mail_signature) : null)
                                ->placeholder(__('The office\'s'))
                                ->visible(fn (User $record): bool => $record->mailbox_sends)
                                ->columnSpanFull(),
                        ]),
                ]),
            ]);
    }
}
