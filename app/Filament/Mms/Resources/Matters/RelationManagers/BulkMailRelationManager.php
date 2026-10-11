<?php

namespace App\Filament\Mms\Resources\Matters\RelationManagers;

use App\Filament\Concerns\HasRelationManagerPermission;
use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Models\BulkMailCampaign;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A matter's bulk mail campaigns — to its creditors, parties … — each
 * opened on its own page; a new one starts with the matter chosen.
 */
class BulkMailRelationManager extends RelationManager
{
    use HasRelationManagerPermission;

    protected static string $relationship = 'bulkMailCampaigns';

    public static function viewPermission(): string
    {
        return ScreenPermissions::MATTER_BULK_MAIL_TAB;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Bulk mail');
    }

    public static function getModelLabel(): string
    {
        return __('Bulk Mail Campaign');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Bulk mail');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (BulkMailCampaign $record): ?string => BulkMailCampaignResource::canView($record) ? BulkMailCampaignResource::getUrl('view', ['record' => $record]) : null)
            ->columns([
                TextColumn::make('name')
                    ->label(__('bulk_mail.fields.name'))
                    ->weight('bold')
                    ->description(fn (BulkMailCampaign $record): string => (string) $record->subject)
                    ->searchable(['name', 'subject'])
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('bulk_mail.fields.status'))
                    ->badge(),
                TextColumn::make('stats')
                    ->label(__('bulk_mail.fields.progress'))
                    ->state(fn (BulkMailCampaign $record): string => "{$record->sent_count} / {$record->total_recipients}".($record->failed_count ? ' ('.__('bulk_mail.fields.failed').": {$record->failed_count})" : '')),
                TextColumn::make('created_at')
                    ->label(__('bulk_mail.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('new')
                    ->label(__('New bulk email'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => BulkMailCampaignResource::canCreate())
                    ->url(fn (): string => BulkMailCampaignResource::getUrl('create', ['matter' => $this->getOwnerRecord()->getKey()])),
            ])
            ->emptyStateHeading(__('No bulk mail for this matter'))
            ->emptyStateDescription(__('Bulk emails sent about this matter — to its creditors or parties — appear here.'));
    }
}
