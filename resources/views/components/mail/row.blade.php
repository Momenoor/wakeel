{{-- One label / value row of <x-mail.info>; the value is `value`, or the slot. --}}
@props(['label', 'value' => null])
<tr>
    <td valign="top" width="40%" style="padding: 6px 0; font-size: 14px; line-height: 1.5; color: #666666; text-align: {{ \App\Support\MailStyle::start() }}; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ $label }}:</td>
    <td valign="top" style="padding: 6px 0; font-size: 14px; line-height: 1.5; font-weight: bold; color: #1B3A5C; text-align: {{ \App\Support\MailStyle::start() }}; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ $value ?? $slot }}</td>
</tr>
