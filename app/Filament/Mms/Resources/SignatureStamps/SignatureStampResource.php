<?php

namespace App\Filament\Mms\Resources\SignatureStamps;

use App\Enums\SignatureStampKind;
use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Mms\Resources\SignatureStamps\Pages\CreateSignatureStamp;
use App\Filament\Mms\Resources\SignatureStamps\Pages\EditSignatureStamp;
use App\Filament\Mms\Resources\SignatureStamps\Pages\ListSignatureStamps;
use App\Models\SignatureStamp;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\ImageEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The signatures and stamps people put on PDFs (the Sign PDF page): everyone's own, and the office's
 * (shared) ones, which only those allowed may add or change. Each image is uploaded (a transparent PNG
 * looks best) or drawn on the screen, and kept on the private disk — the list shows only what the user may
 * use (SignatureStamp::scopeUsableBy()).
 */
class SignatureStampResource extends Resource
{
    protected static ?string $model = SignatureStamp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 4;

    /** The form's helper fields: how the image is given — not columns of the record. */
    public const IMAGE_FIELDS = ['image_source', 'upload', 'drawing'];

    public static function getModelLabel(): string
    {
        return __('Signature or stamp');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Signatures & stamps');
    }

    public static function getNavigationLabel(): string
    {
        return __('Signatures & stamps');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }

    /** Only what the user may use: their own, and the office's when allowed. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->usableBy(auth()->user())->with('user');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(100),
                    ToggleButtons::make('kind')
                        ->label(__('Kind'))
                        ->options(SignatureStampKind::options())
                        ->default(SignatureStampKind::SIGNATURE->value)
                        ->inline()
                        ->required()
                        ->live()
                        // A new kind starts at its own size; it can still be changed.
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('width_mm', SignatureStampKind::tryFrom((string) $state)?->defaultWidthMm() ?? 40)),
                    TextInput::make('width_mm')
                        ->label(__('Width on the page'))
                        ->helperText(__('How wide it is first put on a page; it can be resized there.'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(200)
                        ->step(0.5)
                        ->suffix(__('mm'))
                        ->default(SignatureStampKind::SIGNATURE->defaultWidthMm())
                        ->required(),
                    Toggle::make('is_shared')
                        ->label(__('The office\'s (shared)'))
                        ->helperText(__('Everyone allowed the office\'s signatures and stamps can put it on a PDF.'))
                        ->inline(false)
                        ->visible(fn (): bool => auth()->user()?->can('share', SignatureStamp::class) ?? false),
                ]),

            Section::make(__('Image'))
                ->columnSpanFull()
                ->schema([
                    ImageEntry::make('current_image')
                        ->label(__('Current image'))
                        ->state(fn (?SignatureStamp $record): ?string => $record?->imageUrl())
                        ->imageHeight(96)
                        ->visible(fn (?SignatureStamp $record): bool => $record !== null),
                    ToggleButtons::make('image_source')
                        ->label(fn (?SignatureStamp $record): string => $record ? __('Replace the image') : __('The image'))
                        ->options(fn (?SignatureStamp $record): array => array_filter([
                            'keep' => $record ? __('Keep it') : null,
                            'upload' => __('Upload a file'),
                            'draw' => __('Draw it'),
                        ]))
                        ->default(fn (?SignatureStamp $record): string => $record ? 'keep' : 'upload')
                        ->afterStateHydrated(fn (ToggleButtons $component, ?string $state, ?SignatureStamp $record) => $component->state($state ?? ($record ? 'keep' : 'upload')))
                        ->inline()
                        ->required()
                        ->live(),
                    FileUpload::make('upload')
                        ->label(__('File'))
                        ->helperText(__('PNG, JPG or WEBP, up to 2 MB. A PNG with a transparent background looks best on paper.'))
                        ->disk(fn (): string => SignatureStamp::disk())
                        ->directory(SignatureStamp::DIRECTORY)
                        ->visibility('private')
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->rule('extensions:png,jpg,jpeg,webp')
                        ->maxSize(SignatureStamp::MAX_KB)
                        // Under a name nobody chose: where it is says nothing of whose it is.
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::uuid().'.'.(strtolower($file->guessExtension() ?: $file->getClientOriginalExtension()) ?: 'png'))
                        ->preventFilePathTampering()
                        ->required(fn (Get $get): bool => $get('image_source') === 'upload')
                        ->visible(fn (Get $get): bool => $get('image_source') === 'upload'),
                    SignaturePad::make('drawing')
                        ->label(__('Draw it here'))
                        ->helperText(__('With a finger, a pen or the mouse.'))
                        ->required(fn (Get $get): bool => $get('image_source') === 'draw')
                        ->visible(fn (Get $get): bool => $get('image_source') === 'draw'),
                ]),
        ]);
    }

    /**
     * The form's data with its image turned into a file on the private disk: the uploaded one (already
     * there), or the drawing written as a PNG. With "keep", the record's image stays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withImage(array $data): array
    {
        $source = $data['image_source'] ?? null;

        if ($source === 'upload' && filled($data['upload'] ?? null)) {
            $data['path'] = is_array($data['upload']) ? (string) reset($data['upload']) : (string) $data['upload'];
            $data['disk'] = SignatureStamp::disk();
        } elseif ($source === 'draw' && ($png = SignaturePad::decode($data['drawing'] ?? null)) !== null) {
            $data['path'] = SignatureStamp::storeImage($png);
            $data['disk'] = SignatureStamp::disk();
        }

        // Only someone allowed may make one the office's, whatever the browser sent.
        if (! (auth()->user()?->can('share', SignatureStamp::class) ?? false)) {
            unset($data['is_shared']);
        }

        return collect($data)->except(self::IMAGE_FIELDS)->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('preview')
                    ->label(__('Preview'))
                    ->state(fn (SignatureStamp $record): string => $record->imageUrl())
                    ->imageHeight(48)
                    ->extraImgAttributes(['style' => 'object-fit: contain; max-width: 10rem;']),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('kind')
                    ->label(__('Kind'))
                    ->badge()
                    ->formatStateUsing(fn (?SignatureStampKind $state): ?string => $state?->label())
                    ->color(fn (?SignatureStampKind $state): string => $state?->color() ?? 'gray'),
                TextColumn::make('user.name')
                    ->label(__('Owner'))
                    ->placeholder('—'),
                IconColumn::make('is_shared')
                    ->label(__('Shared'))
                    ->boolean(),
                TextColumn::make('width_mm')
                    ->label(__('Width on the page'))
                    ->suffix(' '.__('mm'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('Kind'))
                    ->options(SignatureStampKind::options()),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No signatures or stamps yet'))
            ->emptyStateDescription(__('Upload or draw your signature, then put it on any PDF from the Sign PDF page.'))
            ->emptyStateIcon(Heroicon::OutlinedFingerPrint);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSignatureStamps::route('/'),
            'create' => CreateSignatureStamp::route('/create'),
            'edit' => EditSignatureStamp::route('/{record}/edit'),
        ];
    }
}
