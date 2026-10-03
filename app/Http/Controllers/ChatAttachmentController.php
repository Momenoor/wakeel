<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file sent in a chat message — to those in the conversation only.
 * Shown in the browser (images, PDFs), or downloaded with ?download=1.
 */
class ChatAttachmentController extends Controller
{
    public function __invoke(Request $request, ChatMessage $message, int $index): StreamedResponse|BinaryFileResponse
    {
        abort_unless($message->conversation?->participants()->whereKey($request->user()->getKey())->exists(), 403);

        $file = $message->files()[$index] ?? null;
        abort_unless($file && Storage::disk(ChatMessage::DISK)->exists($file['path']), 404);

        $headers = ['Content-Type' => $file['mime'] ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'];

        // Only what a browser shows safely is shown; the rest downloads.
        $inline = ! $request->boolean('download')
            && (ChatMessage::isImage($file) && $file['mime'] !== 'image/svg+xml' || $file['mime'] === 'application/pdf');

        // Sound and video play in the page — sent as a file, so the player
        // can seek (Safari won't play without it).
        if (! $request->boolean('download') && (ChatMessage::isAudio($file) || ChatMessage::isVideo($file))) {
            return response()->file(Storage::disk(ChatMessage::DISK)->path($file['path']), $headers);
        }

        return $inline
            ? Storage::disk(ChatMessage::DISK)->response($file['path'], $file['name'], $headers)
            : Storage::disk(ChatMessage::DISK)->download($file['path'], $file['name'], $headers);
    }
}
