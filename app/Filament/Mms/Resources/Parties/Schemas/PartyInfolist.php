<?php

namespace App\Filament\Mms\Resources\Parties\Schemas;

use App\Filament\Mms\Resources\Parties\PartyResource;
use App\Models\ExpertiseArea;
use App\Models\Party;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

/**
 * A party as it is edited (PartyForm): identity and contact side by side, then their roles, an
 * assistant's own settings, the notes, and when the record was made.
 */
class PartyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                self::identity(),
                self::contact(),
                self::roles(),
                self::assistant(),
                self::notes(),
                self::record(),
            ]);
    }

    private static function identity(): Section
    {
        return Section::make(__('Identity'))
            ->icon('heroicon-o-user')
            ->columnSpan(1)
            ->schema([
                TextEntry::make('name')
                    ->hiddenLabel()
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),
                TextEntry::make('name_en')
                    ->label(__('Name in English'))
                    ->extraAttributes(['dir' => 'ltr'])
                    ->visible(fn (?Party $record): bool => filled($record?->name_en)),
                TextEntry::make('legal_name')
                    ->label(__('Legal name'))
                    ->visible(fn (?Party $record): bool => filled($record?->legal_name)),
                TextEntry::make('parent.name')
                    ->label(__('Belongs to'))
                    ->icon('heroicon-o-building-office')
                    ->url(fn (?Party $record): ?string => $record?->parent_id ? PartyResource::getUrl('view', ['record' => $record->parent_id]) : null)
                    ->visible(fn (?Party $record): bool => filled($record?->parent_id)),
                IconEntry::make('black_list')
                    ->label(__('Is Blacklisted'))
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
            ]);
    }

    private static function contact(): Section
    {
        return Section::make(__('Contact'))
            ->icon('heroicon-o-phone')
            ->columnSpan(1)
            ->columns(2)
            ->schema([
                TextEntry::make('phone')
                    ->label(__('Phone'))
                    ->icon('heroicon-o-phone')
                    ->placeholder('—')
                    ->badge()
                    ->listWithLineBreaks()
                    ->copyable(),
                TextEntry::make('fax')
                    ->label(__('Fax'))
                    ->icon('heroicon-o-printer')
                    ->placeholder('—'),
                TextEntry::make('email')
                    ->label(__('Email'))
                    ->icon('heroicon-o-envelope')
                    ->placeholder('—')
                    ->badge()
                    ->listWithLineBreaks()
                    ->copyable()
                    ->columnSpanFull(),
                TextEntry::make('address')
                    ->label(__('Address'))
                    ->icon('heroicon-o-map-pin')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    private static function roles(): Section
    {
        // The model's accessor gives every party the same shape: role.role, role.type, role.field.
        return Section::make(__('Roles & Expertise'))
            ->icon('heroicon-o-briefcase')
            ->columnSpanFull()
            ->columns(3)
            ->schema([
                TextEntry::make('roles')
                    ->label(__('Roles'))
                    ->badge()
                    ->color('info')
                    ->state(fn (Party $record): array => self::labels((array) ($record->role['role'] ?? []), Party::roleOptions())),
                TextEntry::make('expert_types')
                    ->label(__('Expert Types'))
                    ->badge()
                    ->color('warning')
                    ->state(fn (Party $record): array => self::labels((array) ($record->role['type'] ?? []), Party::expertTypeOptions()))
                    ->placeholder('—')
                    ->visible(fn (?Party $record): bool => (bool) $record?->isExpert()),
                TextEntry::make('expertise_field')
                    ->label(__('Expertise Field'))
                    ->icon('heroicon-o-academic-cap')
                    ->state(fn (Party $record): ?string => ExpertiseArea::labelFor($record->role['field'] ?? null))
                    ->placeholder('—')
                    ->visible(fn (?Party $record): bool => (bool) $record?->isExpert()),
            ]);
    }

    /** An assistant's OneDrive folders and who reviews their reports. */
    private static function assistant(): Section
    {
        return Section::make(__('Assistant settings'))
            ->icon('heroicon-o-cloud')
            ->columnSpanFull()
            ->columns(2)
            ->visible(fn (?Party $record): bool => (bool) $record?->isAssistant())
            ->schema([
                TextEntry::make('onedrive_email')
                    ->label(__('OneDrive account (Microsoft 365 email)'))
                    ->icon('heroicon-o-envelope')
                    ->placeholder('—')
                    ->copyable()
                    ->columnSpanFull(),
                TextEntry::make('onedrive_path')
                    ->label(__('Folder for matters in their OneDrive'))
                    ->icon('heroicon-o-folder')
                    ->placeholder('—')
                    ->extraAttributes(['dir' => 'auto']),
                TextEntry::make('onedrive_finished_path')
                    ->label(__('Folder for finished matters in their OneDrive'))
                    ->icon('heroicon-o-folder-arrow-down')
                    ->placeholder('—')
                    ->extraAttributes(['dir' => 'auto']),
                TextEntry::make('reportReviewers.name')
                    ->label(__('Report reviewers (WhatsApp)'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->badge()
                    ->listWithLineBreaks()
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    private static function notes(): Section
    {
        return Section::make(__('Notes'))
            ->icon('heroicon-o-pencil-square')
            ->columnSpanFull()
            ->visible(fn (?Party $record): bool => filled($record?->extra))
            ->schema([
                TextEntry::make('extra')
                    ->hiddenLabel()
                    ->extraAttributes(['dir' => 'auto']),
            ]);
    }

    private static function record(): Section
    {
        return Section::make(__('Record'))
            ->icon('heroicon-o-information-circle')
            ->columnSpanFull()
            ->columns(3)
            ->collapsible()
            ->schema([
                TextEntry::make('old_id')
                    ->label(__('Legacy ID'))
                    ->numeric()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->icon('heroicon-o-calendar'),
                TextEntry::make('updated_at')
                    ->label(__('Last Updated'))
                    ->dateTime()
                    ->since()
                    ->icon('heroicon-o-clock'),
            ]);
    }

    /**
     * Each key as its label (a key with no label as itself, in its own translation).
     *
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $options
     * @return list<string>
     */
    private static function labels(array $keys, array $options): array
    {
        return array_values(array_map(fn ($key): string => (string) ($options[$key] ?? __(ucfirst((string) $key))), array_filter($keys)));
    }
}
