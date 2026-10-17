@php
    use App\Models\MatterEmail;

    $sent = $email->direction === MatterEmail::SENT;
    $rows = array_filter([
        __('Date') => $email->at?->format('d/m/Y H:i'),
        __('From') => $sent ? ($email->user?->name) : $email->from,
        __('To') => implode('، ', (array) $email->to),
        __('Cc') => implode('، ', (array) $email->cc),
        __('Matter known by') => $sent ? null : MatterEmail::matchedByLabel($email->matched_by),
        // What was kept of an email that came in.
        // Inside the matter's OneDrive folder — as it was when the email was saved.
        __('OneDrive folder') => filled(((array) $email->onedrive)['folder'] ?? null) ? ((array) $email->onedrive)['folder'] : null,
        __('PDF in OneDrive') => $email->oneDriveLabel('pdf'),
        __('EML in OneDrive') => $email->oneDriveLabel('eml'),
        __('Attachments in OneDrive') => $email->oneDriveLabel('attachments'),
    ], fn ($value) => filled($value));
@endphp

<div style="display: flex; flex-direction: column; gap: 1rem;" dir="auto">
    <dl style="display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1rem; margin: 0;">
        @foreach ($rows as $label => $value)
            <dt style="font-weight: 600; opacity: .75;">{{ $label }}</dt>
            <dd style="margin: 0; word-break: break-word;" dir="auto">{{ $value }}</dd>
        @endforeach
    </dl>

    @if ($files !== [])
        <div>
            <div style="font-weight: 600; opacity: .75; margin-bottom: .35rem;">{{ __('Files') }}</div>
            <ul style="list-style: disc; padding-inline-start: 1.25rem; margin: 0;">
                @foreach ($files as $index => $file)
                    <li>
                        <a href="{{ $file['url'] }}" target="_blank" rel="noopener" style="text-decoration: underline;" dir="auto">{{ $file['name'] }}</a>
                        @if ($index === 0)
                            <span style="opacity: .65;">({{ __('the email as PDF') }})</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif (! $sent)
        <p style="margin: 0; opacity: .7;">{{ __('No file was kept for this email.') }}</p>
    @endif
</div>
