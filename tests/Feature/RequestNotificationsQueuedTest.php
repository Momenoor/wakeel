<?php

namespace Tests\Feature;

use App\Jobs\SendRequestNotifications;
use App\Models\Matter;
use App\Models\MatterRequest;
use App\Models\User;
use App\Services\MMS\Requests\RequestServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Who is told of a request — made, or decided — is told from the queue: saving the request never waits on
 * WhatsApp or the mail server.
 */
class RequestNotificationsQueuedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_made_or_decided_is_told_from_the_queue(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        $request = MatterRequest::create(['matter_id' => Matter::factory()->create()->id, 'request_by' => $user->id, 'type' => 'review_report', 'status' => 'pending', 'comment' => 'x']);
        $service = RequestServiceFactory::make($request);

        $service->onCreateNotify();
        $service->reject(['approved_comment' => 'Not yet', 'attachments' => []]);

        Queue::assertPushed(SendRequestNotifications::class, fn (SendRequestNotifications $job): bool => $job->requestId === $request->id && $job->kind === 'created');
        Queue::assertPushed(SendRequestNotifications::class, fn (SendRequestNotifications $job): bool => $job->requestId === $request->id && $job->kind === 'decided');
        // Nothing sent while the request was saved.
        Mail::assertNothingSent();
    }
}
