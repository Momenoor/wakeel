{{-- The email as it will go out (LettersRelationManager's Send by email). --}}
<div style="border: 1px solid rgb(229 231 235); border-radius: 0.5rem; overflow: hidden;">
    <div style="padding: 0.5rem 0.75rem; background: rgb(249 250 251); border-bottom: 1px solid rgb(229 231 235); font-size: 0.875rem; color: #374151;">
        <div><strong>{{ __('To') }}:</strong> <span dir="auto">{{ $to ?: '—' }}</span></div>
        @if (filled($cc))
            <div><strong>{{ __('CC') }}:</strong> <span dir="ltr">{{ $cc }}</span></div>
        @endif
        <div><strong>{{ __('Subject') }}:</strong> <span dir="auto">{{ $subject }}</span></div>
        @if ($attachments)
            <div><strong>{{ __('Attachments') }}:</strong> <span dir="auto">{{ implode(' · ', $attachments) }}</span></div>
        @endif
    </div>
    <div dir="{{ $rtl ? 'rtl' : 'ltr' }}" style="padding: 1rem; background: #ffffff; color: #111827; font-family: Tahoma, Arial, sans-serif; font-size: 15px; line-height: 1.7; text-align: {{ $rtl ? 'right' : 'left' }}; max-height: 28rem; overflow: auto;">
        {!! $html !!}
    </div>
</div>
