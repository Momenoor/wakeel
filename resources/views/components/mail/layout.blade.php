{{--
    The frame of every notification email: header (logo, the app's name, a
    subtitle), body, footer. Built of tables with inline styles, bgcolor and
    a direction on each, so it reads the same in classic Outlook for Windows
    (which ignores a <div>'s background, max-width, and a parent's direction
    for a table's columns), in the new Outlook and Outlook on the web, and in
    Gmail (which drops a <style> block it cannot parse). Classic Outlook
    cannot load the web font: Tahoma, in both Outlooks.
--}}
@props(['title', 'subtitle' => null, 'color' => '#1B3A5C', 'width' => 600])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\MailStyle::dir() }}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <link rel="stylesheet" href="{{ asset('fonts/Boutros.css') }}">
    <title>{{ $title }}</title>
    <!--[if mso]>
    <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
    <style>* { font-family: Tahoma, Arial, sans-serif !important; }</style>
    <![endif]-->
    <style>
        * { font-family: {!! \App\Support\MailStyle::FONT !!}; }
        a { color: #1B3A5C; }
    </style>
</head>
<body dir="{{ \App\Support\MailStyle::dir() }}" style="margin: 0; padding: 0; background-color: #f4f4f7;" bgcolor="#f4f4f7">
<table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f4f7" style="background-color: #f4f4f7;">
    <tr>
        <td align="center" style="padding: 40px 12px;">
            <!--[if mso]><table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="{{ $width }}" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" dir="{{ \App\Support\MailStyle::dir() }}" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="max-width: {{ $width }}px; background-color: #ffffff; border-radius: 8px;">
                <tr>
                    <td align="center" bgcolor="{{ $color }}" style="background-color: {{ $color }}; padding: 30px 40px; border-radius: 8px 8px 0 0; text-align: center;">
                        <img src="{{ \App\Support\Branding::emailLogoUrl() }}" width="200" alt="{{ config('app.name') }}" style="display: block; margin: 0 auto 10px; width: 200px; max-width: 200px; height: auto; border: 0;">
                        <h1 style="margin: 0; color: #ffffff; font-size: 20px; line-height: 28px; mso-line-height-rule: exactly; font-weight: bold; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ config('app.name') }}</h1>
                        @if (filled($subtitle))
                            <p style="margin: 6px 0 0; color: #e6edf5; font-size: 13px; line-height: 20px; mso-line-height-rule: exactly; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ $subtitle }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td dir="{{ \App\Support\MailStyle::dir() }}" align="{{ \App\Support\MailStyle::start() }}" style="padding: 32px 40px; color: #333333; font-size: 14px; line-height: 1.7; text-align: {{ \App\Support\MailStyle::start() }}; font-family: {!! \App\Support\MailStyle::FONT !!};">
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td align="center" bgcolor="#f4f4f7" style="background-color: #f4f4f7; padding: 20px 40px; border-radius: 0 0 8px 8px; text-align: center; font-size: 12px; line-height: 1.6; color: #888888; font-family: {!! \App\Support\MailStyle::FONT !!};">
                        {{ config('app.name') }} · {{ now()->format('Y') }}<br>
                        {{ __('All rights reserved.') }}<br>
                        {{ __('This email was sent automatically. Do not reply.') }}
                    </td>
                </tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
