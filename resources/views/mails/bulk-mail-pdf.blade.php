{{-- resources/views/bulk-mail/pdf.blade.php --}}
    <!DOCTYPE html>
<html lang="{{ $isRtl ? 'ar' : 'en' }}" dir="ltr">
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial Unicode MS', Arial, sans-serif;
            font-size: 10pt;
            color: #000;
            /* The page left to right; each Arabic block of the email right to left (EmailPdf::directed()). */
            direction: ltr;
        }

        /* ── Outlook brand block ── */
        .outlook-brand {
            padding-bottom: 10px;
            border-bottom: 1px solid #d0d0d0;
            margin-bottom: 10px;
        }

        .outlook-brand table {
            border-collapse: collapse;
        }

        .outlook-brand td {
            vertical-align: middle;
        }

        .outlook-text {
            font-size: 16pt;
            font-weight: normal;
            color: #1a1a1a;
            padding- {{ $isRtl ? 'right' : 'left' }}: 8px;
        }

        /* ── Subject ── */
        .email-subject {
            font-size: 14pt;
            font-weight: bold;
            color: #000;
            padding: 8px 0 6px;
            border-bottom: 1px solid #d0d0d0;
            margin-bottom: 8px;
        }

        /* ── Meta table ── */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
            margin-bottom: 4px;
            direction: ltr; /* always LTR for From/To/Date fields */
        }

        .meta-table td {
            padding: 2px 0;
            vertical-align: top;
            line-height: 1.6;
        }

        .meta-table td.label {
            font-weight: bold;
            width: 50px;
            white-space: nowrap;
            padding-right: 10px;
        }

        /* ── Attachments ── */
        .attachments-block {
            margin-top: 8px;
            font-size: 9pt;
            color: #444;
            direction: ltr;
        }

        .att-names {
            font-size: 9pt;
            color: #000;
            margin-top: 2px;
        }

        /* ── Body ── */
        .body-gap {
            height: 16px;
        }

        .email-body {
            font-size: 10.5pt;
            line-height: 1.7;
            color: #000;
            /* mPDF handles RTL inside body automatically via autoArabic */
        }

        /* Arabic text inside body */
        .email-body p, .email-body div, .email-body span {
            font-family: 'Arial Unicode MS', Arial, sans-serif;
        }
    </style>
</head>
<body>

{{-- ── Brand: Outlook, or the office — its logo, else its name (Settings) ── --}}
<div class="outlook-brand" style="direction: ltr; text-align: left;">
    <table cellpadding="0" cellspacing="0" style="direction: ltr;">
        <tr>
            @if ($brand['logo'])
                <td style="vertical-align: middle;">
                    <img src="{{ $brand['logo'] }}" @if ($brand['office']) height="48" @else width="48" @endif alt="">
                </td>
            @endif
            @if ($brand['name'])
                <td style="vertical-align: middle; padding-left: 8px; font-size: 16pt; font-weight: normal; color: #1a1a1a;">
                    {{ $brand['name'] }}
                </td>
            @endif
        </tr>
    </table>
</div>

{{-- ── Subject ── --}}
<div class="email-subject" dir="{{ \App\Services\MMS\BulkMailService::containsArabic($subject) ? 'rtl' : 'ltr' }}" style="text-align: {{ \App\Services\MMS\BulkMailService::containsArabic($subject) ? 'right' : 'left' }};">{{ $subject }}</div>

{{-- ── Meta table (always LTR) ── --}}
<table class="meta-table">
    <tr>
        <td class="label">From</td>
        <td><strong>{{ $sender['name'] }}</strong> &lt;{{ $sender['address'] }}&gt;</td>
    </tr>
    <tr>
        <td class="label">Date</td>
        <td>{{ \Carbon\Carbon::parse($sentAt)->format('D d-m-Y g:i A') }}</td>
    </tr>
    <tr>
        <td class="label">To</td>
        <td>{{ $recipient->name }}
            &lt;{{ is_array($recipient->email)?implode('>; <',$recipient->email):$recipient->email }}&gt;
        </td>
    </tr>
    @if(!empty($cc))
        <tr>
            <td class="label">Cc</td>
            <td>
                @foreach($cc as $ccEmail)
                    {{ $ccEmail }}@if(!$loop->last)
                        ;
                    @endif
                @endforeach
            </td>
        </tr>
    @endif
    @if(!empty($bcc))
        <tr>
            <td class="label">Bcc</td>
            <td>{{ implode('; ', $bcc) }}</td>
        </tr>
    @endif
</table>

{{-- ── Attachments ── --}}
@if(!empty($attachments))
    <div class="attachments-block">
        <img src="{{ public_path('images/paperclip.png') }}" width="10" alt=""> {{ count($attachments) }} {{ \Illuminate\Support\Str::plural('attachment', count($attachments)) }}
        <div class="att-names">
            @foreach($attachments as $file)
                {{ $file['name'] }}@if(!$loop->last)
                    ;
                @endif
            @endforeach
        </div>
    </div>
@endif

<div class="body-gap"></div>

{{-- ── Email body (Arabic handled automatically by mPDF autoArabic) ── --}}
<div class="email-body" dir="ltr">
    {!! $html !!}
</div>

</body>
</html>
