<?php

namespace App\Filament\Mms\Resources\Letterheads;

use App\Filament\Concerns\HasModuleGate;
use App\Filament\Mms\Clusters\Templates;
use App\Filament\Mms\Resources\Letterheads\Pages\CreateLetterhead;
use App\Filament\Mms\Resources\Letterheads\Pages\DesignLetterhead;
use App\Filament\Mms\Resources\Letterheads\Pages\EditLetterhead;
use App\Filament\Mms\Resources\Letterheads\Pages\ListLetterheads;
use App\Models\Letterhead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Letterheads: the page letters are printed on — uploaded letterhead
 * images, watermark, margins, signature and stamp — and, on the Design
 * page, the freely placed elements (reference and date, logo, text…).
 */
class LetterheadResource extends Resource
{
    use HasModuleGate;

    protected static ?string $model = Letterhead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 3;

    protected static ?string $cluster = Templates::class;

    public static function moduleGateKey(): string
    {
        return 'mms_communications';
    }

    public static function getModelLabel(): string
    {
        return __('Letterhead');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Letterheads');
    }

    public static function form(Schema $schema): Schema
    {
        $image = fn (string $name, string $label) => FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk(Letterhead::DISK)
            ->directory(Letterhead::DIRECTORY)
            ->maxSize(10240);

        return $schema->components([
            Section::make(__('Letterhead'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required(),
                    Select::make('orientation')
                        ->label(__('Orientation'))
                        ->options(['portrait' => __('Portrait'), 'landscape' => __('Landscape')])
                        ->default('portrait')
                        ->required(),
                    Toggle::make('is_default')->label(__('Default letterhead'))->inline(false),
                ]),

            Section::make(__('Letterhead images'))
                ->description(__('A scan or export of the printed letterhead, as a full A4 page (e.g. 2480 × 3508 px). It is printed behind the letter.'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    $image('first_page_background', __('First page')),
                    $image('other_pages_background', __('Following pages'))
                        ->helperText(__('Leave empty to use the first page\'s image on every page.')),
                ]),

            Section::make(__('Margins (mm) — first page'))
                ->description(__('Where the letter text flows — keep it clear of the letterhead\'s header and footer.'))
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('margin_top')->label(__('Top'))->numeric()->default(45)->required(),
                    TextInput::make('margin_bottom')->label(__('Bottom'))->numeric()->default(30)->required(),
                    TextInput::make('margin_right')->label(__('Right'))->numeric()->default(20)->required(),
                    TextInput::make('margin_left')->label(__('Left'))->numeric()->default(20)->required(),
                ]),

            // Pages after the first often need less room — no letterhead
            // header to clear. Empty: the first page's margin.
            Section::make(__('Margins (mm) — other pages'))
                ->description(__('Top and bottom from the second page on. Leave one empty to use the first page\'s. Right and left are the same on every page.'))
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('other_margin_top')->label(__('Top'))->numeric()->minValue(0)->placeholder(fn ($get) => $get('margin_top')),
                    TextInput::make('other_margin_bottom')->label(__('Bottom'))->numeric()->minValue(0)->placeholder(fn ($get) => $get('margin_bottom')),
                ]),

            Section::make(__('Watermark'))
                ->columns(3)
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    Select::make('watermark_type')
                        ->label(__('Watermark'))
                        ->options(['none' => __('None'), 'text' => __('Text'), 'image' => __('Image')])
                        ->default('none')
                        ->live()
                        ->required(),
                    TextInput::make('watermark_text')
                        ->label(__('Text'))
                        ->visible(fn (Get $get) => $get('watermark_type') === 'text'),
                    $image('watermark_image', __('Image'))
                        ->visible(fn (Get $get) => $get('watermark_type') === 'image'),
                    TextInput::make('watermark_opacity')
                        ->label(__('Opacity'))
                        ->numeric()
                        ->minValue(0.02)
                        ->maxValue(1)
                        ->step(0.01)
                        ->default(0.08)
                        ->helperText(__('0.05 – 0.15 is usual.'))
                        ->visible(fn (Get $get) => $get('watermark_type') !== 'none'),
                ]),

            Section::make(__('Signature and stamp'))
                ->description(__('Transparent PNGs work best. A letter shows them where it has {{signature}} and {{stamp}}.'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    $image('signature_image', __('Signature')),
                    $image('stamp_image', __('Stamp')),
                    // How tall each is drawn; the width follows the image.
                    TextInput::make('signature_height')
                        ->label(__('Signature height (mm)'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(150)
                        ->step(0.5)
                        ->default(45)
                        ->required(),
                    TextInput::make('stamp_height')
                        ->label(__('Stamp height (mm)'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(150)
                        ->step(0.5)
                        ->default(40)
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('first_page_background')
                    ->label('')
                    ->disk(Letterhead::DISK)
                    ->imageHeight(70),
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
                TextColumn::make('templates_count')->label(__('Templates'))->counts('templates'),
            ])
            ->recordActions([
                Action::make('design')
                    ->label(__('Design'))
                    ->icon('heroicon-o-paint-brush')
                    ->url(fn (Letterhead $record) => static::getUrl('design', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLetterheads::route('/'),
            'create' => CreateLetterhead::route('/create'),
            'edit' => EditLetterhead::route('/{record}/edit'),
            'design' => DesignLetterhead::route('/{record}/design'),
        ];
    }
}
