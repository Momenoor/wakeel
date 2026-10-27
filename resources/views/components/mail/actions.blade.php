{{-- A centred row of <x-mail.button>s, the first on the side text starts on. --}}
<table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="padding: 24px 0 12px;">
            <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" align="center" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    {{ $slot }}
                </tr>
            </table>
        </td>
    </tr>
</table>
