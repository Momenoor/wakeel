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

            /* A4 landscape, the smallest margin a printer keeps; the page's own padding is for the screen only. */
            @page {
                size: A4 landscape;
                margin: 5mm;
            }

            body {
                font-size: 11px;
                padding: 0;
            }

            tr {
                page-break-inside: avoid;
            }

            /* A row holding a whole table (an assistant's matters) splits between pages, row by row of its own;
               the row before it (the assistant's title) goes with it, so a title never ends a page alone. */
            tr.print-split {
                page-break-inside: auto;
                break-inside: auto;
            }

            tr.print-keep-next {
                page-break-after: avoid;
                break-after: avoid;
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
