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
    public static function schema(Matter $matter, string $name): array
    {
        return [
            Select::make('creditor_id')
                ->label(__('Creditor'))
                ->options(fn (): array => $matter->creditors()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('A new creditor'))
                ->searchable()
                ->live(),
            TextInput::make('name')
                ->label(__("Creditor's name"))
                ->default($name)
                ->required()
                ->maxLength(255)
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

        foreach ($emails as $email) {
            $creditor = MatterCreditor::assign($matter, $creditorId, $data['name'] ?? null, $email, (bool) ($data['agent'] ?? false), $data['agent_name'] ?? null);
            // The others to the same one.
            $creditorId = (int) $creditor->getKey();
        }

        return [$creditor ?? $matter->creditors()->findOrFail($creditorId), app(MatterCreditors::class)->refile($matter, $emails)];
    }
}
