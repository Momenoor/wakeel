<?php

namespace App\Livewire;

use App\Filament\Concerns\RefreshesMatterPage;
use App\Services\MMS\MatterHistory;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * A matter's History tab: everything done to it and to what is on it — newest first, who did it and when,
 * and what changed from what to what (MatterHistory). Each request keeps its own history too.
 */
class MatterHistoryTab extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, RefreshesMatterPage {
        RefreshesMatterPage::afterActionCalled insteadof InteractsWithActions;
    }
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $matterId;

    public function mount(int $matterId): void
    {
        $this->matterId = $matterId;
    }

    /** While it is read: a spinner where it will be. */
    public function placeholder(): View
    {
        return view('livewire.tab-loading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Activity::query()->where('matter_id', $this->matterId)->with(['causer', 'subject']))
            ->defaultSort(fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->description(fn (Activity $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->width('1%'),
                TextColumn::make('subject_type')
                    ->label(__('What'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => MatterHistory::kind($state))
                    ->width('1%'),
                TextColumn::make('description')
                    ->label(__('Details'))
                    ->state(fn (Activity $record): string => $this->described($record)['title'])
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Activity $record): ?HtmlString => ($changes = $this->described($record)['changes']) === []
                        ? null
                        : new HtmlString(collect($changes)->map(fn (string $line): string => e($line))->implode('<br>')))
                    ->wrap(),
                TextColumn::make('causer.name')
                    ->label(__('By'))
                    ->state(fn (Activity $record): string => (string) ($record->causer?->display_name ?? $record->causer?->name ?? __('System')))
                    ->width('1%'),
            ])
            ->filters([
                SelectFilter::make('subject_type')
                    ->label(__('What'))
                    ->multiple()
                    ->options(collect(MatterHistory::KINDS)->mapWithKeys(fn (string $label, string $class): array => [(new $class)->getMorphClass() => __($label)])->all()),
            ])
            ->emptyStateHeading(__('Nothing recorded yet'));
    }

    /** @var array<int, array{title: string, changes: list<string>}> each row read once a render */
    private array $described = [];

    /** @return array{title: string, changes: list<string>} */
    private function described(Activity $record): array
    {
        return $this->described[$record->getKey()] ??= MatterHistory::describe($record);
    }

    public function render(): View
    {
        return view('livewire.matter-history-tab');
    }
}
