<?php

namespace Tests\Feature;

use App\Enums\FeeStatus;
use App\Enums\FeeType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\EmailsRelationManager;
use App\Livewire\MatterHistoryTab;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\User;
use App\Support\MatterEvents;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A matter's History tab: everything done to it and to what is on it — who, when, what changed — found by
 * the matter each activity was logged for, after what it was about is deleted too.
 */
class MatterHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create(['name' => 'المدير'])->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web'])));
        $this->matter = Matter::factory()->create(['number' => '7777', 'year' => '2026']);
    }

    public function test_what_is_done_on_a_matter_is_its_history(): void
    {
        $this->matter->update(['next_session_date' => '2026-11-09']);
        $fee = Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 12000, 'date' => '2026-10-01', 'status' => FeeStatus::UNPAID]);
        Allocation::create(['fee_id' => $fee->id, 'matter_id' => $this->matter->id, 'user_id' => auth()->id(), 'amount' => 6000, 'date' => '2026-10-10', 'description' => 'الدفعة الأولى']);
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => 1, 'meeting_at' => '2026-10-20 10:00:00', 'status' => MatterMinutes::DRAFT]);
        // Typed as it is held: not news.
        $minutes->update(['items' => [['type' => 'question', 'text' => 'سؤال', 'answer' => 'جواب']]]);
        $minutes->update(['status' => MatterMinutes::FINAL]);
        MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::SENT, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas', 'subject' => 'إخطار بموعد المعاينة', 'to' => ['a@b.ae'], 'at' => now()]);
        // Deleted: its history stays.
        $fee->delete();

        $tab = Livewire::test(MatterHistoryTab::class, ['matterId' => $this->matter->id])->assertOk();

        foreach ([
            __('Matter changed'),
            __(':field: from :old to :new', ['field' => __('Next Session Date'), 'old' => '—', 'new' => '09/11/2026']),
            __('Fee added: :what', ['what' => FeeType::EXPERT_FEE->getLabel().' — 12,000.00']),
            __('Collected: :what', ['what' => '6,000.00 — الدفعة الأولى']),
            __('Minutes created: :what', ['what' => __('Minutes no. :number', ['number' => 1])]),
            __('Minutes finalised: :what', ['what' => __('Minutes no. :number', ['number' => 1])]),
            __('Email sent: :what', ['what' => 'إخطار بموعد المعاينة']),
            __(':kind deleted: :what', ['kind' => __('Fee'), 'what' => FeeType::EXPERT_FEE->getLabel().' — 12,000.00']),
            'المدير',
        ] as $text) {
            $tab->assertSee($text);
        }

        // Only what is news: not the minutes' autosave.
        $this->assertSame(2, DB::table('activity_log')->where('subject_type', (new MatterMinutes)->getMorphClass())->count());
        // Another matter's: not here.
        $other = Matter::factory()->create();
        Fee::create(['matter_id' => $other->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 99, 'date' => '2026-10-01', 'status' => FeeStatus::UNPAID]);
        Livewire::test(MatterHistoryTab::class, ['matterId' => $this->matter->id])->assertDontSee('99.00');
    }

    public function test_what_was_logged_before_finds_its_matter(): void
    {
        $fee = Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 500, 'date' => '2026-10-01', 'status' => FeeStatus::UNPAID]);
        $fee->delete();
        // As logged before the column: no matter on them.
        DB::table('activity_log')->update(['matter_id' => null]);

        $migration = require database_path('migrations/2020_01_01_000182_matter_history.php');
        $migration->up();

        $this->assertSame(0, DB::table('activity_log')->whereIn('subject_type', [(new Fee)->getMorphClass(), (new Matter)->getMorphClass()])->whereNull('matter_id')->count());
        $this->assertTrue(auth()->user()->can('View:MatterHistoryTab'));
    }

    public function test_the_matters_tabs_are_drawn_again_when_something_is_done_on_it(): void
    {
        $emails = Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertDontSee('وصل الآن');

        MatterEmail::create(['matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas', 'subject' => 'وصل الآن', 'from' => 'x@y.ae', 'at' => now()]);

        $emails->dispatch(MatterEvents::CHANGED)->assertSee('وصل الآن');
    }
}
