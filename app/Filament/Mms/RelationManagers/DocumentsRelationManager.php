<?php

namespace App\Filament\Mms\RelationManagers;

use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The official documents of a record with no matter — an employee's (labour contract, Emirates ID, visa,
 * passport, personal picture, others), a company's (trade licence, establishment card, others): its attachments,
 * each of one of the kinds the record allows (attachmentCategories()), seen and changed by whoever its own rule
 * lets (allowsAttachment()).
 */
class DocumentsRelationManager extends AttachmentsRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documents');
    }

    public static function getModelLabel(): string
    {
        return __('Document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Documents');
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading(__('No documents yet'))
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
