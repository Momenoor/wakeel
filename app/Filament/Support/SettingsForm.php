<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Support\Icons\Heroicon;

/**
 * The settings pages' form shell: the page's own form, and under it a
 * "Save Settings" button (Ctrl+S) that submits to the page's save method.
 */
class SettingsForm
{
    /**
     * @param  string  $method  the page method that saves
     */
    public static function saveAction(string $method = 'save'): Action
    {
        return Action::make($method)
            ->label(__('Save Settings'))
            ->submit($method)
            ->icon(Heroicon::Check)
            ->color('primary')
            ->keyBindings(['mod+s']);
    }

    /**
     * The page's `form` with its save actions below it.
     *
     * @param  array<Action>|null  $actions  defaults to the one Save Settings button
     */
    public static function make(?array $actions = null): Form
    {
        return Form::make([
            EmbeddedSchema::make('form'),
        ])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($actions ?? [self::saveAction()])
                    ->key('form-actions'),
            ]);
    }
}
