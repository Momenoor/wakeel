<?php

namespace Tests\Feature\Letters;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use App\Filament\Mms\Resources\WhatsAppTemplates\Pages\ManageWhatsAppTemplates;
use App\Models\Attachment;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterParty;
use App\Models\MinutesDelivery;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\MMS\Letters\MinutesService;
use App\Services\WhatsAppCloud;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Finalised minutes sent to the attendees to sign — by email and WhatsApp —
 * and the signed copy sent back on WhatsApp, taken in by the webhook.
 */
class MinutesSignatureTest extends TestCase
{
    use RefreshDatabase;

    private MatterMinutes $minutes;

    /** @var list<Email> */
    private array $sent = [];

    /** @var list<array<string, mixed>> */
    private array $whatsapp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();

        config([
            'services.whatsapp.token' => 'wa-token',
            'services.whatsapp.phone_id' => '1234',
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => [
                'username' => 'iflas@jpaemirates.com', 'address' => 'iflas@jpaemirates.com', 'name' => 'JPA Iflas',
                'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
            ],
            'mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph', 'tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
        ]);
        Setting::clearCache();
        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $matter = Matter::factory()->create(['number' => '3153', 'year' => '2026']);
        $party = Party::factory()->create(['name' => 'محمد عبد المقصود', 'email' => ['m@law.ae'], 'phone' => ['0501132801']]);
        MatterParty::create(['matter_id' => $matter->id, 'role' => 'party', 'type' => 'plaintiff', 'party_id' => $party->id]);

        $this->minutes = MatterMinutes::create([
            'matter_id' => $matter->id,
            'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1,
            'meeting_at' => '2026-09-30 16:00:00',
            'attendees' => [
                ['present' => true, 'title' => 'الأستاذ/', 'name' => 'محمد عبد المقصود', 'phone' => '0501132801', 'party_id' => $party->id],
                ['present' => false, 'name' => 'غائب', 'phone' => '0509999999'],
            ],
            'status' => MatterMinutes::DRAFT,
        ]);
        app(MinutesService::class)->finalise($this->minutes, auth()->id());
        $this->minutes->refresh();

