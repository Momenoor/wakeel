{{-- Annual leave only: the days it takes off the vacation balance, and the balance before and after it. --}}
@php $days = fn (float $value): string => rtrim(rtrim(number_format($value, 1), '0'), '.'); @endphp
@if ($fromBalance > 0)
    <x-mail.row :label="__('Days from the vacation balance')" :value="$days($fromBalance)" />
    <x-mail.row :label="__('Balance before the leave')" :value="$days($balanceBefore)" />
    <x-mail.row :label="__('Balance after the leave')" :value="$days($balanceBefore - $fromBalance)" />
@elseif ($showBalance ?? false)
    <x-mail.row :label="__('Vacation Balance')" :value="$days($balanceBefore)" />
@endif
