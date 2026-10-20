<?php

namespace App\Services\MMS\Letters\Blocks;

use App\Services\MMS\Letters\SignatureLayouts;

/**
 * A saved signature block (Communication → Templates → Signature blocks), dropped into
 * a letter from the editor's signature blocks menu (resources/js/rich-text-field.js).
 * Designed once, used in any template; editing it changes every template using it,
 * while letters already issued keep it as it was (SignatureLayouts::freeze()).
 *
 * Kept in the text as <div data-type="customBlock" data-id="saved_signature"
 * data-config="{layout_id, snapshot?}"></div>.
 */
class SavedSignatureBlock
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

    /**
     * The block as the signature block designer draws it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        $layout = SignatureLayouts::layout($config);

        return $layout ? SignatureLayouts::previewHtml($layout) : '<p>'.e(__('This signature block no longer exists.')).'</p>';
    }
}
