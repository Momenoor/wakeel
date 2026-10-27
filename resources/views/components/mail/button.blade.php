{{--
    A button in <x-mail.actions> that classic Outlook draws too: the colour
    on the table cell (bgcolor), the padding as mso-padding-alt there, since
    Outlook ignores a link's padding and background.
--}}
@props(['href', 'color' => '#1B3A5C'])
<td style="padding: 4px 6px;">
    <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" bgcolor="{{ $color }}" style="background-color: {{ $color }}; border-radius: 6px; mso-padding-alt: 12px 28px;">
                <a href="{{ $href }}" target="_blank" style="display: inline-block; padding: 12px 28px; font-size: 15px; font-weight: bold; line-height: 20px; mso-line-height-rule: exactly; color: #ffffff; text-decoration: none; border-radius: 6px; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ $slot }}</a>
            </td>
        </tr>
    </table>
</td>
