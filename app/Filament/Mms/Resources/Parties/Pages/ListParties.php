<?php

namespace App\Filament\Mms\Resources\Parties\Pages;

use App\Filament\Mms\Resources\Parties\PartyResource;
use App\Models\Party;
use App\Support\ScreenPermissions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListParties extends ListRecords
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
