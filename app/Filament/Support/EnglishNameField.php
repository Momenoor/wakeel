<?php

namespace App\Filament\Support;

use App\Services\Translation\NameTranslator;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/**
 * A name in English beside one in Arabic, put into English by Claude (NameTranslator) when it is set: filled
 * in once the Arabic is typed and left, while the English is empty — and by its Translate button, again, at
 * any time. Corrected by hand as any field.
 */
class EnglishNameField
{
    /** The English field: a Translate button filling it from the field $arabic. */
    public static function make(TextInput $field, string $arabic): TextInput
    {
        return $field
            ->extraInputAttributes(['dir' => 'ltr'])
            ->suffixAction(
                Action::make('translate_'.$field->getName())
                    ->label(__('Translate'))
                    ->tooltip(__('Translate from Arabic'))
                    ->icon(Heroicon::Language)
                    ->visible(fn (): bool => NameTranslator::on())
                    ->action(fn (Get $get, Set $set) => self::fill($get, $set, $arabic, $field->getName(), true)),
            );
    }

    /** The Arabic field: once left, an empty English field $english is filled with its translation. */
    public static function source(TextInput $field, string $english): TextInput
    {
        $arabic = $field->getName();

        return $field
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set) use ($arabic, $english): void {
                if (NameTranslator::on() && blank($get($english))) {
                    self::fill($get, $set, $arabic, $english, false);
                }
            });
    }

    /** $loud: pressed — told when there is nothing to translate or Claude cannot be reached. */
    private static function fill(Get $get, Set $set, string $from, string $to, bool $loud): void
    {
        $name = (string) $get($from);

        if (! NameTranslator::arabic($name)) {
            if ($loud) {
                Notification::make()->warning()->title(__('There is no Arabic to translate.'))->send();
            }

            return;
        }

        try {
            $english = app(NameTranslator::class)->one($name);
        } catch (Throwable $e) {
            if ($loud) {
                Notification::make()->danger()->title(__('Claude could not be reached'))->body(Str::limit($e->getMessage(), 300))->send();
            }

            return;
        }

        if ($english !== null) {
            $set($to, $english);
        }
    }
}
