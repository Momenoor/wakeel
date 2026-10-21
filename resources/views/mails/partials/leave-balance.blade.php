{{-- Annual leave only: the days it takes off the vacation balance, and the balance before and after it. --}}
@php $days = fn (float $value): string => rtrim(rtrim(number_format($value, 1), '0'), '.'); @endphp
@if ($fromBalance > 0)
    <tr>
        <td>{{ __('Days from the vacation balance') }}:</td>
        <td>{{ $days($fromBalance) }}</td>
    </tr>
    <tr>
        <td>{{ __('Balance before the leave') }}:</td>
        <td>{{ $days($balanceBefore) }}</td>
    </tr>
    <tr>
        <td>{{ __('Balance after the leave') }}:</td>
        <td>{{ $days($balanceBefore - $fromBalance) }}</td>
    </tr>
@elseif ($showBalance ?? false)
    <tr>
        <td>{{ __('Vacation Balance') }}:</td>
        <td>{{ $days($balanceBefore) }}</td>
    </tr>
@endif
