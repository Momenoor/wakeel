<?php

namespace App\Filament\Mms\Resources\PayrollRuns\Schemas;

use App\Models\Company;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Unique;

class PayrollRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Payroll Run'))
                    ->schema([
                        // Whose employees it pays — those the company sponsors — and whose details its Salary
                        // Authorization Form and journal voucher carry. None: the employees with no company.
                        Select::make('company_id')
                            ->label(__('Company'))
                            ->options(fn (): array => Company::options())
                            ->placeholder(__('Employees with no company'))
                            ->default(fn (): ?int => request()->integer('company') ?: null)
                            ->searchable()
                            ->live()
                            ->helperText(__('Its employees are those the company sponsors (the employee\'s "Sponsor"), and its forms carry the company\'s own details.'))
                            ->visible(fn (): bool => Company::anyExists()),
                        Select::make('period')
                            ->label(__('Period'))
                            // A closed list rather than a free-text month, because
                            // the period string is matched character-for-character
                            // against loan instalment due periods.
                            ->options(fn () => self::periodOptions())
                            ->default(now()->subMonth()->format('Y-m'))
                            // One run a month for each company (and one for the employees with none).
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => filled($get('company_id'))
                                ? $rule->where('company_id', (int) $get('company_id'))
                                : $rule->whereNull('company_id'))
                            ->required()
                            ->validationMessages([
                                'unique' => __('A payroll run already exists for this month.'),
                            ]),
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->maxLength(255)
                            ->placeholder(__('Optional label, e.g. "March salaries"')),
                        Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    /**
     * The last two years of months, newest first.
     *
     * @return array<string, string>
     */
    private static function periodOptions(): array
    {
        $options = [];
        $month = Carbon::now()->startOfMonth()->addMonth();

        for ($i = 0; $i < 24; $i++) {
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
            $month = $month->subMonth();
        }

        return $options;
    }
}
