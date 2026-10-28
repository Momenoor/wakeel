<?php

namespace App\Filament\Mms\Resources\HrListItems\Schemas;

use App\Filament\Support\EnglishNameField;
use App\Models\HrListItem;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * An entry of the office's lists (a designation) in Arabic and English — on its page, and as a
 * dropdown on an employee's profile that adds one not yet in the list (select()).
 */
class HrListItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Hidden::make('kind')->default(HrListItem::DESIGNATION),
            ...self::names(),
        ]);
    }

    /** @return list<TextInput> */
    public static function names(): array
    {
        return [
            // The English put in from the Arabic by Claude, when set.
            EnglishNameField::source(TextInput::make('name_ar')->label(__('Name in Arabic'))->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']), 'name_en'),
            EnglishNameField::make(TextInput::make('name_en')->label(__('Name in English'))->required()->maxLength(255), 'name_ar'),
        ];
    }

    /** A dropdown of one of the lists, adding to it what is not there yet. */
    public static function select(string $name, string $kind, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(fn (): array => HrListItem::options($kind))
            ->searchable()
            ->createOptionForm(self::names())
            ->createOptionUsing(fn (array $data): int => (int) HrListItem::create([...$data, 'kind' => $kind])->getKey());
    }
}
