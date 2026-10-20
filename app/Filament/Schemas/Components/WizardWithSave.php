<?php

namespace App\Filament\Schemas\Components;

use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\Blade;

/**
 * A wizard with a Save button beside Next on every step (Record the meeting): saved without going on.
 * Laid out by the theme (.wk-wizard-save): Previous on one side, Save and Next on the other.
 */
class WizardWithSave extends Wizard
{
    protected string $saveMethod = 'save';

    /** The page's method Save calls. */
    public function saveMethod(string $method): static
    {
        $this->saveMethod = $method;

        return $this;
    }

    public function toEmbeddedHtml(): string
    {
        $save = Blade::render(
            '<div class="wk-wizard-save"><x-filament::button wire:click="{{ $method }}" color="gray" icon="heroicon-o-check">{{ $label }}</x-filament::button></div>',
            ['method' => $this->saveMethod, 'label' => __('Save')],
        );

        $footer = '<div x-cloak class="fi-sc-wizard-footer">';

        return str_replace($footer, $footer.$save, parent::toEmbeddedHtml());
    }
}
