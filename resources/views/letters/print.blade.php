{{-- A letter or minutes printed at 100%, in any browser: its PDF's pages drawn as pictures of their real size (resources/js/letter-print.js). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #e5e7eb; }
        #letter-print { display: flex; flex-direction: column; align-items: center; gap: 8mm; padding: 8mm 0; }
        .letter-page { display: block; background: #fff; box-shadow: 0 1px 4px rgb(0 0 0 / 0.2); }
        #letter-print-status { font: 14px system-ui, sans-serif; color: #374151; padding: 24px; text-align: center; }
        @media print {
            html, body { background: #fff; }
            #letter-print { display: block; gap: 0; padding: 0; }
            .letter-page { box-shadow: none; break-after: page; page-break-after: always; }
            .letter-page:last-child { break-after: auto; page-break-after: auto; }
            #letter-print-status { display: none; }
        }
    </style>
    @vite('resources/js/letter-print.js')
</head>
<body>
    <div id="letter-print-status" data-failed="{{ __('The letter could not be prepared for printing. Open the PDF instead.') }}">{{ __('Preparing the letter for printing…') }}</div>
    <div id="letter-print" data-pdf="{{ $pdf }}"></div>
</body>
</html>
