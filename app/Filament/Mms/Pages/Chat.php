<?php

namespace App\Filament\Mms\Pages;

use App\Livewire\ChatWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The full-page view of the chat widget (App\Livewire\ChatWidget) — same
 * component, same data, as the floating popup rendered on every other page.
 * This page just gives it its own nav entry and a two-pane layout with room
 * to breathe; all the actual chat logic lives in the Livewire component.
 */
class Chat extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 1;

    /** From a notification: ?conversation=…&message=… opens it at that message. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(ChatWidget::class, fn (): array => [
                'mode' => 'page',
                'conversation' => filter_var(request()->query('conversation'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
                'message' => filter_var(request()->query('message'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('Chat');
    }

    public static function getNavigationLabel(): string
    {
        return __('Chat');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    /**
     * Its own permission — the floating popup (MmsPanelProvider) and the
     * chat component follow it too.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:Chat') ?? false;
    }
}
