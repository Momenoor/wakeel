<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\MmsPanelProvider;
use App\Providers\Filament\PmsPanelProvider;

// From config('modules.*'), not env(): this file is read after the
// configuration is loaded, and once it is cached (System Updates runs
// `optimize`) env() no longer sees .env at all. Both default to true so a
// deployment with no MODULE_* flags in its .env (every existing one, until
// the installer's Modules step sets them) keeps registering both panels
// exactly as it always has.
return array_filter([
    AppServiceProvider::class,
    filter_var(config('modules.mms', true), FILTER_VALIDATE_BOOLEAN) ? MmsPanelProvider::class : null,
    filter_var(config('modules.pms', true), FILTER_VALIDATE_BOOLEAN) ? PmsPanelProvider::class : null,
]);
