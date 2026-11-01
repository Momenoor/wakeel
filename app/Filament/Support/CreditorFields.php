<?php

namespace App\Filament\Support;

use App\Models\Matter;
use App\Models\MatterCreditor;
use App\Services\MMS\MatterCreditors;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Someone's addresses given to a creditor of a matter — a new one or one it has; as the creditor's own, or as its
 * lawyer's or agent's — from an email that came in (Emails tab) or a contact (Contacts tab). Their emails that came
 * in are moved to the creditor's folder then.
 */
final class CreditorFields
{
    /**
     * @return list<Component>
     */
    public static function schema(Matter $matter, string $name, string $address = ''): array
    {
        // Known as a creditor on another matter already: offered to copy from there.
        $known = MatterCreditors::knownElsewhere($matter, $address);

        return [
            Select::make('creditor_id')
                ->label(__('Creditor'))
                ->options(fn (): array => $matter->creditors()->orderBy('number')->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('A new creditor'))
                ->searchable()
                ->live(),
            Select::make('copy_from')
                ->label(__('The same creditor on another matter'))
                ->helperText(__('Its name, licence or ID, phones and agents are copied from there; its claim here starts empty.'))
                ->placeholder(__('None — a new one'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => MatterCreditors::elsewhere($matter, $search))
                ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? MatterCreditor::find($value)?->name : null)
                ->default($known?->getKey())
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($source = filled($state) ? MatterCreditor::find($state) : null) {
                        $set('name', $source->name);
                        $set('id_number', $source->id_number);
                    }
                })
                ->hidden(fn (Get $get): bool => filled($get('creditor_id'))),
            TextInput::make('name')
                ->label(__("Creditor's name"))
                ->default($known?->name ?? $name)
                ->required()
                ->maxLength(255)
                ->hidden(fn (Get $get): bool => filled($get('creditor_id'))),
            TextInput::make('id_number')
                ->label(__('Trade licence or ID number'))
                ->helperText(__('The same number twice in a matter is one creditor twice: a claim with it already is said.'))
                ->default($known?->id_number)
                ->maxLength(100)
                ->rule(fn (): Unique => Rule::unique('matter_creditors', 'id_number')->where('matter_id', $matter->getKey()))
                ->validationMessages(['unique' => __('A creditor of this matter has this licence or ID number already — choose it above instead.')])
                ->hidden(fn (Get $get): bool => filled($get('creditor_id'))),
            Toggle::make('agent')
                ->label(__("They are the creditor's lawyer or agent"))
                ->live(),
            TextInput::make('agent_name')
                ->label(__("Agent's name"))
                ->default($name)
                ->maxLength(255)
                ->visible(fn (Get $get): bool => (bool) $get('agent')),
        ];
    }

    /**
     * The addresses given to the creditor chosen, and the emails come in from them moved to its folder. The creditor,
     * and how many emails moved.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $emails
     * @return array{0: MatterCreditor, 1: int}
     */
    public static function save(Matter $matter, array $data, array $emails): array
    {
        $creditorId = filled($data['creditor_id'] ?? null) ? (int) $data['creditor_id'] : null;

        // From another matter: made here with what it has there, then given these addresses.
        if ($creditorId === null && filled($data['copy_from'] ?? null) && ($source = MatterCreditor::find($data['copy_from']))) {
            $copied = app(MatterCreditors::class)->copy($matter, $source);
            $copied->fill(['name' => trim((string) ($data['name'] ?? '')) ?: $copied->name, 'id_number' => trim((string) ($data['id_number'] ?? '')) ?: $copied->id_number])->save();
            $creditorId = (int) $copied->getKey();
        }

        foreach ($emails as $email) {
            $creditor = MatterCreditor::assign($matter, $creditorId, $data['name'] ?? null, $email, (bool) ($data['agent'] ?? false), $data['agent_name'] ?? null);
            if ($creditorId === null && filled($data['id_number'] ?? null)) {
                $creditor->fill(['id_number' => trim((string) $data['id_number'])])->save();
            }
            // The others to the same one.
            $creditorId = (int) $creditor->getKey();
        }

        return [$creditor ?? $matter->creditors()->findOrFail($creditorId), app(MatterCreditors::class)->refile($matter, $emails)];
    }
}
