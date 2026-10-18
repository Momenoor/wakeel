<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Filament\Mms\Actions\Request\ResubmitRequestAction;
use App\Mail\NewRequestNotificationMail;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterRequest;
use App\Models\Party;
use App\Models\ReportReviewDelivery;
use App\Models\Type;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\Requests\ReportReviewWhatsApp;
use App\Services\MMS\Requests\RequestServiceFactory;
use App\Services\Notifications\NotificationPreferences;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A report reviewed on WhatsApp: sent to the assistant's reviewers with Approve / Reject / Needs
 * changes; the decision (and the text and files of a rejection) lands on the request.
 */
class ReportReviewWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private User $reviewer;

    private User $other;

    private Matter $matter;

    private MatterRequest $request;

    /** @var list<array{url: string, data: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('attachments');
        Mail::fake();
        // Who is told of a new request (admins and super admins).
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        Gate::before(fn () => true);
        config(['services.whatsapp.token' => 't', 'services.whatsapp.phone_id' => '99', 'services.whatsapp.app_secret' => 'secret']);

        Http::fake(function (Request $request) {
            $this->calls[] = ['url' => $request->url(), 'data' => $request->data()];

            return match (true) {
                str_ends_with($request->url(), '/media') => Http::response(['id' => 'media-1']),
                str_ends_with($request->url(), '/messages') => Http::response(['messages' => [['id' => 'wamid.'.count($this->calls)]]]),
                str_contains($request->url(), 'files.test') => Http::response('PICTURE-BYTES'),
                str_contains($request->url(), 'graph.facebook.com') => Http::response(['url' => 'https://files.test/pic', 'mime_type' => 'image/jpeg']),
                default => Http::response('', 404),
            };
        });

        $this->assistant = User::factory()->create(['name' => 'Assistant']);
        $assistantParty = Party::factory()->create(['user_id' => $this->assistant->id, 'phone' => ['0500000001']]);
        $this->reviewer = User::factory()->create(['name' => 'Reviewer']);
        Party::factory()->create(['user_id' => $this->reviewer->id, 'phone' => ['0501234567']]);
        $this->other = User::factory()->create(['name' => 'Second']);
        Party::factory()->create(['user_id' => $this->other->id, 'phone' => ['0507654321']]);
        $assistantParty->reportReviewers()->attach([$this->reviewer->id, $this->other->id]);

        WhatsAppTemplate::create([
            'name' => 'Report review', 'purpose' => WhatsAppTemplate::REPORT_REVIEW, 'meta_name' => 'report_review', 'language' => 'ar',
            'header' => 'document', 'body' => 'Report {{matter}} from {{assistant}}', 'is_active' => true, 'is_default' => true,
            'parameters' => [['name' => 'reviewer', 'value' => '{{reviewer}}'], ['name' => 'matter', 'value' => '{{matter}}'], ['name' => 'assistant', 'value' => '{{assistant}}']],
        ]);

        $this->matter = Matter::factory()->create();
        MatterParty::create(['matter_id' => $this->matter->id, 'party_id' => $assistantParty->id, 'role' => 'expert', 'type' => 'assistant']);
        $this->request = $this->makeRequest();
    }

    private function makeRequest(RequestType $type = RequestType::REVIEW_REPORT): MatterRequest
    {
        $request = MatterRequest::create(['matter_id' => $this->matter->id, 'request_by' => $this->assistant->id, 'type' => $type, 'status' => RequestStatus::PENDING, 'comment' => 'Please review']);
        Storage::disk('public')->put('requests-attachments/report.pdf', '%PDF');
        $request->attachments()->create(['name' => 'report.pdf', 'path' => 'requests-attachments/report.pdf', 'size' => 4, 'extension' => 'pdf', 'type' => 'matter-request', 'matter_id' => $this->matter->id, 'user_id' => $this->assistant->id]);

        return $request;
    }

    private function deliveryOf(User $user, ?MatterRequest $request = null): ReportReviewDelivery
    {
        return ReportReviewDelivery::query()->where('matter_request_id', ($request ?? $this->request)->id)->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function press(ReportReviewDelivery $delivery, string $action, ?string $from = null): bool
    {
        return app(ReportReviewWhatsApp::class)->receive([
            'type' => 'button', 'from' => $from ?? $delivery->phone, 'id' => 'in-'.uniqid(),
            'button' => ['payload' => ReportReviewWhatsApp::payload($delivery, $action), 'text' => $action],
        ]);
    }

    /** What was sent to WhatsApp as messages, in order. */
    private function messages(): array
    {
        return array_values(array_filter(array_map(fn (array $call) => str_ends_with($call['url'], '/messages') ? $call['data'] : null, $this->calls)));
    }

    public function test_the_report_goes_to_each_reviewer_with_three_buttons(): void
    {
        $result = app(ReportReviewWhatsApp::class)->send($this->request);

        $this->assertSame(['sent' => 2, 'failed' => 0], array_intersect_key($result, ['sent' => 1, 'failed' => 1]));
        $this->assertEqualsCanonicalizing([$this->reviewer->id, $this->other->id], $result['reached']);
        [$first] = $this->messages();
        $this->assertSame('971501234567', $first['to']);
        $this->assertSame('report_review', $first['template']['name']);
        $components = collect($first['template']['components']);
        // The report in the header, the parameters filled, the buttons carrying what comes back.
        $this->assertSame('media-1', $components->firstWhere('type', 'header')['parameters'][0]['document']['id']);
        $this->assertSame(['Reviewer', $this->matter->number.'/'.$this->matter->year, 'Assistant'], array_column($components->firstWhere('type', 'body')['parameters'], 'text'));
        $delivery = $this->deliveryOf($this->reviewer);
        $this->assertSame([ReportReviewWhatsApp::payload($delivery, 'approve'), ReportReviewWhatsApp::payload($delivery, 'reject'), ReportReviewWhatsApp::payload($delivery, 'changes')],
            $components->where('type', 'button')->map(fn ($b) => $b['parameters'][0]['payload'])->values()->all());
        $this->assertSame('wamid.2', $delivery->message_id);
    }

    public function test_creating_the_request_sends_it_and_without_reviewers_or_a_template_nothing_goes(): void
    {
        RequestServiceFactory::make($this->request)->onCreateNotify();
        $this->assertSame(2, ReportReviewDelivery::count());

        // Nobody set to review: as before.
        $this->calls = [];
        $this->assistant->party->reportReviewers()->detach();
        RequestServiceFactory::make($this->makeRequest(RequestType::CONFIRM_REPORT))->onCreateNotify();
        $this->assertSame([], $this->messages());

        // Not a report.
        $this->assistant->party->reportReviewers()->attach($this->reviewer->id);
        $other = MatterRequest::create(['matter_id' => $this->matter->id, 'request_by' => $this->assistant->id, 'type' => RequestType::CHANGE_DIFFICULTY, 'status' => RequestStatus::PENDING, 'comment' => 'x']);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'reached' => []], app(ReportReviewWhatsApp::class)->send($other));
    }

    public function test_a_parameter_can_hold_several_placeholders(): void
    {
        $this->matter->update(['type_id' => Type::factory()->create(['name' => 'Commercial'])->id]);
        // Set in the template record: the reference and the type, in one parameter, with the system's own placeholders.
        $template = WhatsAppTemplate::query()->where('purpose', WhatsAppTemplate::REPORT_REVIEW)->sole();
        $template->update(['parameters' => [['name' => 'reviewer', 'value' => '{{reviewer}}'], ['name' => 'matter', 'value' => '{{matter.reference}} - {{matter.type}}'], ['name' => 'assistant', 'value' => '{{assistant}}']]]);

        app(ReportReviewWhatsApp::class)->send($this->request);

        $body = collect($this->messages()[0]['template']['components'])->firstWhere('type', 'body')['parameters'];
        $this->assertSame($this->matter->fresh()->reference.' - Commercial', $body[1]['text']);
    }

    public function test_a_reviewer_who_is_also_an_admin_is_not_told_of_the_request_as_well(): void
    {
        $this->reviewer->assignRole('admin');
        $this->reviewer->refresh();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // The admin has WhatsApp turned on for new requests (the reviewer too, were they not reached).
        foreach ([$this->reviewer, $admin->refresh()] as $user) {
            NotificationPreferences::make()->saveUser($user, [NotificationType::REQUEST_CREATED->value => ['whatsapp' => true]]);
        }
        Party::factory()->create(['user_id' => $admin->id, 'phone' => ['0509999999']]);

        RequestServiceFactory::make($this->request)->onCreateNotify();

        // The other admin is told as before: bell, email and WhatsApp.
        $this->assertCount(1, $admin->notifications);
        Mail::assertSent(NewRequestNotificationMail::class, fn ($mail) => $mail->hasTo($admin->email) && ! $mail->hasTo($this->reviewer->email));
        $templates = fn (string $phone) => collect($this->messages())->where('to', $phone)->pluck('template.name')->all();
        $this->assertSame(['new_request_notification'], $templates('971509999999'));
        // The reviewer: the report only.
        $this->assertCount(0, $this->reviewer->notifications);
        $this->assertSame(['report_review'], $templates('971501234567'));

        // Nobody else to tell (everyone reached): no mail at all, and no error.
        Mail::fake();
        $this->request->update(['status' => RequestStatus::PENDING]);
        $admin->removeRole('admin');
        $this->other->assignRole('admin');
        RequestServiceFactory::make($this->request)->onCreateNotify();
        Mail::assertNothingSent();
    }

    public function test_every_file_a_reviewer_sends_is_kept_with_the_request(): void
    {
        app(ReportReviewWhatsApp::class)->send($this->request);
        $mine = $this->deliveryOf($this->reviewer);
        $reviews = app(ReportReviewWhatsApp::class);

        $this->press($mine, 'changes');
        foreach (['m1', 'm2', 'm3'] as $id) {
            $reviews->receive(['type' => 'image', 'from' => $mine->phone, 'id' => $id, 'image' => ['id' => 'media-'.$id, 'mime_type' => 'image/jpeg']]);
        }
        $reviews->receive(['type' => 'document', 'from' => $mine->phone, 'id' => 'm4', 'document' => ['id' => 'media-m4', 'mime_type' => 'application/pdf', 'filename' => 'notes.pdf']]);
        $this->assertCount(4, $mine->refresh()->pending_files);

        $this->press($mine, 'done');

        // All four with the request (from WhatsApp, on the private disk), besides the report itself.
        $this->assertSame(4, $this->request->refresh()->attachments()->where('source', 'whatsapp')->count());
        $this->assertSame(RequestStatus::NEEDS_CHANGES, $this->request->status);
    }

    public function test_approve_decides_the_request_as_the_reviewer(): void
    {
        app(ReportReviewWhatsApp::class)->send($this->request);
        $mine = $this->deliveryOf($this->reviewer);
        $theirs = $this->deliveryOf($this->other);

        $this->assertTrue($this->press($mine, 'approve'));

        $this->request->refresh();
        $this->assertSame([RequestStatus::APPROVED, $this->reviewer->id], [$this->request->status, $this->request->approved_by]);
        $this->assertNotNull($this->matter->refresh()->initial_report_at);
        $this->assertSame('approved', $mine->refresh()->decision);
        // The other reviewer's message no longer decides; pressing it says so.
        $this->assertSame('closed', $theirs->refresh()->status);
        $this->assertTrue($this->press($theirs, 'reject'));
        $this->assertSame(RequestStatus::APPROVED, $this->request->refresh()->status);
        $this->assertSame(__('This request has already been decided.'), collect($this->messages())->last()['text']['body'] ?? '');
        // Nobody left signed in by the webhook.
        $this->assertNull(Auth::user());
    }

    public function test_a_button_from_another_number_or_a_repeated_message_does_nothing(): void
    {
        app(ReportReviewWhatsApp::class)->send($this->request);
        $mine = $this->deliveryOf($this->reviewer);

        $this->assertFalse($this->press($mine, 'approve', from: '971509999999'));
        $this->assertSame(RequestStatus::PENDING, $this->request->refresh()->status);

        // A text with nothing being collected is not a review's: left to the other handlers.
        $this->assertFalse(app(ReportReviewWhatsApp::class)->receive(['type' => 'text', 'from' => $mine->phone, 'id' => 'x', 'text' => ['body' => 'hello']]));

        // Meta sending the same message twice: once.
        $message = ['type' => 'button', 'from' => $mine->phone, 'id' => 'same', 'button' => ['payload' => ReportReviewWhatsApp::payload($mine, 'reject')]];
        app(ReportReviewWhatsApp::class)->receive($message);
        $this->calls = [];
        app(ReportReviewWhatsApp::class)->receive($message);
        $this->assertSame([], $this->messages());
    }

    public function test_reject_collects_text_and_files_then_decides_on_send_decision(): void
    {
        app(ReportReviewWhatsApp::class)->send($this->request);
        $mine = $this->deliveryOf($this->reviewer);
        $reviews = app(ReportReviewWhatsApp::class);

        // Reject: asks for the details, with Send decision / Cancel.
        $this->press($mine, 'reject');
        $this->assertSame('reject', $mine->refresh()->awaiting);
        $buttons = collect($this->messages())->last()['interactive']['action']['buttons'];
        $this->assertSame([ReportReviewWhatsApp::payload($mine, 'done'), ReportReviewWhatsApp::payload($mine, 'cancel')], array_column(array_column($buttons, 'reply'), 'id'));

        // Pressing Send decision with nothing sent asks again.
        $this->press($mine, 'done');
        $this->assertSame(RequestStatus::PENDING, $this->request->refresh()->status);

        // Text, then a picture with a caption.
        $this->assertTrue($reviews->receive(['type' => 'text', 'from' => $mine->phone, 'id' => 't1', 'text' => ['body' => 'Section 3 is wrong']]));
        $this->assertTrue($reviews->receive(['type' => 'image', 'from' => $mine->phone, 'id' => 'i1', 'image' => ['id' => 'media-9', 'mime_type' => 'image/jpeg', 'caption' => 'see here']]));
        $this->assertSame(RequestStatus::PENDING, $this->request->refresh()->status);

        $this->press($mine, 'done');

        $this->request->refresh();
        $this->assertSame([RequestStatus::REJECTED, $this->reviewer->id], [$this->request->status, $this->request->approved_by]);
        $this->assertSame("Section 3 is wrong\nsee here", $this->request->approved_comment);
        // The picture kept with the request, as if uploaded there.
        $picture = $this->request->attachments()->where('source', 'whatsapp')->sole();
        $this->assertSame(['request', 'attachments'], [$picture->category->value, $picture->disk]);
        $this->assertSame('PICTURE-BYTES', Storage::disk('attachments')->get($picture->path));
        // The assistant told, on the system.
        $this->assertSame('rejected', $mine->refresh()->decision);
        $this->assertCount(1, $this->assistant->notifications);
    }

    public function test_changes_needed_is_its_own_status_and_counts_as_a_review_then_the_assistant_sends_it_again(): void
    {
        RequestServiceFactory::make($this->request)->afterCreated();
        $this->assertSame(1, $this->matter->refresh()->review_count);
        app(ReportReviewWhatsApp::class)->send($this->request);
        $mine = $this->deliveryOf($this->reviewer);

        $this->press($mine, 'changes');
        app(ReportReviewWhatsApp::class)->receive(['type' => 'text', 'from' => $mine->phone, 'id' => 'c1', 'text' => ['body' => 'Fix the dates']]);
        $this->press($mine, 'done');

        $this->request->refresh();
        $this->assertSame([RequestStatus::NEEDS_CHANGES, 'Fix the dates', 1], [$this->request->status, $this->request->approved_comment, $this->request->extra['modifications']]);
        // A review of the matter, counted.
        $this->assertSame(2, $this->matter->refresh()->review_count);
        $this->assertCount(1, $this->assistant->notifications);

        // Only the assistant who asked can send it again.
        $this->actingAs($this->reviewer);
        $this->assertFalse(ResubmitRequestAction::make()->record($this->request)->isVisible());
        $this->actingAs($this->assistant);
        $this->assertTrue(ResubmitRequestAction::make()->record($this->request)->isVisible());

        // Sent again: back to pending, the corrected file to the reviewers (round 2), counted once more only if asked again.
        $this->calls = [];
        Storage::disk('public')->put('requests-attachments/fixed.pdf', '%PDF-2');
        RequestServiceFactory::make($this->request)->resubmit(['comment' => 'Dates fixed', 'attachments' => [['path' => 'requests-attachments/fixed.pdf']]]);

        $this->request->refresh();
        $this->assertSame([RequestStatus::PENDING, 'Dates fixed', null], [$this->request->status, $this->request->comment, $this->request->approved_by]);
        $this->assertSame('Fix the dates', $this->request->extra['rounds'][0]['reason']);
        $this->assertSame(2, $this->matter->refresh()->review_count);
        $this->assertSame(2, ReportReviewDelivery::query()->where('round', 2)->count());
        // The corrected file is the one sent (uploaded as media).
        $this->assertContains('media-1', array_map(fn (array $message) => $message['template']['components'][0]['parameters'][0]['document']['id'] ?? null, $this->messages()));

        // Decided on the second round.
        $this->press($this->deliveryOf($this->reviewer), 'approve');
        $this->assertSame(RequestStatus::APPROVED, $this->request->refresh()->status);

        // Deleted: the matter's count goes back by the review and the changes asked.
        $this->request->delete();
        $this->assertSame(0, $this->matter->refresh()->review_count);
    }

    public function test_a_reviewer_with_two_reports_decides_each_on_its_own(): void
    {
        $second = $this->makeRequest();
        app(ReportReviewWhatsApp::class)->send($this->request);
        app(ReportReviewWhatsApp::class)->send($second);
        $a = $this->deliveryOf($this->reviewer);
        $b = $this->deliveryOf($this->reviewer, $second);
        $reviews = app(ReportReviewWhatsApp::class);

        // Approve one, whichever order: each button is its own request's.
        $this->press($b, 'approve');
        $this->assertSame([RequestStatus::PENDING, RequestStatus::APPROVED], [$this->request->refresh()->status, $second->refresh()->status]);

        // Reject begun on A, then on the other: A's is cancelled and said — the text goes to the latest only.
        $second2 = $this->makeRequest();
        app(ReportReviewWhatsApp::class)->send($second2);
        $c = $this->deliveryOf($this->reviewer, $second2);
        $this->press($a, 'reject');
        $reviews->receive(['type' => 'text', 'from' => $a->phone, 'id' => 'a-text', 'text' => ['body' => 'for the first']]);
        $this->calls = [];
        $this->press($c, 'reject');
        $this->assertNull($a->refresh()->awaiting);
        $this->assertSame('reject', $c->refresh()->awaiting);
        $this->assertStringContainsString('#'.$this->request->id, $this->messages()[0]['text']['body']);
        // Every question names its request and matter.
        $this->assertStringContainsString(__('Request #:id — matter :matter', ['id' => $second2->id, 'matter' => $this->matter->number.'/'.$this->matter->year]), $this->messages()[1]['interactive']['body']['text']);

        $reviews->receive(['type' => 'text', 'from' => $c->phone, 'id' => 'c-text', 'text' => ['body' => 'for the third']]);
        $this->press($c, 'done');
        $this->assertSame(['for the third', RequestStatus::REJECTED], [$second2->refresh()->approved_comment, $second2->status]);
        // The first was never decided by it.
        $this->assertSame(RequestStatus::PENDING, $this->request->refresh()->status);
    }

    public function test_a_message_from_an_earlier_round_does_not_decide_the_corrected_report(): void
    {
        app(ReportReviewWhatsApp::class)->send($this->request);
        $old = $this->deliveryOf($this->reviewer);
        $this->press($old, 'changes');
        app(ReportReviewWhatsApp::class)->receive(['type' => 'text', 'from' => $old->phone, 'id' => 'c1', 'text' => ['body' => 'fix']]);
        $this->press($old, 'done');
        Storage::disk('public')->put('requests-attachments/fixed.pdf', '%PDF-2');
        RequestServiceFactory::make($this->request->refresh())->resubmit(['comment' => 'fixed', 'attachments' => [['path' => 'requests-attachments/fixed.pdf']]]);

        // The first message's Approve: told to use the latest — the request still waits.
        $this->press($old, 'approve');
        $this->assertSame(RequestStatus::PENDING, $this->request->refresh()->status);
        $this->assertSame(__('This report was sent again after changes: please use the latest message.'), explode("\n", collect($this->messages())->last()['text']['body'], 2)[1]);

        // The new message's button decides.
        $this->press($this->deliveryOf($this->reviewer), 'approve');
        $this->assertSame(RequestStatus::APPROVED, $this->request->refresh()->status);
    }

    public function test_the_webhook_takes_a_signed_button_in_and_refuses_an_unsigned_one(): void
    {
        $this->withoutDefer();
        app(ReportReviewWhatsApp::class)->send($this->request);
        $mine = $this->deliveryOf($this->reviewer);
        $body = json_encode(['entry' => [['changes' => [['value' => ['messages' => [[
            'type' => 'button', 'from' => $mine->phone, 'id' => 'wh1', 'button' => ['payload' => ReportReviewWhatsApp::payload($mine, 'approve')],
        ]]]]]]]]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong'], $body)->assertForbidden();
        $this->assertSame(RequestStatus::PENDING, $this->request->refresh()->status);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret')], $body)->assertOk();
        $this->assertSame(RequestStatus::APPROVED, $this->request->refresh()->status);
    }

    public function test_a_confirm_report_is_reviewed_the_same_way(): void
    {
        $request = $this->makeRequest(RequestType::CONFIRM_REPORT);
        app(ReportReviewWhatsApp::class)->send($request);

        $this->press($this->deliveryOf($this->reviewer, $request), 'approve');

        $this->assertSame(RequestStatus::APPROVED, $request->refresh()->status);
        $this->assertNotNull($this->matter->refresh()->final_report_memo_date);
    }
}
