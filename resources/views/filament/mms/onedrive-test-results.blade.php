{{-- The last OneDrive test folder run: one row per assistant. Inline styles
     so it needs nothing from the panel theme's build. --}}
@php($cell = 'padding: 6px 8px; vertical-align: top;')
<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
        <thead>
            <tr style="border-bottom: 1px solid rgba(128,128,128,.35);">
                <th style="{{ $cell }} text-align: start; font-weight: 600;">{{ __('Assistant') }}</th>
                <th style="{{ $cell }} text-align: start; font-weight: 600;">{{ __('OneDrive account') }}</th>
                <th style="{{ $cell }} text-align: start; font-weight: 600;">{{ __('Result') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr style="border-bottom: 1px solid rgba(128,128,128,.15);">
                    <td style="{{ $cell }} white-space: nowrap;">{{ $row['name'] }}</td>
                    <td style="{{ $cell }}" dir="ltr">{{ $row['email'] }}</td>
                    <td style="{{ $cell }} color: {{ $row['ok'] ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }};">
                        {{ $row['ok'] ? '✓' : '✗' }} {{ $row['message'] }}
                        @if ($row['url'])
                            — <a href="{{ $row['url'] }}" target="_blank" rel="noopener" style="text-decoration: underline;">{{ __('Open') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
