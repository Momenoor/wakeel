{{-- A letter by email (App\Mail\LetterEmail). Images are embedded here, where $message is available. --}}
@php
    $body = $letterHtml;
    foreach ($images as $token => $path) {
        $body = str_replace($token, $message->embed($path), $body);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin: 0; padding: 16px; background: #ffffff;">
    <div dir="{{ $rtl ? 'rtl' : 'ltr' }}" style="font-family: Tahoma, Arial, sans-serif; font-size: 15px; line-height: 1.7; color: #111827; text-align: {{ $rtl ? 'right' : 'left' }}; max-width: 760px;">
        {!! $body !!}
    </div>
</body>
</html>
