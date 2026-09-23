<?php

namespace App\Filament\Schemas;

use App\Support\Branding;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Shared by System Settings (MMS) and PMS Settings — lives outside both
 * modules' folders so pruning either one never removes it. Saved through
 * {@see Branding::save()}, never as ordinary settings keys.
 */
class BrandingSettingsSection
{
    public static function make(): Section
    {
        return Section::make(__('Branding'))
            ->description(__('Your company logo and the avatar shown for users without their own photo.'))
            ->icon(Heroicon::Photo)
            ->columns(2)
            ->schema([
                FileUpload::make(Branding::LOGO)
                    ->label(__('Company Logo'))
                    ->helperText(__('Shown in the panel header and in emails. Leave empty to use the default logo.'))
                    ->image()
                    ->disk('public')
                    ->directory(Branding::DIRECTORY)
                    ->visibility('public')
                    ->maxSize(2048),

                FileUpload::make(Branding::DEFAULT_AVATAR)
                    ->label(__('Default User Avatar'))
                    ->helperText(__('Used for every user who hasn\'t uploaded their own photo. Leave empty to show their initials.'))
                    ->image()
                    ->avatar()
                    ->disk('public')
                    ->directory(Branding::DIRECTORY)
                    ->visibility('public')
                    ->maxSize(2048),
            ]);
    }
}
