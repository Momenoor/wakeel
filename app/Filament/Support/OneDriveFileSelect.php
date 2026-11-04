<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Livewire\Attributes\Renderless;

/**
 * The OneDrive file picker's select: what it offers is read from OneDrive only when its list is opened — the
 * screen it is on draws with the listing kept from before (none, to start), and never waits for OneDrive.
 */
class OneDriveFileSelect extends Select
{
    /** @var ?Closure(): array<string, string> the files, read from OneDrive (or as kept for the day) */
    protected ?Closure $listing = null;

    /**
     * @param  Closure(): array<string, string>  $listing  value => label
     */
    public function listing(Closure $listing): static
    {
        $this->listing = $listing;

        return $this;
    }

    /**
     * The list opened: the files read now.
     *
     * @return array<array{'label': string, 'value': string}>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getOptionsForJs(): array
    {
        return $this->transformOptionsForJs((array) ($this->listing !== null ? $this->evaluate($this->listing) : $this->getOptions()));
    }
}
