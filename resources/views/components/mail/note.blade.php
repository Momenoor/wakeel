{{-- A warning note under a notification email's buttons. --}}
<table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="padding-top: 16px;">
            <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td bgcolor="#fff7ed" style="background-color: #fff7ed; border: 1px solid #fed7aa; border-radius: 4px; padding: 12px 16px; font-size: 12px; line-height: 1.6; color: #92400e; text-align: {{ \App\Support\MailStyle::start() }}; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ $slot }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
