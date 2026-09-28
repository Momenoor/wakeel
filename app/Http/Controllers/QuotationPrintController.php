<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Services\PMS\QuotationService;
use Illuminate\Contracts\View\View;

class QuotationPrintController extends Controller
{
    public function __invoke(Quotation $quotation): View
    {
        abort_unless(auth()->user()?->can('view', $quotation), 403);

        return view('filament.pms.quotation-print', [
            'quotation' => $quotation->load(['party', 'units.property']),
            'installments' => app(QuotationService::class)->expectedInstallments($quotation),
            'period' => app(QuotationService::class)->period($quotation),
        ]);
    }
}
