<?php

namespace App\Filament\Shared\Pages;

use App\Models\Setting;
use App\Support\LiveRefresh;
use App\Support\ScreenPermissions;
use BackedEnum;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Communication → Announcements & delivery: the banner shown to
 * everyone, who hears of a new leave request, and how often the chat looks
 * for new messages. Who is told of what, per role, is the Notifications page.
 */
class AnnouncementSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Megaphone;

    protected static ?int $navigationSort = 31;

    public static function getNavigationLabel(): string
    {
        return __('Announcements & delivery');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    protected static function permission(): string
    {
        return ScreenPermissions::SETTINGS_NOTIFICATIONS_TAB;
    }

    protected function defaults(): array
    {
        return [
            'leave_request_recipients' => (array) Setting::get('leave_request_recipients', []),
            LiveRefresh::SETTING => LiveRefresh::configured(),
            'system_announcement' => Setting::get('system_announcement', ''),
            'show_system_announcement' => Setting::get('show_system_announcement', false),
        ];
    }

    protected function groups(): array
    {
        return [
            LiveRefresh::SETTING => 'notifications',
            'system_announcement' => 'notifications',
            'show_system_announcement' => 'notifications',
        ];
    }

    protected function fields(): array
    {
        return [
            Section::make(__('System Announcement'))
                ->icon(Heroicon::Megaphone)
                ->schema([
                    Toggle::make('show_system_announcement')
                        ->label(__('Display System Announcement Banner'))
                        ->live(),

                    Textarea::make('system_announcement')
                        ->label(__('Announcement Message'))
                        ->rows(3)
                        ->visible(fn (Get $get): bool => (bool) $get('show_system_announcement')),
                ]),

            Section::make(__('Leave Requests'))
                ->icon(Heroicon::CalendarDays)
                ->schema([
                    // Who hears of a new leave request (email + bell).
                    TagsInput::make('leave_request_recipients')
                        ->label(__('Leave requests are sent to'))
                        ->placeholder(__('Add an email address'))
                        ->nestedRecursiveRules(['email'])
                        ->helperText(__('Empty: everyone who can approve leave requests.')),
                ]),

            Section::make(__('Chat'))
                ->icon(Heroicon::ChatBubbleLeftRight)
                ->schema([
                    // How often the chat checks for new messages (App\Support\LiveRefresh).
                    TextInput::make(LiveRefresh::SETTING)
                        ->label(__('Chat checks for new messages every'))
                        ->numeric()
                        ->integer()
                        ->minValue(LiveRefresh::MIN)
                        ->maxValue(LiveRefresh::MAX)
                        ->suffix(__('seconds'))
                        ->default(LiveRefresh::DEFAULT)
                        ->required()
                        ->helperText(fn (): string => __('Shorter: messages and online status sooner, but more requests to the server. If "Error while loading" notices appear, make it longer.')
                            .(filled(config('filament.broadcasting.echo')) ? ' '.__('With Pusher set up, messages arrive the moment they are sent and the chat does not check for them: this is used only without Pusher.') : '')),
                ]),
        ];
    }
}
