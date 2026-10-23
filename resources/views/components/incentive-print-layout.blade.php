@props(['title'])
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @vite('resources/css/print.css')
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                font-size: 11px;
            }

            tr {
                page-break-inside: avoid;
            }
        }
    </style>
    {{ \App\Support\Currency::fontLink() }}
</head>
<body class="bg-white text-gray-800 font-sans text-sm p-10">

{{-- Print Button --}}
<div class="no-print flex justify-end mb-6">
    <button onclick="window.print()"
            class="flex items-center gap-2 bg-blue-900 hover:bg-blue-800 text-white text-sm font-medium px-5 py-2 rounded shadow">
        🖨 {{ __('Print') }}
    </button>
</div>

{{ $slot }}

{{-- Footer --}}
<div class="flex justify-between items-center border-t border-gray-200 pt-4 mt-8 text-xs text-gray-400">
    <span>{{ config('app.name') }}</span>
    <span>{{ $title }}</span>
    <span>{{ now()->format(\App\Support\Dates::dateFormat()) }}</span>
</div>

</body>
</html>
