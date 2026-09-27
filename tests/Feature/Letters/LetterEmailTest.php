<?php

namespace Tests\Feature\Letters;

use App\Enums\LetterStatus;
use App\Filament\Mms\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Models\EmailTemplate;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Emailing an issued letter through a department mailbox.
 */
class LetterEmailTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Email> */
    private array $sent = [];

    private MatterLetter $letter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            // The mailbox's smtp mailer delivers into memory here.
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => [
                'username' => 'iflas@jpaemirates.com', 'address' => 'iflas@jpaemirates.com', 'name' => 'JPA Iflas',
                'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
            ],
        ]);

        Event::listen(MessageSent::class, fn (MessageSent $event) => $this->sent[] = $event->message);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        // A signature image, to check it's embedded in the email.
        Storage::disk('public')->put('letterheads/sig.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $letterhead = Letterhead::create(['name' => 'Main', 'is_default' => true, 'signature_image' => 'letterheads/sig.png', 'elements' => Letterhead::defaultElements()]);

        $template = LetterTemplate::create([
            'name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter', 'letterhead_id' => $letterhead->id,
            'subject' => 'إشعار الدعوى رقم {{matter.reference}}',
            'body' => '<p>{{recipients}}</p><p>نص الخطاب.</p><p>{{signature}}</p>',
        ]);

        $this->letter = app(LetterIssuer::class)->issue($template, Matter::factory()->create(['number' => '986', 'year' => '2026']), [
            ['name' => 'منى أحمد', 'role' => 'المدعي', 'emails' => ['mona@example.com']],
            ['name' => 'مكتب المزروعي', 'role' => 'وكيل المدعي', 'emails' => ['a@law.ae', 'b@law.ae']],
            ['name' => 'بدون بريد', 'role' => null, 'emails' => []],
        ], []);
    }

    public function test_a_letter_goes_as_an_attachment_under_a_covering_email(): void
    {
        $cover = EmailTemplate::create(['name' => 'Cover', 'locale' => 'ar', 'subject' => 'خطاب {{reference}}',
            'body' => '<p>مرفق الخطاب رقم <span data-type="mergeTag" data-id="reference"></span> — {{subject}}</p>']);

        $result = app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::ATTACHMENT, $cover, ['pdf', 'docx'], cc: ['boss@jpa.ae']);

        $this->assertSame(['sent' => 2, 'failed' => 0, 'skipped' => 1, 'errors' => []], $result);
        $this->assertCount(1, $this->sent);

        $email = $this->sent[0];
        $this->assertSame('iflas@jpaemirates.com', $email->getFrom()[0]->getAddress());
        $this->assertSame(['mona@example.com', 'a@law.ae', 'b@law.ae'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('boss@jpa.ae', $email->getCc()[0]->getAddress());
        $this->assertSame('خطاب JPA/2026/986/1', $email->getSubject());
        $this->assertStringContainsString('مرفق الخطاب رقم JPA/2026/986/1 — إشعار الدعوى رقم 986/2026', $email->getHtmlBody());

        $names = array_map(fn ($part) => $part->getFilename(), $email->getAttachments());
        $this->assertCount(2, $names);
        $this->assertStringEndsWith('.pdf', $names[0]);
        $this->assertStringEndsWith('.docx', $names[1]);

        $letter = $this->letter->fresh(['recipients']);
        $this->assertSame(LetterStatus::SENT, $letter->status);
        $this->assertSame('iflas', $letter->sender_key);
        $this->assertSame([LetterStatus::SENT, LetterStatus::SENT, LetterStatus::DRAFT], $letter->recipients->pluck('delivery_status')->all());
    }

    public function test_the_letter_itself_as_the_email_with_the_signature_embedded(): void
    {
        app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::BODY);

        $email = $this->sent[0];
        $this->assertSame('JPA/2026/986/1 — إشعار الدعوى رقم 986/2026', $email->getSubject());
        $this->assertStringContainsString('نص الخطاب.', $email->getHtmlBody());
        $this->assertStringContainsString('المرجع: <span dir="ltr">JPA/2026/986/1</span>', $email->getHtmlBody());
        // The signature travels with the email, not as a server path.
        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        $this->assertStringNotContainsString(storage_path(), $email->getHtmlBody());
        $this->assertCount(0, array_filter($email->getAttachments(), fn ($part) => ! $part->hasContentId()));
    }

    public function test_separate_emails_greet_each_recipient(): void
    {
        $cover = EmailTemplate::create(['name' => 'Cover', 'subject' => '{{reference}}', 'body' => '<p>السادة/ {{recipient.name}}</p>']);

        app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::ATTACHMENT, $cover, ['pdf'], separate: true);

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('السادة/ منى أحمد', $this->sent[0]->getHtmlBody());
        $this->assertSame(['a@law.ae', 'b@law.ae'], array_map(fn ($a) => $a->getAddress(), $this->sent[1]->getTo()));
        $this->assertStringContainsString('السادة/ مكتب المزروعي', $this->sent[1]->getHtmlBody());
    }

    public function test_a_failed_send_is_recorded_per_recipient(): void
    {
        config(['mail.mailers.smtp.transport' => 'smtp', 'mail_senders.senders.iflas.host' => '127.0.0.1', 'mail_senders.senders.iflas.port' => 9, 'mail.mailers.smtp.timeout' => 2]);

        $result = app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::ATTACHMENT, null, ['pdf']);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(2, $result['failed']);
        $recipient = $this->letter->fresh(['recipients'])->recipients->first();
        $this->assertSame(LetterStatus::FAILED, $recipient->delivery_status);
        $this->assertNotEmpty($recipient->failure_reason);
        $this->assertSame(LetterStatus::DRAFT, $this->letter->fresh()->status);
    }

    public function test_sending_from_the_matter_page(): void
    {
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->letter->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('email', $this->letter, [
                'sender' => 'iflas',
                'mode' => LetterMailer::ATTACHMENT,
                'formats' => ['pdf'],
                'recipients' => [$this->letter->recipients->first()->id],
                'separate' => false,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertCount(1, $this->sent);
        $this->assertSame(['mona@example.com'], array_map(fn ($a) => $a->getAddress(), $this->sent[0]->getTo()));
        // No template chosen: the built-in covering note.
        $this->assertStringContainsString('نرفق لكم طيه الخطاب رقم JPA/2026/986/1', $this->sent[0]->getHtmlBody());
    }

    public function test_the_email_templates_screen(): void
    {
        $this->get(EmailTemplateResource::getUrl())->assertSuccessful();
    }
}
