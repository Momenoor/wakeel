<?php

namespace App\Filament\Schemas;

use App\Support\Branding;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Shared by System Settings (MMS) and PMS Settings — lives outside both
 * modules' folders so pruning either one never removes it. Saved through
 * {@see Branding::save()}, never as ordinary settings keys. Every upload
 * has the image editor, for cropping before it's saved.
 */
class BrandingSettingsSection
{
    /** Crop presets for a logo: free, then common wide shapes. */
    private const LOGO_RATIOS = [null, '4:1', '3:1', '2:1', '1:1'];

    public static function make(): Section
    {
        return Section::make(__('Branding'))
            ->description(__('Your company logos, the browser tab icon, and the avatar shown for users without their own photo.'))
            ->icon(Heroicon::Photo)
            ->columns(2)
            ->schema([
                static::upload(Branding::LOGO)
                    ->label(__('Company Logo'))
                    ->helperText(__('Shown in the panel header and in emails. Leave empty to use the default logo.'))
                    ->imageEditorAspectRatios(self::LOGO_RATIOS),

                static::upload(Branding::LOGO_DARK)
                    ->label(__('Company Logo (Dark Mode)'))
                    ->helperText(__('Shown in the panel header in dark mode. Leave empty to use the logo above.'))
                    ->imageEditorAspectRatios(self::LOGO_RATIOS),

                static::upload(Branding::FAVICON)
                    ->label(__('Favicon'))
                    ->helperText(__('The browser tab icon. A square image, e.g. 64×64 PNG.'))
                    ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'])
                    ->imageEditorAspectRatios(['1:1'])
                    ->maxSize(512),

                static::upload(Branding::DEFAULT_AVATAR)
                    ->label(__('Default User Avatar'))
                    ->helperText(__('Used for every user who hasn\'t uploaded their own photo. Leave empty to show their initials.'))
                    ->avatar()
                    ->circleCropper(),
            ]);
    }

    private static function upload(string $key): FileUpload
    {
        return FileUpload::make($key)
            ->image()
            ->imageEditor()
            ->disk('public')
            ->directory(Branding::DIRECTORY)
            ->visibility('public')
            ->maxSize(2048);
    }
}
