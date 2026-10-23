<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Quotation') }} — {{ $quotation->party->name }}</title>

    {{-- Inline, self-contained styles for the same reason every other print
         view in this app is: no CDN dependency on the print path. --}}
    <style>
        @page {
            size: A4;
            margin: 14mm 12mm;
        }

        * { box-sizing: border-box; }

        @include('filament.partials.print-document-base-styles')
        th {
            text-align: start;
            font-size: 9.5pt;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border-bottom: 1.5px solid #111;
            padding: 6px 4px;
        }

        td {
            padding: 5px 4px;
            border-bottom: 1px solid #e5e5e5;
        }

        .amount {
            text-align: end;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        th.amount { text-align: end; }

        tfoot td {
            border-top: 2px solid #111;
            border-bottom: none;
            font-weight: 700;
            padding-top: 8px;
        }

        .schedule {
            font-size: 10pt;
        }

        .details td:nth-child(odd) {
            color: #555;
            width: 22%;
        }

        @include('filament.partials.print-toolbar-styles')
    </style>
    {{ \App\Support\Currency::fontLink() }}
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    <header>
        <div>
            <div class="office">{{ config('app.name') }}</div>
            <div class="muted">{{ __('Property Management') }}</div>
        </div>
        <div class="meta">
            <h1>{{ __('Quotation') }}</h1>
            <div class="muted">{{ __('Prospect / Tenant') }}: {{ $quotation->party->name }}</div>
            <div class="muted">{{ __('Valid Until') }}: {{ $quotation->validity_date->format(\App\Support\Dates::dateFormat()) }}</div>
            <div class="muted">{{ __('Printed') }}: {{ now()->format(\App\Support\Dates::dateFormat()) }}</div>
        </div>
    </header>

    {{-- A quotation usually covers one building; each is listed with its lessor. --}}
    <table class="details">
        <tbody>
            @foreach ($quotation->units->pluck('property')->filter()->unique('id') as $property)
                <tr>
                    <td>{{ __('Building Name') }}</td>
                    <td>{{ $property->name }}</td>
                    <td>{{ __('Lessor Name') }}</td>
                    <td>{{ filled($lessor = $property->landlordName()) ? $lessor : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th>{{ __('Unit') }}</th>
                <th class="amount">{{ __('Offered Rent') }} ({{ \App\Support\Currency::symbol() }})</th>
                <th class="amount">{{ __('VAT') }} ({{ \App\Support\Currency::symbol() }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quotation->units as $unit)
                <tr>
                    <td>{{ $unit->property?->name }} — {{ $unit->unit_number }}</td>
                    <td class="amount">{{ number_format($unit->pivot->offered_rent, 2) }}</td>
                    <td class="amount">{{ number_format($unit->pivot->vat_amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('Base Rent') }}</td>
                <td class="amount" colspan="2">{{ number_format($quotation->base_rent, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('VAT') }}</td>
                <td class="amount" colspan="2">{{ number_format($quotation->vat_amount, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('Attestation Fee (Estimate)') }}</td>
                <td class="amount" colspan="2">{{ number_format($quotation->attestation_fee_estimate, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('Security Deposit') }}</td>
                <td class="amount" colspan="2">{{ number_format($quotation->security_deposit, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('Total Due') }}</td>
                <td class="amount" colspan="2">{{ number_format($quotation->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <h1 style="margin-top: 10px;">{{ __('Contract Details') }}</h1>
    <table class="details">
        <tbody>
            <tr>
                <td>{{ __('Start Date') }}</td>
                <td>{{ $period[0]->format(\App\Support\Dates::dateFormat()) }}</td>
                <td>{{ __('End Date') }}</td>
                <td>{{ $period[1]->format(\App\Support\Dates::dateFormat()) }}</td>
            </tr>
            <tr>
                <td>{{ __('Contract Type') }}</td>
                <td>{{ $quotation->contract_type?->getLabel() ?? '—' }}</td>
                <td>{{ __('Grace Period (Days)') }}</td>
                <td>{{ $quotation->grace_period_days }}</td>
            </tr>
            <tr>
                <td>{{ __('Payment Method') }}</td>
                <td>{{ $quotation->payment_method?->getLabel() ?? '—' }}</td>
                <td>{{ __('Number of Instalments') }}</td>
                <td>{{ $quotation->number_of_installments }}</td>
            </tr>
        </tbody>
    </table>

    <h1 style="margin-top: 10px;">{{ __('Expected Instalments') }}</h1>
    <table class="schedule">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('Instalment') }}</th>
                <th>{{ __('Due Date') }}</th>
                <th class="amount">{{ \App\Support\Currency::label(__('Amount (AED)')) }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($installments as $row)
                <tr>
                    <td>{{ $row['number'] }}</td>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['due_date']->format(\App\Support\Dates::dateFormat()) }}</td>
                    <td class="amount">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">{{ __('Total') }}</td>
                <td class="amount">{{ number_format(array_sum(array_column($installments, 'amount')), 2) }}</td>
            </tr>
        </tfoot>
    </table>
    <p class="muted">{{ __('The attestation fee is an estimate, paid separately.') }}</p>
</body>
</html>
