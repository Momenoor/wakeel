<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\MatterRequest;
use App\Services\MMS\Requests\RequestServiceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Who is told of a request — made (or sent again), or decided — on the queue: the report sent to its reviewers on
 * WhatsApp, a message and an email for each person, so saving the request never waits on WhatsApp or the mail
 * server (a request once took 36 seconds to save). Not tried again: someone told twice is worse than once late.
 */
class SendRequestNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  'created'|'decided'  $kind
     */
    public function __construct(
        public int $requestId,
        public string $kind,
        public bool $again = false,
        public ?NotificationType $type = null,
        public ?string $title = null,
        public ?string $body = null,
    ) {}

    public function handle(): void
    {
        $request = MatterRequest::with('matter')->find($this->requestId);

        if ($request === null) {
            return;
        }

        $service = RequestServiceFactory::make($request);

        $this->kind === 'created'
            ? $service->sendCreateNotifications($this->again)
            : $service->sendActionNotifications($this->type, (string) $this->title, (string) $this->body);
    }
}
