<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Pages\Schemas\IntegrationsSettingsForm;
use App\Services\MMS\MatterReplyCollector;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Communication → Collecting emails: how the mailboxes are read for their emails — when they last
 * were (and undoing what was collected), how far back, and whose emails are never read. With the mail
 * senders and the system's email settings, all of the mailboxes in one place.
 */
class MailCollectingSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::InboxArrowDown;

    protected static ?int $navigationSort = 22;

    public static function getNavigationLabel(): string
    {
        return __('Collecting emails');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    protected static function permission(): string
    {
        return ScreenPermissions::SETTINGS_EMAIL_TAB;
    }

    protected function defaults(): array
    {
        return [
            MatterReplyCollector::LOOK_BACK => MatterReplyCollector::lookBackDays(),
            MatterReplyCollector::IGNORED => MatterReplyCollector::ignoredSenders(),
        ];
    }

    protected function groups(): array
    {
        return [
            MatterReplyCollector::LOOK_BACK => 'mail',
            MatterReplyCollector::IGNORED => 'mail',
        ];
    }

    protected function fields(): array
    {
        return [
            Section::make(__('Collecting emails'))
                ->description(__('Every mailbox set to be read — the shared ones and each user\'s own — is read every few minutes by the scheduled tasks (Settings → Integrations): replies and emails about a matter are filed on it.'))
                ->icon(Heroicon::InboxArrowDown)
                ->schema([
                    // Whether the scheduler really runs: the automatic email check is one of its tasks.
                    TextEntry::make('last_email_check')
                        ->label(__('Last automatic email check'))
                        ->state(fn (): string => MatterReplyCollector::lastRunSummary())
                        ->hintAction(IntegrationsSettingsForm::undoCollectedAction()),
                    TextInput::make(MatterReplyCollector::LOOK_BACK)
                        ->label(__('Read the inboxes back (days)'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(MatterReplyCollector::MAX_LOOK_BACK)
                        ->required()
                        ->helperText(__('How far back a mailbox is read the first time, for one matter, or after the checks stopped for a while. Each check still reads a little at a time — a long period is caught up over several checks, never in one long one.')),
                    TagsInput::make(MatterReplyCollector::IGNORED)
                        ->label(__('Never check emails from'))
                        ->placeholder(__('Add an email address, or @domain'))
                        // Several at once: typed with a comma, space or semicolon between, or a list pasted.
                        ->splitKeys(['Tab', ',', ' ', ';'])
                        ->nestedRecursiveRules(['regex:/^([^@\s]+)?@[^@\s]+\.[^@\s]+$/'])
                        ->helperText(__('Emails from these addresses (or a whole domain written as @domain) are not read: not kept, not notified, and left unread in the mailbox. Add several at once by separating them with a comma, space or semicolon, or by pasting a list.')),
                ]),
        ];
    }
}
