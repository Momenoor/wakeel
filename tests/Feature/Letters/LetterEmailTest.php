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
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\SentFolder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_more_files_go_with_the_letter_either_way(): void
    {
        Storage::disk('local')->put('letter-attachments/statement.pdf', '%PDF-1.4 statement');
        $statement = ['path' => Storage::disk('local')->path('letter-attachments/statement.pdf'), 'name' => 'كشف الحساب.pdf'];
        $mona = [$this->letter->recipients->first()->id];

        // Attached: the letter and the file.
        app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::ATTACHMENT, null, ['pdf'], $mona, attachments: [$statement]);
        $names = array_map(fn ($part) => $part->getFilename(), $this->sent[0]->getAttachments());
        $this->assertCount(2, $names);
        $this->assertStringEndsWith('.pdf', $names[0]);
        $this->assertSame('كشف الحساب.pdf', $names[1]);

        // The letter as the email: the file, not the letter (only its
        // signature, embedded in the text).
        app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::BODY, recipientIds: $mona, attachments: [$statement]);
        $this->assertSame(['sig.png', 'كشف الحساب.pdf'], array_map(fn ($part) => $part->getFilename(), $this->sent[1]->getAttachments()));
    }

    public function test_the_matters_assistants_are_copied_in_and_files_can_be_added(): void
    {
        $matter = $this->letter->matter;
        $expert = fn (string $type, array $party) => MatterParty::create([
            'matter_id' => $matter->id, 'role' => 'expert', 'type' => $type,
            'party_id' => Party::factory()->create($party)->id,
        ]);
        $expert('assistant', ['name' => 'سارة', 'email' => ['sara@jpa.ae']]);
        // No email of their own: the account they sign in with.
        $expert('external-assistant', ['name' => 'Omar', 'email' => [], 'user_id' => User::factory()->create(['email' => 'omar@partner.ae'])->id]);
        // The expert himself is not an assistant.
        $expert('certified', ['name' => 'Reda', 'email' => ['reda@jpa.ae']]);

        Storage::fake('local');

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $this->letter)
            ->assertSet('mountedActions.0.data.cc', ['sara@jpa.ae', 'omar@partner.ae'])
            ->setTableActionData([
                'sender' => 'iflas',
                'recipients' => [$this->letter->recipients->first()->id],
                'attachments' => [UploadedFile::fake()->create('statement.pdf', 12, 'application/pdf')],
            ])
            ->assertMountedActionModalSee('statement.pdf')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $email = $this->sent[0];
        $this->assertSame(['sara@jpa.ae', 'omar@partner.ae'], array_map(fn ($a) => $a->getAddress(), $email->getCc()));
        $this->assertContains('statement.pdf', array_map(fn ($part) => $part->getFilename(), $email->getAttachments()));
        // The upload was for this email only.
        $this->assertSame([], Storage::disk('local')->allFiles('letter-attachments'));

        // System Settings chooses who is copied in, for every letter.
        Setting::set('letter_cc_expert_types', ['certified', 'external-assistant'], 'general');
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $this->letter)
            ->assertSet('mountedActions.0.data.cc', ['omar@partner.ae', 'reda@jpa.ae']);

        Setting::set('letter_cc_expert_types', [], 'general');
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $this->letter)
            ->assertSet('mountedActions.0.data.cc', []);
    }

    public function test_the_email_is_previewed_and_can_be_changed_for_this_send_only(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Cover', 'locale' => 'ar', 'is_active' => true,
            'subject' => 'خطابنا {{reference}}',
            'body' => '<p>السادة/ {{recipient.name}}</p><p>مرفق الخطاب {{reference}}.</p>',
        ]);
        $mona = $this->letter->recipients->first();

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->letter->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $this->letter)
            ->setTableActionData(['sender' => 'iflas', 'email_template_id' => $template->id, 'recipients' => [$mona->id], 'separate' => true])
            // Starts from the template, the letter's details filled in; each
            // recipient's name still to come.
            ->assertTableActionDataSet([
                'subject' => 'خطابنا JPA/2026/986/1',
                'body' => '<p>السادة/ {{recipient.name}}</p><p>مرفق الخطاب JPA/2026/986/1.</p>',
            ])
            // The preview: as it goes to the first recipient.
            ->assertMountedActionModalSee(['السادة/ منى أحمد', 'mona@example.com'])
            ->setTableActionData([
                'subject' => 'خطاب عاجل',
                'body' => '<p>السادة/ {{recipient.name}}</p><p>نص لهذه المرة فقط.</p>',
            ])
            ->assertMountedActionModalSee('نص لهذه المرة فقط.')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertCount(1, $this->sent);
        $this->assertSame('خطاب عاجل', $this->sent[0]->getSubject());
        $this->assertStringContainsString('السادة/ منى أحمد', $this->sent[0]->getHtmlBody());
        $this->assertStringContainsString('نص لهذه المرة فقط.', $this->sent[0]->getHtmlBody());
        // The template stays as it was.
        $this->assertSame('خطابنا {{reference}}', $template->fresh()->subject);
    }

    public function test_the_covering_emails_alignment_goes_out_as_left_and_right(): void
    {
        app(LetterMailer::class)->send($this->letter, 'iflas', LetterMailer::ATTACHMENT, null, ['pdf'], [$this->letter->recipients->first()->id],
            body: '<p style="text-align: start">تحية طيبة</p><p style="text-align: end">المخلص</p>');

        $html = $this->sent[0]->getHtmlBody();
        $this->assertStringContainsString('<p style="text-align: right">تحية طيبة</p><p style="text-align: left">المخلص</p>', $html);
    }

    public function test_the_letter_as_the_email_is_previewed_with_its_signature(): void
    {
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->letter->matter, 'pageClass' => ViewMatter::class])
            ->mountTableAction('email', $this->letter)
            ->setTableActionData(['mode' => LetterMailer::BODY])
            ->assertTableActionDataSet(['subject' => 'JPA/2026/986/1 — إشعار الدعوى رقم 986/2026'])
            ->assertMountedActionModalSee('نص الخطاب.')
            // The signature, inline in the preview.
            ->assertMountedActionModalSeeHtml('data:image/png;base64,');
    }

    public function test_the_email_templates_screen(): void
    {
        $this->get(EmailTemplateResource::getUrl())->assertSuccessful();
    }
}
