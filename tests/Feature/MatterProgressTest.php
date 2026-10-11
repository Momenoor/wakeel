<?php

namespace Tests\Feature;

use App\Enums\ProgressType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\ProgressRelationManager;
use App\Models\BulkMailCampaign;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\MatterProgress;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use App\Services\MMS\Letters\LetterMailer;
use App\Services\MMS\Letters\MinutesSender;
use App\Services\MMS\Letters\MinutesService;
use App\Services\MMS\MatterProgressRecorder;
use App\Services\MMS\SentFolder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A matter's progress: the steps Wakeel records as they happen — letters
 * issued and sent, meetings and their minutes, emails to the parties, the
 * reports — and those added by hand.
 */
class MatterProgressTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutDefer();
        config([
            'mail.mailers.smtp.transport' => 'array',
            'mail_senders.senders.iflas' => [
                'username' => 'iflas@jpaemirates.com', 'address' => 'iflas@jpaemirates.com', 'name' => 'JPA Iflas',
                'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
            ],
        ]);
        $this->app->instance(SentFolder::class, Mockery::spy(SentFolder::class));

        $this->user = User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        );
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');

        Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        return $this->matter->progress()->orderBy('id')->get()->map(fn (MatterProgress $p) => $p->type->value)->all();
    }

    private function issueLetter(): MatterLetter
    {
        $template = LetterTemplate::create([
            'name' => 'إشعار', 'slug' => 'notice', 'locale' => 'ar', 'category' => 'letter',
            'subject' => 'إشعار الدعوى رقم {{matter.reference}}', 'body' => '<p>{{recipients}}</p><p>نص الخطاب.</p>',
        ]);

        return app(LetterIssuer::class)->issue($template, $this->matter, [
            ['name' => 'منى أحمد', 'role' => 'المدعي', 'emails' => ['mona@example.com']],
            ['name' => 'شركة خارجية', 'role' => null, 'emails' => ['info@external.ae']],
            ['name' => 'بدون بريد', 'role' => null, 'emails' => []],
        ], [], now()->setDate(2026, 10, 1)->startOfDay(), userId: $this->user->id);
    }

    public function test_a_letter_is_a_step_once_sent_not_when_issued(): void
    {
        $letter = $this->issueLetter();
        app(LetterIssuer::class)->revise($letter, [['name' => 'منى أحمد', 'role' => 'المدعي', 'emails' => ['mona@example.com']], ['name' => 'شركة خارجية', 'role' => null, 'emails' => ['info@external.ae']]], now()->setDate(2026, 10, 5), null, null);
        $this->assertSame([], $this->types());

        // Sent: how, and to those with an email — a party and an outside one.
        app(LetterMailer::class)->send($letter, 'iflas');
        $sent = $this->matter->progress()->sole();
        $this->assertSame(ProgressType::LETTER_SENT, $sent->type);
        $this->assertSame('إشعار الدعوى رقم 986/2026', $sent->title);
        $this->assertSame(__('By email').' — '.$letter->reference.' — منى أحمد، شركة خارجية', $sent->details);
        $this->assertTrue($sent->isAutomatic());
        $this->assertSame($this->user->id, $sent->user_id);

        // Sent again: another step.
        app(LetterMailer::class)->send($letter, 'iflas');
        $this->assertSame(['letter_sent', 'letter_sent'], $this->types());
    }

    public function test_a_meeting_held_and_its_minutes_sent_are_steps(): void
    {
        $minutes = MatterMinutes::create([
            'matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 2, 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT,
            'attendees' => [
                ['present' => true, 'name' => 'محمد عبد المقصود'],
                ['present' => false, 'name' => 'غائب'],
            ],
        ]);

        app(MinutesService::class)->finalise($minutes, $this->user->id);
        // Finalised again (reopened): the same step.
        app(MinutesService::class)->finalise($minutes->fresh(), $this->user->id);

        $held = $this->matter->progress()->sole();
        $this->assertSame(ProgressType::MEETING_HELD, $held->type);
        $this->assertSame(__('Minutes no. :number', ['number' => 2]), $held->title);
        $this->assertSame('2026-09-30 16:00', $held->happened_at->format('Y-m-d H:i'));
        $this->assertSame('محمد عبد المقصود', $held->details);

        app(MinutesSender::class)->send($minutes->fresh(), [
            ['name' => 'الأستاذ/ محمد عبد المقصود', 'emails' => ['m@law.ae'], 'by_email' => true],
            ['name' => 'لم يُرسل له', 'emails' => [], 'by_email' => true],
        ], 'iflas', userId: $this->user->id);

        $sent = $this->matter->progress()->where('type', ProgressType::MINUTES_SENT)->sole();
        $this->assertSame(__('By email').' — الأستاذ/ محمد عبد المقصود', $sent->details);
    }

    public function test_an_email_to_the_parties_is_one_step_however_many_it_reaches(): void
    {
        $campaign = BulkMailCampaign::create(['name' => 'Notice', 'subject' => 'إخطار الأطراف', 'body' => '<p>…</p>',
            'from_sender_key' => 'iflas', 'created_by' => $this->user->id, 'matter_id' => $this->matter->id, 'sent_count' => 1]);

        MatterProgressRecorder::emailSent($campaign);
        $campaign->update(['sent_count' => 3]);
        MatterProgressRecorder::emailSent($campaign);

        $step = $this->matter->progress()->sole();
        $this->assertSame(ProgressType::EMAIL_SENT, $step->type);
        $this->assertSame('إخطار الأطراف', $step->title);
        $this->assertSame(__('To :count recipients', ['count' => 3]), $step->details);
    }

    public function test_the_reports_dates_are_steps_that_follow_them(): void
    {
        $this->matter->update(['initial_report_at' => '2026-10-02 00:00:00']);
        $this->assertSame(['initial_report'], $this->types());

        $this->matter->update(['initial_report_at' => '2026-10-03 00:00:00', 'final_report_at' => '2026-10-20 00:00:00']);
        $this->assertSame(['initial_report', 'final_report'], $this->types());
        $this->assertSame('2026-10-03', $this->matter->progress()->where('type', ProgressType::INITIAL_REPORT)->sole()->happened_at->toDateString());

        // The date taken away: its step with it.
        $this->matter->update(['final_report_at' => null]);
        $this->assertSame(['initial_report'], $this->types());
    }

    public function test_steps_are_added_and_changed_by_hand_on_the_progress_tab(): void
    {
        $page = Livewire::test(ProgressRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);

        $page->mountTableAction(CreateAction::class)
            // The type's name, until one is written.
            ->set('mountedActions.0.data.type', ProgressType::DOCUMENTS_RECEIVED->value)
            ->assertSet('mountedActions.0.data.title', ProgressType::DOCUMENTS_RECEIVED->getLabel())
            ->set('mountedActions.0.data.title', 'استلام مستندات من المدعي')
            ->set('mountedActions.0.data.happened_at', '2026-10-04 11:00:00')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $step = $this->matter->progress()->sole();
        $this->assertSame('استلام مستندات من المدعي', $step->title);
        $this->assertFalse($step->isAutomatic());
        $this->assertSame($this->user->id, $step->user_id);

        $page->callAction(TestAction::make(EditAction::class)->table($step), ['details' => 'كشوف الحساب 2024'])
            ->assertHasNoFormErrors();
        $this->assertSame('كشوف الحساب 2024', $step->fresh()->details);

        $page->assertCanSeeTableRecords([$step])
            ->callAction(TestAction::make(DeleteAction::class)->table($step));
        $this->assertSame(0, $this->matter->progress()->count());
    }

    public function test_what_matters_already_have_is_filled_in_once(): void
    {
        $letter = $this->issueLetter();
        app(LetterMailer::class)->send($letter, 'iflas');
        $this->matter->update(['final_report_at' => '2026-10-20 00:00:00']);
        // As before there was progress.
        MatterProgress::query()->delete();

        $this->assertEquals(['letter_sent' => 1, 'final_report' => 1], MatterProgressRecorder::backfill());
        $this->assertSame([], MatterProgressRecorder::backfill());

        $sent = $this->matter->progress()->where('type', ProgressType::LETTER_SENT)->sole();
        $this->assertSame($letter->fresh()->sent_at->toDateTimeString(), $sent->happened_at->toDateTimeString());
        $this->assertStringContainsString('منى أحمد، شركة خارجية', $sent->details);
    }
}
