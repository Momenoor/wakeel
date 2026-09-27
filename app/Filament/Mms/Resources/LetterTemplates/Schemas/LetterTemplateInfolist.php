<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Schemas;

use App\Models\LetterTemplate;
use App\Services\MMS\Letters\LetterComposer;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class LetterTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')->label(__('Name')),
                        TextEntry::make('category')->label(__('Category'))->badge(),
                        TextEntry::make('letterhead.name')->label(__('Letterhead'))->placeholder(__('The default letterhead')),
                        IconEntry::make('is_active')->label(__('Active'))->boolean(),
                        TextEntry::make('subject')->label(__('Subject'))->columnSpanFull(),
                        TextEntry::make('inputs')
                            ->label(__('Fields'))
                            ->state(fn (LetterTemplate $record) => collect($record->inputs ?? [])
                                ->map(fn ($input) => ($input['label'] ?? '').' — {{input.'.($input['key'] ?? '').'}}')
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Letter'))
                    ->columnSpanFull()
                    ->schema([
                        // The wording with its placeholders shown as {{…}},
                        // right to left for Arabic templates.
                        TextEntry::make('body')
                            ->hiddenLabel()
                            ->state(fn (LetterTemplate $record) => new HtmlString(
                                '<div dir="'.($record->locale === 'en' ? 'ltr' : 'rtl').'" class="fi-prose">'
                                .LetterComposer::normalizeMergeTags((string) $record->body)
                                .'</div>'
                            )),
                    ]),
            ]);
    }
}
