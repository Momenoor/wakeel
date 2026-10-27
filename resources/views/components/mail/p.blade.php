{{-- A paragraph of a notification email, its spacing on itself (classic Outlook's own paragraph spacing otherwise). --}}
<p dir="{{ \App\Support\MailStyle::dir() }}" style="margin: 0 0 16px; font-size: 14px; line-height: 1.7; color: #333333; text-align: {{ \App\Support\MailStyle::start() }}; font-family: {!! \App\Support\MailStyle::FONT !!};">{{ $slot }}</p>
