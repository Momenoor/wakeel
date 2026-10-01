<?php

namespace App\Filament\Mms\Resources\SignatureLayouts;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\CreateSignatureLayout;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\DesignSignatureLayout;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\EditSignatureLayout;
use App\Filament\Mms\Resources\SignatureLayouts\Pages\ListSignatureLayouts;
use App\Models\SignatureLayout;
use App\Services\MMS\Letters\SignatureLayouts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Signature blocks: the signature, the stamp, other images and text lines
 * laid out once — over and under each other — and dropped into any letter
 * template from the editor's blocks menu.
 */
class SignatureLayoutResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = SignatureLayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 6;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    public static function getModelLabel(): string
    {
        return __('Signature block');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Signature blocks');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->placeholder(__('e.g. Expert Reda — signature and stamp'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('width')
                        ->label(__('Width (mm)'))
                        ->numeric()->minValue(20)->maxValue(190)
                        ->default(80)
                        ->required(),
                    TextInput::make('height')
                        ->label(__('Height (mm)'))
                        ->numeric()->minValue(10)->maxValue(150)
                        ->default(45)
                        ->required(),
                    Radio::make('align')
                        ->label(__('Place on the line'))
                        ->options(['right' => __('Right'), 'center' => __('Centre'), 'left' => __('Left')])
                        ->default('left')
                        ->inline()
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                TextColumn::make('preview')
                    ->label(__('Preview'))
                    ->state(fn (SignatureLayout $record) => new HtmlString('<div style="width: 14rem;">'.SignatureLayouts::previewHtml($record->snapshot()).'</div>'))
                    ->html(),
                TextColumn::make('size')
                    ->label(__('Size (mm)'))
                    ->state(fn (SignatureLayout $record) => rtrim(rtrim((string) $record->width, '0'), '.').' × '.rtrim(rtrim((string) $record->height, '0'), '.')),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->recordActions([
                Action::make('design')
                    ->label(__('Design'))
                    ->icon('heroicon-o-paint-brush')
                    ->url(fn (SignatureLayout $record) => static::getUrl('design', ['record' => $record])),
                ReplicateAction::make()
                    ->label(__('Copy'))
                    ->mutateRecordDataUsing(fn (array $data): array => [...$data, 'name' => $data['name'].' — '.__('copy')]),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSignatureLayouts::route('/'),
            'create' => CreateSignatureLayout::route('/create'),
            'edit' => EditSignatureLayout::route('/{record}/edit'),
            'design' => DesignSignatureLayout::route('/{record}/design'),
        ];
    }
}
