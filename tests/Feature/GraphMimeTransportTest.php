<?php

namespace Tests\Feature;

use App\Mail\Transport\GraphMimeTransport;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Maize\MsgraphMailer\Services\MicrosoftGraphClient;
use Microsoft\Kiota\Abstractions\Authentication\AccessTokenProvider;
use Microsoft\Kiota\Abstractions\Authentication\AllowedHostsValidator;
use Mockery;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * A Microsoft 365 mailbox's emails sent whole: the Message-ID the system keeps is the one that goes, so the
 * replies naming it are known as replies.
 */
class GraphMimeTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'microsoft-graph', 'mail.from.address' => 'office@jpa.ae', 'mail.from.name' => 'JPA']);
        $this->app->instance('mail.microsoft-graph.token-provider', new class implements AccessTokenProvider
        {
            public function getAuthorizationTokenAsync(string $url, array $additionalAuthenticationContext = []): Promise
            {
                return new FulfilledPromise('token');
            }

            public function getAllowedHostsValidator(): AllowedHostsValidator
            {
                return new AllowedHostsValidator(['graph.microsoft.com']);
            }
        });
        app('mail.manager')->purge('microsoft-graph');
    }

    public function test_the_email_goes_whole_with_the_message_id_kept_its_reply_headers_and_bcc(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response('', 202)]);

        $sent = Mail::mailer('microsoft-graph')->raw('مرفق الخطاب', function ($message): void {
            $message->to('office@albanna.ae')->bcc('archive@jpa.ae')->subject('خطاب');
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('In-Reply-To', 'theirs@albanna.ae');
        });

        Http::assertSent(function (Request $request) use ($sent): bool {
            $mime = base64_decode($request->body());

            return $request->url() === 'https://graph.microsoft.com/v1.0/users/office%40jpa.ae/sendMail'
                && $request->hasHeader('Content-Type', 'text/plain')
                && $request->hasHeader('Authorization', 'Bearer token')
                && str_contains($mime, 'Message-ID: <'.$sent->getMessageId().'>')
                && str_contains($mime, 'In-Reply-To: <theirs@albanna.ae>')
                && str_starts_with($mime, 'Bcc: archive@jpa.ae');
        });
    }

    public function test_one_too_big_to_go_whole_goes_as_a_graph_message_and_a_refusal_says_why(): void
    {
        Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);
        $client = Mockery::mock(MicrosoftGraphClient::class);
        $client->shouldReceive('sendEmail')->once()->with(Mockery::type(Email::class));
        $this->app->instance('mail.microsoft-graph.client', $client);

        Mail::mailer('microsoft-graph')->raw('كبير', function ($message): void {
            $message->to('office@albanna.ae')->subject('ملفات')->attachData(random_bytes(GraphMimeTransport::MAX_REQUEST_BYTES), 'big.bin');
        });
        Http::assertNothingSent();

        $this->expectExceptionMessage('Mail.Send');
        Mail::mailer('microsoft-graph')->raw('صغير', fn ($message) => $message->to('office@albanna.ae')->subject('s'));
    }
}
