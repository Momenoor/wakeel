<?php

namespace App\Http\Controllers;

use App\Models\IncentiveCalculation;
use App\Services\MMS\IncentiveCalculatorService;

class IncentiveCalculationPrintController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(IncentiveCalculation $calculation)
    {
        // The whole office's payroll. The route carried nothing but `auth`, so
        // every signed-in user could read it; the policy ability existed and was
        // simply never consulted. An assistant wanting their own figures has
        // My Incentive and the per-assistant print.
        abort_unless(auth()->user()?->can('print', $calculation), 403);

        // The matters' lines, for the cards' totals only (each matter is in its assistants' rows); an amount
        // entered by hand is in its assistant's rows.
        $lines = $calculation->lines()
            ->where('is_adjustment', false)
            ->get(['id', 'base_amount', 'net_amount']);

        $assistantSummary = app(IncentiveCalculatorService::class)
            ->getAssistantSummary($calculation);

        return view('filament.pages.incentive.calculation-print', compact('calculation', 'lines', 'assistantSummary'));
    }
}
