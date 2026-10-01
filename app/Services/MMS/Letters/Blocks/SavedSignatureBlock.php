<?php

namespace App\Services\MMS\Letters\Blocks;

use App\Models\SignatureLayout;
use App\Services\MMS\Letters\SignatureLayouts;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;

/**
 * A saved signature block (Communication → Signature blocks), dropped into
 * a letter from the editor's blocks menu. Designed once, used in any
 * template; editing it changes every template using it, while letters
 * already issued keep it as it was (SignatureLayouts::freeze()).
 */
class SavedSignatureBlock extends RichContentCustomBlock
{
    public const ID = 'saved_signature';

    public static function getId(): string
    {
        return self::ID;
    }

    public static function getLabel(): string
    {
        return __('Saved signature block');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription(__('Signature blocks are designed under Communication → Signature blocks.'))
            ->fillForm(fn (array $arguments): array => ['layout_id' => $arguments['config']['layout_id'] ?? SignatureLayout::query()->value('id')])
            ->schema([
                Select::make('layout_id')
                    ->label(__('Signature block'))
                    ->options(fn () => SignatureLayout::query()->orderBy('name')->pluck('name', 'id'))
                    ->required(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $name = SignatureLayout::find($config['layout_id'] ?? null)?->name;

        return $name ? self::getLabel().': '.$name : self::getLabel();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        $layout = SignatureLayouts::layout($config);

        return $layout ? SignatureLayouts::previewHtml($layout) : '<p>'.e(__('This signature block no longer exists.')).'</p>';
    }
}
