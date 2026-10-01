{{-- Each assistant's incentive on one matter: share, then what was added
     and taken off, then what is paid. Inline styles so it needs nothing
     from the panel theme's build. --}}
@php
    $cell = 'padding: 6px 8px; white-space: nowrap;';
    $num = $cell.' text-align: end; font-variant-numeric: tabular-nums;';
    $money = fn (float $v): string => number_format($v, 2);
@endphp
<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
        <thead>
            <tr style="border-bottom: 1px solid rgba(128,128,128,.35);">
                <th style="{{ $cell }} text-align: start; font-weight: 600;">{{ __('Assistant') }}</th>
                <th style="{{ $num }} font-weight: 600;">{{ __('Share') }}</th>
                <th style="{{ $num }} font-weight: 600;">{{ __('Extra') }}</th>
                <th style="{{ $num }} font-weight: 600;">{{ __('Penalty') }}</th>
                <th style="{{ $num }} font-weight: 600;">{{ __('Fixed Deduction') }}</th>
                <th style="{{ $num }} font-weight: 700;">{{ __('Net') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr style="border-bottom: 1px solid rgba(128,128,128,.15);">
                    <td style="{{ $cell }}">
                        {{ $row['name'] }}
                        @if ($row['override'])
                            <span style="opacity: .7;">({{ __('override') }})</span>
                        @endif
                    </td>
                    <td style="{{ $num }}">{{ $money($row['share']) }}</td>
                    <td style="{{ $num }} color: rgb(22 163 74);">
                        {{ $row['extra'] > 0 ? '+'.$money($row['extra']) : '—' }}
                        @if ($row['extra_pct'] > 0)
                            <span style="opacity: .75;">({{ $row['extra_pct'] + 0 }}%)</span>
                        @endif
                    </td>
                    <td style="{{ $num }} color: rgb(220 38 38);">
                        {{ $row['penalty'] > 0 ? '−'.$money($row['penalty']) : '—' }}
                        @if ($row['penalty_pct'] > 0)
                            <span style="opacity: .75;">({{ $row['penalty_pct'] + 0 }}%)</span>
                        @endif
                    </td>
                    <td style="{{ $num }} color: rgb(220 38 38);">{{ $row['fixed'] > 0 ? '−'.$money($row['fixed']) : '—' }}</td>
                    <td style="{{ $num }} font-weight: 700;"><span dir="ltr" style="white-space:nowrap">{{ \App\Support\Currency::symbol() }} {{ $money($row['net']) }}</span></td>
                </tr>
            @endforeach
        </tbody>
        @if (count($rows) > 1)
            <tfoot>
                <tr style="border-top: 1px solid rgba(128,128,128,.35); font-weight: 700;">
                    <td style="{{ $cell }}">{{ __('Total') }}</td>
                    <td style="{{ $num }}">{{ $money(array_sum(array_column($rows, 'share'))) }}</td>
                    <td style="{{ $num }}">{{ $money(array_sum(array_column($rows, 'extra'))) }}</td>
                    <td style="{{ $num }}">{{ $money(array_sum(array_column($rows, 'penalty'))) }}</td>
                    <td style="{{ $num }}">{{ $money(array_sum(array_column($rows, 'fixed'))) }}</td>
                    <td style="{{ $num }}"><span dir="ltr" style="white-space:nowrap">{{ \App\Support\Currency::symbol() }} {{ $money(array_sum(array_column($rows, 'net'))) }}</span></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