        // WhatsApp and OneDrive, answering here.
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/1234/media') => Http::response(['id' => 'media-1']),
                str_ends_with($url, '/1234/messages') => (function () use ($request) {
                    $this->whatsapp[] = $request->data();

                    return Http::response(['messages' => [['id' => 'wamid.sent'.count($this->whatsapp)]]]);
                })(),
                str_ends_with($url, '/media-in') => Http::response(['url' => 'https://lookaside.fbsbx.com/file', 'mime_type' => 'application/pdf']),
                str_starts_with($url, 'https://lookaside.fbsbx.com/') => Http::response('%PDF-signed', 200, ['Content-Type' => 'application/pdf']),
                str_contains($url, 'login.microsoftonline.com') => Http::response(['access_token' => 'graph']),
                str_ends_with($url, '/children') => Http::response(['id' => 'signed-folder', 'webUrl' => 'https://od/signed']),
                str_contains($url, ':/content') => Http::response(['id' => 'file-1', 'webUrl' => 'https://od/signed/file.pdf']),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
            };
        });
    }

    private function send(): void
    {
        Livewire::test(MinutesRelationManager::class, ['ownerRecord' => $this->minutes->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('sendForSignature', $this->minutes)
            // The attendees present, with their email and WhatsApp.
            ->assertSet('mountedActions.0.data.whatsapp_template_id', WhatsAppTemplate::default(WhatsAppTemplate::MINUTES_SIGNATURE)->id)
            ->assertSet('mountedActions.0.data.recipients', fn (array $rows) => array_values($rows) == [[
                'name' => 'الأستاذ/ محمد عبد المقصود', 'party_id' => $this->minutes->attendees[0]['party_id'],
                'email' => 'm@law.ae', 'phone' => '0501132801', 'by_email' => true, 'by_whatsapp' => true,
            ]])
            ->setTableActionData(['sender' => 'iflas'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
    }

    public function test_finalised_minutes_go_to_the_attendees_by_email_and_whatsapp(): void
    {
        $this->send();

        $this->assertCount(1, $this->sent);
        $this->assertSame('m@law.ae', $this->sent[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('محضر اجتماع الخبرة رقم (1)', $this->sent[0]->getSubject());
        $this->assertStringContainsString('السادة/ الأستاذ/ محمد عبد المقصود المحترمين', $this->sent[0]->getHtmlBody());
        $this->assertCount(1, $this->sent[0]->getAttachments());

        // The approved template, the PDF in its header, its parameters by name.
        $message = $this->whatsapp[0];
        $this->assertSame('971501132801', $message['to']);
        $this->assertSame('minutes_for_signature', $message['template']['name']);
        $this->assertSame(['type' => 'document', 'document' => ['id' => 'media-1', 'filename' => MinutesService::fileName($this->minutes).'.pdf']], $message['template']['components'][0]['parameters'][0]);
        $this->assertSame(
            ['name' => 'الأستاذ/ محمد عبد المقصود', 'minutes_number' => '1', 'matter_number' => '3153/2026', 'meeting_date' => '30/09/2026'],
            collect($message['template']['components'][1]['parameters'])->pluck('text', 'parameter_name')->all(),
        );

        $deliveries = $this->minutes->deliveries()->orderBy('channel')->get();
        $this->assertSame([MinutesDelivery::EMAIL, MinutesDelivery::WHATSAPP], $deliveries->pluck('channel')->all());
        $this->assertSame('wamid.sent1', $deliveries[1]->message_id);
    }

    public function test_the_webhook_is_verified_with_its_token_and_signed_calls_only(): void
    {
        $token = WhatsAppCloud::verifyToken();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.$token.'&hub.challenge=42')->assertOk()->assertSee('42');
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();

        // No app secret yet: nothing accepted.
        $this->postJson('/webhooks/whatsapp', ['entry' => []])->assertForbidden();

        Setting::set(WhatsAppCloud::APP_SECRET, encrypt('app-secret'), 'whatsapp');
        $this->postJson('/webhooks/whatsapp', ['entry' => []], ['X-Hub-Signature-256' => 'sha256=wrong'])->assertForbidden();
    }

    public function test_a_signed_copy_sent_back_on_whatsapp_is_filed_put_in_onedrive_and_thanked(): void
    {
        $this->send();

        $assistant = Party::factory()->create(['onedrive_email' => 'assistant@jpa.ae']);
        MatterOneDriveFolder::create(['matter_id' => $this->minutes->matter_id, 'party_id' => $assistant->id, 'folder_name' => '2026-3153',
            'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'matter-folder', 'web_url' => 'https://od/matter']);

        Setting::set(WhatsAppCloud::APP_SECRET, encrypt('app-secret'), 'whatsapp');
        $payload = json_encode(['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '971501132801', 'id' => 'wamid.in1', 'type' => 'document',
            'context' => ['id' => 'wamid.sent1'],
            'document' => ['id' => 'media-in', 'filename' => 'signed.pdf', 'mime_type' => 'application/pdf'],
        ]]]]]]]]);
        $post = fn () => $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'app-secret'),
        ], $payload);

        $post()->assertOk();

        $delivery = $this->minutes->deliveries()->where('channel', MinutesDelivery::WHATSAPP)->sole();
        $this->assertSame(MinutesDelivery::SIGNED, $delivery->status);
        $this->assertSame('https://od/signed/file.pdf', $delivery->onedrive_url);

        $attachment = Attachment::findOrFail($delivery->signed_attachments[0]);
        $this->assertSame('minutes_signed', $attachment->type);
        $this->assertSame('%PDF-signed', Storage::disk('public')->get($attachment->path));
        $this->assertStringContainsString('الأستاذ/ محمد عبد المقصود', $attachment->name);

        // Into the matter folder's signed-minutes subfolder.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/drive/items/matter-folder/children') && $r['name'] === 'محاضر موقعة');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/drive/items/signed-folder:/') && $r->body() === '%PDF-signed');

        // Thanked, once.
        $this->assertSame('text', end($this->whatsapp)['type']);

        // Sent again by Meta: taken in once.
        $post()->assertOk();
        $this->assertCount(1, $delivery->fresh()->signed_attachments);
        $this->assertSame(1, Attachment::where('type', 'minutes_signed')->count());

        Livewire::test(MinutesRelationManager::class, ['ownerRecord' => $this->minutes->matter, 'pageClass' => ViewMatter::class])
            ->assertTableColumnStateSet('signed', '1 / 1', $this->minutes)
            ->mountTableAction('signatures', $this->minutes)
            ->assertMountedActionModalSee('https://od/signed/file.pdf');
    }

    public function test_whatsapp_templates_and_the_webhook_settings_screen(): void
    {
        Livewire::test(ManageWhatsAppTemplates::class)
            ->assertCanSeeTableRecords(WhatsAppTemplate::all())
            ->mountAction('webhook')
            ->assertSet('mountedActions.0.data.url', route('webhooks.whatsapp'))
            ->setActionData(['app_secret' => 'new-secret'])
            ->callMountedAction();

        $this->assertSame('new-secret', WhatsAppCloud::appSecret());
    }
}
