{{-- A shaded box of label / value rows (<x-mail.row>) in a notification email. --}}
<table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="padding: 4px 0 20px;">
            <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td bgcolor="#f0f5fb" style="background-color: #f0f5fb; border-{{ \App\Support\MailStyle::start() }}: 4px solid #1B3A5C; border-radius: 4px; padding: 14px 20px;">
                        <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0">
                            {{ $slot }}
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
