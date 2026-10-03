<?php

namespace App\Filament\Mms\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

/**
 * The letter, email and WhatsApp templates and everything a letter is built
 * from (items, letterheads, signature blocks, fonts) behind one
 * "Templates" entry under Communication, which keeps that group to the
 * daily tools — Chat and bulk mail — plus this one.
 */
class Templates extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Templates');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Templates');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Communication');
    }
}
