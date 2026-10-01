{{-- The instalments a quotation's lease would get: rent rows, then the VAT
     and the security deposit each on its own. Inline styles so it renders
     the same in the form, the view page and without the panel's theme. --}}
@php($total = array_sum(array_column($rows, 'amount')))
<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
        <thead>
            <tr style="border-bottom: 1px solid rgba(128,128,128,.35);">
                <th style="text-align: start; padding: 6px 8px; font-weight: 600;">#</th>
                <th style="text-align: start; padding: 6px 8px; font-weight: 600;">{{ __('Instalment') }}</th>
                <th style="text-align: start; padding: 6px 8px; font-weight: 600;">{{ __('Due Date') }}</th>
                <th style="text-align: end; padding: 6px 8px; font-weight: 600;">{{ \App\Support\Currency::label(__('Amount (AED)')) }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr style="border-bottom: 1px solid rgba(128,128,128,.15);">
                    <td style="padding: 6px 8px;">{{ $row['number'] }}</td>
                    <td style="padding: 6px 8px;">{{ $row['label'] }}</td>
                    <td style="padding: 6px 8px; white-space: nowrap;">{{ $row['due_date']->format('d/m/Y') }}</td>
                    <td style="padding: 6px 8px; text-align: end; font-variant-numeric: tabular-nums;">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="padding: 6px 8px; opacity: .7;">{{ __('Add a unit with its rent to see the instalments.') }}</td>
                </tr>
            @endforelse
        </tbody>
        @if ($rows !== [])
            <tfoot>
                <tr style="border-top: 1px solid rgba(128,128,128,.35); font-weight: 700;">
                    <td colspan="3" style="padding: 6px 8px;">{{ __('Total') }}</td>
                    <td style="padding: 6px 8px; text-align: end; font-variant-numeric: tabular-nums;">{{ number_format($total, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
