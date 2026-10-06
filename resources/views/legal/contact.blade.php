{{-- The office's contact details (System Settings), on the legal pages. --}}
@php
    $email = \App\Support\CompanyContact::email();
    $phone = \App\Support\CompanyContact::phone();
    $whatsapp = \App\Support\CompanyContact::whatsapp();
    $digits = fn (string $number): string => preg_replace('/\D+/', '', $number);
@endphp
<p>
    <strong>{{ \App\Support\CompanyContact::name() }}</strong>
    @if (filled($phone))
        <br>{{ $arabic ? 'الهاتف' : 'Phone' }}: <a href="tel:+{{ $digits($phone) }}" dir="ltr">{{ $phone }}</a>
    @endif
    @if (filled($whatsapp))
        <br>{{ $arabic ? 'واتساب' : 'WhatsApp' }}: <a href="https://wa.me/{{ $digits($whatsapp) }}" dir="ltr">{{ $whatsapp }}</a>
    @endif
    @if (filled($email))
        <br>{{ $arabic ? 'البريد الإلكتروني' : 'Email' }}: <a href="mailto:{{ $email }}">{{ $email }}</a>
    @endif
</p>
