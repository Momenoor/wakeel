<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Maize\MsgraphMailer\Services\MicrosoftGraphClient;
use Microsoft\Kiota\Abstractions\Authentication\AccessTokenProvider;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A Microsoft 365 mailbox's emails sent whole (MIME), as written: their Message-ID — the one the system keeps, which
 * the replies name to be known as replies — and In-Reply-To and References with it. The package's transport builds
 * a Graph message of its own from the email instead, which drops them: Exchange gives it an id of its own, and the
 * replies to it named one the system never knew.
 *
 * Graph takes a request of 4 MB at most, and MIME goes as base64 of the whole email (its files base64 already): an
 * email too big for that goes the package's way, as before.
 */
final class GraphMimeTransport extends AbstractTransport
{
    /** The most of a request Graph takes, with room to spare. */
    public const MAX_REQUEST_BYTES = 3_900_000;

    public function __construct(
        private readonly string $from,
        private readonly AccessTokenProvider $tokens,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $mime = $message->toString();

        // Bcc is never in the headers sent (Symfony takes it out): Exchange reads whom to send to from them, and takes
        // it out itself.
        if ($original instanceof Email && $original->getBcc() !== []) {
            $mime = 'Bcc: '.implode(', ', array_map(fn (Address $address): string => $address->getAddress(), $original->getBcc()))."\r\n".$mime;
        }

        $body = base64_encode($mime);

        if (strlen($body) > self::MAX_REQUEST_BYTES || ! $original instanceof Email) {
            $this->sendAsGraphMessage($original);

            return;
        }

        $token = $this->tokens->getAuthorizationTokenAsync('https://graph.microsoft.com')->wait();
        $response = Http::withToken((string) $token)
            ->withBody($body, 'text/plain')
            ->timeout(120)
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->from).'/sendMail');

        if ($response->status() === 413) {
            Log::info('Email too large to send whole from '.$this->from.': sent as a Graph message.');
            $this->sendAsGraphMessage($original);

            return;
        }

        if ($response->status() === 403) {
            throw new RuntimeException(__('Microsoft 365 refused to send from :address: the app registration needs the Mail.Send application permission, with admin consent.', ['address' => $this->from]).' ('.($response->json('error.code') ?? '403').')');
        }

        if ($response->failed()) {
            throw new RuntimeException('Microsoft Graph '.$response->status().': '.($response->json('error.message') ?? $response->body()));
        }
    }

    /** The package's way: a Graph message made from the email (its headers not kept). */
    private function sendAsGraphMessage(mixed $email): void
    {
        if (! $email instanceof Email) {
            throw new RuntimeException('Only emails can be sent through Microsoft 365.');
        }

        /** @var MicrosoftGraphClient $client */
        $client = app('mail.microsoft-graph.client');
        $client->sendEmail($email);
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }
}
