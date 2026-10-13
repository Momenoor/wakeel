<?php

namespace App\Filament\Mms\Resources\LetterTemplates\Pages;

use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Enums\LetterTemplateCategories;
use App\Services\MMS\Letters\WordTemplateImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListLetterTemplates extends ListRecords
{
    protected static string $resource = LetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importWord')
                ->label(__('Create from Word file'))
                ->icon('heroicon-o-document-arrow-up')
                ->visible(fn () => auth()->user()?->can('Create:LetterTemplate'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('Word file (.docx)'))
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->disk('local')
                        ->directory('letter-template-imports')
                        ->required(),
                    TextInput::make('name')->label(__('Name'))->required(),
                    Select::make('category')
                        ->label(__('Category'))
                        ->options(LetterTemplateCategories::class)
                        ->default(LetterTemplateCategories::LETTER)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = \Illuminate\Support\Facades\Storage::disk('local')->path($data['file']);
                    $template = app(WordTemplateImporter::class)->import($path, $data['name'], LetterTemplateCategories::from($data['category']));
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($data['file']);

                    return redirect(LetterTemplateResource::getUrl('edit', ['record' => $template]));
                }),
            CreateAction::make(),
        ];
    }
}
