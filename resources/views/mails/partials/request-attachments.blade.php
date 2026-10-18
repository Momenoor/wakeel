{{-- A matter request's attachments, as links. $matterRequest: the request. --}}
@if($matterRequest->attachments()->exists())
    <div style="margin-top: 20px;">
        <p><strong>{{ __('Attachments') }}:</strong></p>
        <ul style="font-size: 14px; color: #1B3A5C; padding-inline-start: 20px;">
            @foreach($matterRequest->attachments as $attachment)
                <li>
                    <a href="{{ $attachment->url() }}"
                       style="color: #1B3A5C; text-decoration: underline;">
                        {{ $attachment->name }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
