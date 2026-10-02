{{-- Minutes sent for signature: to whom, how, and what came back signed. --}}
<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
        <thead>
            <tr style="text-align: start; border-bottom: 1px solid rgba(107,114,128,.3);">
                <th style="padding: .5rem; text-align: start;">{{ __('Name') }}</th>
                <th style="padding: .5rem; text-align: start;">{{ __('Sent by') }}</th>
                <th style="padding: .5rem; text-align: start;">{{ __('Sent') }}</th>
                <th style="padding: .5rem; text-align: start;">{{ __('Status') }}</th>
                <th style="padding: .5rem; text-align: start;">{{ __('Signed copy') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deliveries as $delivery)
                <tr style="border-bottom: 1px solid rgba(107,114,128,.15); vertical-align: top;">
                    <td style="padding: .5rem;">{{ $delivery->name }}</td>
                    <td style="padding: .5rem;">
                        {{ $delivery->channel === \App\Models\MinutesDelivery::WHATSAPP ? 'WhatsApp' : __('Email') }}
                        <div dir="ltr" style="opacity: .65; font-size: .8em; text-align: start;">{{ $delivery->address }}</div>
                    </td>
                    <td style="padding: .5rem;">{{ $delivery->sent_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td style="padding: .5rem;">
                        @switch($delivery->status)
                            @case(\App\Models\MinutesDelivery::SIGNED)
                                <x-filament::badge color="success">{{ __('Signed') }} {{ $delivery->signed_at?->format('d/m/Y H:i') }}</x-filament::badge>
                                @break
                            @case(\App\Models\MinutesDelivery::FAILED)
                                <x-filament::badge color="danger">{{ __('Failed') }}</x-filament::badge>
                                <div style="opacity: .7; font-size: .8em;">{{ $delivery->error }}</div>
                                @break
                            @default
                                <x-filament::badge color="warning">{{ __('Awaiting signature') }}</x-filament::badge>
                        @endswitch
                    </td>
                    <td style="padding: .5rem;">
                        @foreach ($delivery->signed_attachments ?? [] as $id)
                            @if ($attachment = $attachments->get($id))
                                <div><a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($attachment->path) }}" target="_blank" style="color: rgb(37,99,235); text-decoration: underline;">{{ $attachment->name }}</a></div>
                            @endif
                        @endforeach
                        @if ($delivery->onedrive_url)
                            <div><a href="{{ $delivery->onedrive_url }}" target="_blank" style="color: rgb(37,99,235); text-decoration: underline;">{{ __('In OneDrive') }}</a></div>
                        @elseif ($delivery->onedrive_error)
                            <div style="opacity: .7; font-size: .8em;">{{ __('Not in OneDrive:') }} {{ $delivery->onedrive_error }}</div>
                        @endif
                        @if ($delivery->channel === \App\Models\MinutesDelivery::EMAIL && $delivery->status !== \App\Models\MinutesDelivery::SIGNED)
                            <div style="opacity: .65; font-size: .8em;">{{ __('A copy signed by email is added by hand, under the matter\'s attachments.') }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
