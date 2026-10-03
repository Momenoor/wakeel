<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\Chat\ChatMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A chat message with files: sent straight from the browser in one upload
 * (with its progress shown), not through Livewire's temporary uploads.
 */
class ChatMessageController extends Controller
{
    public function store(Request $request, ChatConversation $conversation, ChatMessenger $messenger): JsonResponse
    {
        abort_unless($conversation->participants()->whereKey($request->user()->getKey())->exists(), 403);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer'],
            'files' => ['required', 'array', 'min:1', 'max:'.ChatMessenger::MAX_FILES],
            'files.*' => ['file', 'max:'.ChatMessenger::MAX_KB],
            'voice' => ['nullable', 'boolean'],
        ], [], ['files.*' => __('file')]);

        $message = $messenger->send(
            $conversation,
            $request->user(),
            (string) ($data['body'] ?? ''),
            isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
            $request->file('files', []),
            $request->boolean('voice'),
        );

        return response()->json(['id' => $message?->getKey()]);
    }
}
