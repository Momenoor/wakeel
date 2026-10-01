{{-- Owner statement PDF (mPDF) — see App\Services\PMS\OwnerStatement. --}}
@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $tenant = fn ($lease) => $lease?->primaryTenant()?->party?->name ?? '—';
    $units = fn ($lease) => $lease?->units
        ->groupBy(fn ($unit) => $unit->property?->name)
        ->map(fn ($list, $building) => trim($building.' — '.$list->pluck('unit_number')->implode(', '), ' —'))
        ->implode(' | ') ?: '—';
    $period = match (true) {
        $from && $until => $from->format('d/m/Y').' – '.$until->format('d/m/Y'),
        (bool) $from => __('From').' '.$from->format('d/m/Y'),
        (bool) $until => __('Until').' '.$until->format('d/m/Y'),
        default => __('All dates'),
    };
    $align = $rtl ? 'left' : 'right';
@endphp
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<style>
    body { font-family: sans-serif; font-size: 9.5pt; color: #111827; }
    h1 { font-size: 16pt; margin: 0; }
    h2 { font-size: 11pt; margin: 18px 0 6px; color: #1f2937; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #f3f4f6; font-weight: bold; text-align: start; padding: 5px 6px; border: 1px solid #d1d5db; font-size: 8.5pt; }
    table.data td { padding: 4px 6px; border: 1px solid #e5e7eb; vertical-align: top; }
    table.data tfoot td { font-weight: bold; background: #f9fafb; }
    .num { text-align: {{ $align }}; white-space: nowrap; }
    .muted { color: #6b7280; }
    .summary td { padding: 6px 10px; border: 1px solid #d1d5db; }
    .summary .label { color: #4b5563; }
    .summary .value { font-weight: bold; font-size: 11pt; text-align: {{ $align }}; }
</style>
</head>
<body>
    <table width="100%" style="border-bottom: 2px solid #1f2937; padding-bottom: 8px;">
        <tr>
            <td style="vertical-align: middle;">
                <h1>{{ __('Owner statement') }}</h1>
                <div style="font-size: 12pt; margin-top: 4px;"><b>{{ $group->name }}</b></div>
                @if ($group->trn)
                    <div class="muted">{{ __('TRN') }} {{ $group->trn }}</div>
                @endif
                <div class="muted">{{ __('Period') }}: {{ $period }}</div>
            </td>
            <td style="vertical-align: middle; text-align: {{ $align }};">
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 55px; max-width: 180px;" /><br>
                @endif
                <span class="muted">{{ $company }}</span><br>
                <span class="muted" style="font-size: 8pt;">{{ __('Issued') }} {{ now()->format('d/m/Y') }}</span>
            </td>
        </tr>
    </table>

    @if ($group->properties->isNotEmpty())
        <p class="muted" style="margin-top: 8px;">{{ __('Buildings') }}: {{ $group->properties->pluck('name')->implode(', ') }}</p>
    @endif

    <h2>{{ __('Summary') }}</h2>
    <table class="summary" width="100%" style="border-collapse: collapse;">
        <tr>
            <td class="label">{{ __('Rent due in period') }}</td><td class="value">{{ $money($summary['due']) }}</td>
            <td class="label">{{ __('Collected in period') }}</td><td class="value">{{ $money($summary['collected']) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('VAT on rent due') }}</td><td class="value">{{ $money($summary['vat']) }}</td>
            <td class="label">{{ __('Outstanding') }}</td><td class="value" style="color: #b91c1c;">{{ $money($summary['outstanding']) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Deposits held') }}</td><td class="value">{{ $money($summary['deposits']) }}</td>
            <td></td><td class="value muted" style="font-size: 8pt; font-weight: normal;">{{ \App\Support\Currency::pdfSymbol() }}</td>
        </tr>
    </table>

    <h2>{{ __('Rent due') }}</h2>
    @if ($installments->isEmpty())
        <p class="muted">{{ __('No rent fell due in this period.') }}</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('Due date') }}</th>
                    <th>{{ __('Tenant') }}</th>
                    <th>{{ __('Units') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                    <th class="num">{{ __('Paid amount') }}</th>
                    <th class="num">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($installments as $installment)
                    <tr>
                        <td>{{ $installment->due_date?->format('d/m/Y') }}</td>
                        <td>{{ $tenant($installment->lease) }}</td>
                        <td>{{ $units($installment->lease) }}</td>
                        <td class="num">{{ $money($installment->total_due_amount) }}</td>
                        <td class="num">{{ $money($installment->paid_amount) }}</td>
                        <td class="num">{{ $money($installment->balance_due) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">{{ __('Total') }}</td>
                    <td class="num">{{ $money($installments->sum('total_due_amount')) }}</td>
                    <td class="num">{{ $money($installments->sum('paid_amount')) }}</td>
                    <td class="num">{{ $money($installments->sum('balance_due')) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <h2>{{ __('Payments received') }}</h2>
    @if ($payments->isEmpty())
        <p class="muted">{{ __('No payments were received in this period.') }}</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('Paid on') }}</th>
                    <th>{{ __('Tenant') }}</th>
                    <th>{{ __('Units') }}</th>
                    <th>{{ __('Method') }}</th>
                    <th>{{ __('Reference') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_date?->format('d/m/Y') }}</td>
                        <td>{{ $tenant($payment->installment?->lease) }}</td>
                        <td>{{ $units($payment->installment?->lease) }}</td>
                        <td>{{ $payment->payment_method?->getLabel() ?? '—' }}</td>
                        <td>{{ $payment->transaction_reference ?: '—' }}</td>
                        <td class="num">{{ $money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="num">{{ $money($payments->sum('amount')) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
</body>
</html>
