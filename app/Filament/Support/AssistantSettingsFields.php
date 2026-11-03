<?php

namespace App\Filament\Support;

use App\Services\MMS\MatterOneDriveFolders;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * An assistant's own settings, on their party: where their matter folders are made in OneDrive (and moved
 * once finished), who reviews their reports, and who handles their matters with them. On the user's page
 * (Users → Edit → Assistant) — the account comes from the user's work account there; on the party's page
 * only for an assistant with no user, the account with them.
 */
final class AssistantSettingsFields
{
    /** @return list<Field> */
    public static function make(bool $withAccount): array
    {
        return array_values(array_filter([
            $withAccount ? TextInput::make('onedrive_email')
                ->label(__('OneDrive account (Microsoft 365 email)'))
                ->email()
                ->maxLength(255)
                ->columnSpanFull() : null,
            TextInput::make('onedrive_path')
                ->label(__('Folder for matters in their OneDrive'))
                ->placeholder(fn (): string => MatterOneDriveFolders::standardFolders()['matters'])
                ->helperText(__('Leave empty to put matter folders at the top of their OneDrive. The standard name for everyone is set under Settings → OneDrive Folders, and given to every assistant from there.'))
                ->maxLength(255)
                ->extraInputAttributes(['dir' => 'auto']),
            // A matter's folder is moved here once the matter is finished (its final report).
            TextInput::make('onedrive_finished_path')
                ->label(__('Folder for finished matters in their OneDrive'))
                ->placeholder(fn (): string => MatterOneDriveFolders::standardFolders()['finished'])
                ->helperText(__('When a matter is finished, its folder is moved here and the matter link follows it. Leave empty to keep the folders of finished matters where they are.'))
                ->maxLength(255)
                ->extraInputAttributes(['dir' => 'auto']),
            // Who reviews this assistant's reports: each Review Report / Confirm Report request goes to them on WhatsApp.
            Select::make('reportReviewers')
                ->label(__('Report reviewers (WhatsApp)'))
                ->relationship('reportReviewers', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText(__('Each report this assistant sends for review goes to these users on WhatsApp, to approve, reject or ask for changes there. They need a WhatsApp number on their profile; the first to answer decides.'))
                ->columnSpanFull(),
            // Who handles this assistant's matters with them: those matters theirs to open and work on, and told of them.
            Select::make('administrators')
                ->label(__('Administrators of their matters'))
                // Users of the "admin" role only — the role that sees its administered assistants' matters and no others.
                ->relationship('administrators', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'admin')))
                ->multiple()
                ->preload()
                ->searchable()
                ->helperText(__('These users open and work on this assistant\'s matters (as their own role allows), see this assistant\'s OneDrive folders and incentive on them and have them in their "My Matters", and are told of what happens on them: emails sent and received, requests, minutes, new matters and appointment reminders. Only users of the "admin" role are offered: that role sees its administered assistants\' matters and no others.'))
                ->columnSpanFull(),
        ]));
    }
}
