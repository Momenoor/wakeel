<?php

namespace App\Filament\Mms\Resources\Parties\Pages;

use App\Filament\Mms\Imports\PartyImporter;
use App\Filament\Mms\Resources\Parties\PartyResource;
use App\Models\Party;
use App\Services\MMS\ContactMap;
use App\Support\ScreenPermissions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListParties extends ListRecords
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // From a spreadsheet: several phones / emails a cell, roles, whom each belongs to.
            ImportAction::make()
                ->importer(PartyImporter::class)
                ->visible(fn (): bool => auth()->user()?->can('create', Party::class) ?? false),
            // The shared contact map (courts, banks, government bodies, companies): external entities with
            // their contacts under them (ContactMap). The file is read, then deleted.
            Action::make('importContactMap')
                ->label(__('Import contact map'))
                ->icon('heroicon-o-building-library')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('create', Party::class) ?? false)
                ->modalDescription(__('A contact map file (JSON): each body becomes an external entity, its people and offices its contacts. Imported again, nothing is doubled.'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('Contact map'))
                        ->disk('local')
                        ->directory('contact-maps')
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $path = (string) $data['file'];

                    try {
                        $made = app(ContactMap::class)->importFile(Storage::disk('local')->path($path));
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($path);
                    }

                    Notification::make()->success()
                        ->title(__('Contact map imported'))
                        ->body(__(':entities new external entities, :contacts new contacts.', $made))
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    /**
     * Each tab has its own permission (ScreenPermissions); a role sees only
     * the tabs it holds.
     */
    public function getTabs(): array
    {
        $permissions = [
            'all' => ScreenPermissions::PARTIES_ALL_TAB,
            'parties' => ScreenPermissions::PARTIES_PARTIES_TAB,
            'representatives' => ScreenPermissions::PARTIES_REPRESENTATIVES_TAB,
            'experts' => ScreenPermissions::PARTIES_EXPERTS_TAB,
            'employees' => ScreenPermissions::PARTIES_EMPLOYEES_TAB,
            'external' => ScreenPermissions::PARTIES_EXTERNAL_TAB,
        ];

        return array_filter(
            $this->allTabs(),
            fn (string $key): bool => ScreenPermissions::can($permissions[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, Tab>
     */
    private function allTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'parties' => Tab::make(__('Parties'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereJsonContains('role', [['role' => 'party']])),
            'representatives' => Tab::make(__('Representatives'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereJsonContains('role', [['role' => 'representative']])),
            'experts' => Tab::make(__('Experts'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereJsonContains('role', [['role' => 'expert']])),
            // withRole() rather than whereJsonContains(): the latter is MySQL-only
            // in practice, and this tab is the entry point to payroll, which has
            // to be coverable by a test.
            'employees' => Tab::make(__('Employees'))
                ->modifyQueryUsing($this->scopeToEmployees(...)),
            // Police, central bank …: written to from matters, with their own letter templates.
            'external' => Tab::make(__('External entities'))
                ->modifyQueryUsing(fn (Builder $query) => $query->withRole('external')),
        ];
    }

    /**
     * @param  Builder<Party>  $query
     */
    protected function scopeToEmployees(Builder $query): void
    {
        $query->withRole('employee');
    }
}
