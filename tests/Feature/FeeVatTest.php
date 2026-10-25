<?php

namespace Tests\Feature;

use App\Enums\FeeStatus;
use App\Enums\FeeType;
use App\Filament\Mms\Pages\Reports\VatSummaryReport;
use App\Filament\Mms\Resources\Matters\Pages\EditMatter;
use App\Filament\Mms\Resources\Matters\Pages\ListMatters;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Support\FeeVatFields;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\IncentiveCalculation;
use App\Models\IncentiveLine;
use App\Models\Matter;
use App\Models\MatterTypeIncentiveConfig;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\FeeVatAndStatusFix;
use App\Services\MMS\IncentiveCalculatorService;
use App\Services\MMS\VatRowMerger;
use App\Support\Vat;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fee's VAT on the fee itself: its basic amount, its VAT and its total, split one way (App\Support\Vat) at the
 * rate of Settings → Financial Settings → VAT — entered VAT included for an expert fee unless changed; the
 * incentive on the basic amount only; what is billed and collected its total.
 */
class FeeVatTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::clearCache();
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());
        $this->matter = Matter::factory()->create(['number' => '1200', 'year' => '2026']);
    }

    private function addFee(): TestAction
    {
        return TestAction::make('create_fee')->schemaComponent('feesSection', 'infolist');
    }

    private function page()
    {
        return Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()]);
    }

    public function test_an_expert_fee_is_entered_vat_included_and_any_other_type_without(): void
    {
        $this->assertTrue(FeeType::EXPERT_FEE->vatIncludedByDefault());
        $this->assertFalse(FeeType::OTHER->vatIncludedByDefault());
        // A deduction is not charged VAT at all; nor can a new fee be a VAT row.
        $this->assertFalse(FeeType::OFFICE_SHARE->carriesVat());
        $this->assertArrayNotHasKey(FeeType::VAT->value, FeeType::newFeeOptions());

        // The form follows the type chosen.
        $this->page()->mountAction($this->addFee())
            ->fillForm(['type' => FeeType::EXPERT_FEE->value])->assertSchemaStateSet(['is_vat_included' => true])
            ->fillForm(['type' => FeeType::OTHER->value])->assertSchemaStateSet(['is_vat_included' => false]);
    }

    public function test_included_the_amount_is_the_total_and_excluded_the_basic(): void
    {
        $this->assertSame(['basic' => 1000.0, 'vat' => 50.0, 'total' => 1050.0], Vat::split(1050, included: true));
        $this->assertSame(['basic' => 1000.0, 'vat' => 50.0, 'total' => 1050.0], Vat::split(1000, included: false));
    }

    public function test_rounding_never_makes_the_basic_and_vat_differ_from_the_total(): void
    {
        $this->assertSame(['basic' => 95.25, 'vat' => 4.76, 'total' => 100.01], Vat::split(100.01, included: true));
        $this->assertSame(['basic' => 0.01, 'vat' => 0.0, 'total' => 0.01], Vat::split(0.01, included: false));

        foreach ([0.01, 0.99, 1.05, 33.33, 100.01, 1666.66, 99999.99, 123456.78] as $amount) {
            foreach ([true, false] as $included) {
                $split = Vat::split($amount, $included);
                $this->assertSame((int) round($split['total'] * 100), (int) round($split['basic'] * 100) + (int) round($split['vat'] * 100), "$amount ".($included ? 'incl.' : 'excl.'));
            }
        }

        // The rate is the setting's.
        Setting::set(Vat::SETTING, 10.0, 'financial');
        $this->assertSame(['basic' => 1000.0, 'vat' => 100.0, 'total' => 1100.0], Vat::split(1100, included: true));
    }

    public function test_add_fee_on_a_matter_shows_including_vat_and_saves_it_split(): void
    {
        $this->page()->mountAction($this->addFee())
            ->fillForm(['type' => FeeType::EXPERT_FEE->value, 'amount' => 1050])
            ->assertFormFieldExists('is_vat_included')
            ->assertMountedActionModalSee(__('Including VAT'))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $fee = $this->matter->fees()->sole();
        $this->assertSame([1000.0, 50.0, true, 1050.0], [(float) $fee->amount, (float) $fee->vat, $fee->is_vat_included, $fee->total]);

        // Excluded: the amount is the basic, the VAT added.
        $this->page()->callAction($this->addFee(), ['type' => FeeType::OTHER->value, 'amount' => 1000, 'is_vat_included' => false])->assertHasNoActionErrors();
        $other = $this->matter->fees()->where('type', FeeType::OTHER->value)->sole();
        $this->assertSame([1000.0, 50.0, false], [(float) $other->amount, (float) $other->vat, $other->is_vat_included]);
    }

    public function test_a_fee_of_before_edited_keeps_no_vat_until_its_amount_or_vat_changes(): void
    {
        // As every fee before this change: its amount the basic, no VAT.
        $fee = $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'date' => '2026-01-10']);
        $edit = TestAction::make('editFee')->schemaComponent('fees.0', 'infolist');

        $this->page()->mountAction($edit)
            ->assertActionDataSet(['amount' => 1000.0, 'is_vat_included' => false])
            ->fillForm(['description' => 'Its note only'])
            ->callMountedAction();
        $this->assertSame([1000.0, 0.0], [(float) $fee->refresh()->amount, (float) $fee->vat]);

        // Its amount entered again, VAT included: split.
        $this->page()->callAction($edit, ['amount' => 1050, 'is_vat_included' => true]);
        $this->assertSame([1000.0, 50.0, 1050.0], [(float) $fee->refresh()->amount, (float) $fee->vat, $fee->total]);
        // Shown again as entered: the total.
        $this->assertSame(['amount' => 1050.0, 'is_vat_included' => true], FeeVatFields::fill($fee));
    }

    public function test_the_incentive_is_on_the_basic_amount_only(): void
    {
        $config = MatterTypeIncentiveConfig::create(['name' => 'Fixed 10', 'calculation_type' => 'fixed', 'fixed_percentage' => 10.0, 'assistant_rate' => 100.0]);
        $type = Type::create(['name' => 'Fixed', 'incentive_config_id' => $config->id, 'incentive_trigger_type' => 'final_report_date']);
        $this->matter->update(['type_id' => $type->id]);
        $fee = Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::EXPERT_FEE, ...FeeVatFields::attributes(['type' => FeeType::EXPERT_FEE->value, 'amount' => 10500, 'is_vat_included' => true]), 'date' => '2026-06-15']);
        $calculation = IncentiveCalculation::create(['name' => 'June', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft']);
        IncentiveLine::create(['incentive_calculation_id' => $calculation->id, 'matter_id' => $this->matter->id, 'fee_id' => $fee->id]);

        app(IncentiveCalculatorService::class)->calculate($calculation);

        $line = IncentiveLine::sole();
        $this->assertSame([10000.0, 1000.0], [(float) $line->fee_amount_excl_vat, (float) $line->base_amount]);
    }

    public function test_the_matters_table_shows_what_is_billed_vat_included(): void
    {
        $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'vat' => 50, 'is_vat_included' => true]);
        $this->matter->fees()->create(['type' => FeeType::OFFICE_SHARE, 'amount' => -100]);

        Livewire::test(ListMatters::class)->assertTableColumnStateSet('fees_summary', '950.00', $this->matter);
    }

    public function test_paid_is_its_total_collected_and_the_matter_shows_basic_vat_and_total(): void
    {
        $fee = $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'vat' => 50, 'is_vat_included' => true]);
        Allocation::create(['fee_id' => $fee->id, 'matter_id' => $this->matter->id, 'amount' => 1000, 'date' => '2026-06-01']);
        $fee->refresh()->updateStatus();
        $this->assertSame(FeeStatus::PARTIAL, $fee->status);

        Allocation::create(['fee_id' => $fee->id, 'matter_id' => $this->matter->id, 'amount' => 50, 'date' => '2026-06-02']);
        $fee->refresh()->updateStatus();
        $this->assertSame(FeeStatus::PAID, $fee->status);

        $this->page()->assertSeeText(__('Basic amount'))->assertSeeText(__('VAT amount'))->assertSeeText(__('Total amount'))->assertSeeText('1,050.00');
    }

    public function test_a_vat_row_of_before_merges_into_its_fee_with_its_payments(): void
    {
        $fee = $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1587.30]);
        $row = $this->matter->fees()->create(['type' => FeeType::VAT, 'amount' => 79.36]);
        Allocation::create(['fee_id' => $fee->id, 'matter_id' => $this->matter->id, 'amount' => 1587.30, 'date' => '2026-06-01']);
        Allocation::create(['fee_id' => $row->id, 'matter_id' => $this->matter->id, 'amount' => 79.36, 'date' => '2026-06-01']);
        // One not 5% of any fee: left for a person.
        $other = Matter::factory()->create();
        $other->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000]);
        $odd = $other->fees()->create(['type' => FeeType::VAT, 'amount' => 70]);

        $plan = app(VatRowMerger::class)->plan();
        $this->assertSame([$row->id], $plan['merge']->pluck('vat.id')->all());
        $this->assertSame([$odd->id], $plan['review']->pluck('vat.id')->all());
        // Listing changes nothing.
        $this->assertNotNull(Fee::find($row->id));

        $this->assertSame(1, app(VatRowMerger::class)->apply());

        $fee->refresh();
        // The basic as it was (and so the incentive); the VAT and the payments on it; paid in full.
        $this->assertSame([1587.30, 79.36, true, 1666.66, 2, FeeStatus::PAID], [(float) $fee->amount, (float) $fee->vat, $fee->is_vat_included, $fee->total, $fee->allocations()->count(), $fee->status]);
        $this->assertNull(Fee::find($row->id));
        $this->assertNotNull(Fee::find($odd->id));
    }

    public function test_merging_reads_as_much_for_many_pairs_as_for_one(): void
    {
        $pair = function (): void {
            $matter = Matter::factory()->create();
            $fee = $matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000]);
            $row = $matter->fees()->create(['type' => FeeType::VAT, 'amount' => 50]);
            Allocation::create(['fee_id' => $row->id, 'matter_id' => $matter->id, 'amount' => 50, 'date' => '2026-06-01']);
        };
        // The reads (SELECTs) of one merge; the writes are each pair's own.
        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            $selects += str_starts_with(strtolower(ltrim($query->sql)), 'select') ? 1 : 0;
        });
        $reads = function () use (&$selects): int {
            $selects = 0;
            app(VatRowMerger::class)->apply();

            return $selects;
        };

        $pair();
        $one = $reads();
        $pair();
        $pair();
        $pair();

        // Every fee, its payments and its matter read at once: not once a pair.
        $this->assertSame($one, $reads());
        $this->assertSame(0, Fee::where('type', FeeType::VAT->value)->count());
    }

    public function test_the_fix_previews_then_merges_and_fixes_statuses_with_a_log_of_each_change(): void
    {
        Storage::fake('local');
        $enteredBy = User::factory()->create();
        $fee = $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000]);
        $row = $this->matter->fees()->create(['type' => FeeType::VAT, 'amount' => 50]);
        Allocation::create(['fee_id' => $fee->id, 'matter_id' => $this->matter->id, 'amount' => 1000, 'date' => '2026-06-01']);
        Allocation::create(['fee_id' => $row->id, 'matter_id' => $this->matter->id, 'amount' => 50, 'date' => '2026-06-01']);
        // A status out of date: a fee fully paid, still marked unpaid.
        $paid = $this->matter->fees()->create(['type' => FeeType::OTHER, 'amount' => 300]);
        Allocation::create(['fee_id' => $paid->id, 'matter_id' => $this->matter->id, 'amount' => 300, 'date' => '2026-06-01']);
        DB::table('fees')->where('id', $paid->id)->update(['status' => FeeStatus::UNPAID->value]);
        // Who entered it (a payment saved the fee as whoever recorded it — set after them).
        DB::table('fees')->where('id', $fee->id)->update(['user_id' => $enteredBy->id]);

        $fix = app(FeeVatAndStatusFix::class);

        // A preview: nothing changed; its log says what would.
        $preview = $fix->run(apply: false);
        $this->assertSame([1, 0, 1], [$preview['merged'], $preview['review'], $preview['fees']]);
        $this->assertNotNull(Fee::find($row->id));
        $this->assertSame(FeeStatus::UNPAID, $paid->fresh()->status);
        $this->assertStringContainsString('Preview — nothing changed', Storage::disk('local')->get($preview['file']));

        $applied = $fix->run(apply: true);
        $log = Storage::disk('local')->get($applied['file']);

        $this->assertNull(Fee::find($row->id));
        $this->assertSame([50.0, FeeStatus::PAID, $enteredBy->id], [(float) $fee->fresh()->vat, $fee->fresh()->status, $fee->fresh()->user_id]);
        $this->assertSame(FeeStatus::PAID, $paid->fresh()->status);
        // Each change, line by line.
        $this->assertStringContainsString("Matter 1200/2026 — fee #{$fee->id}: basic 1,000.00 + VAT 50.00 = 1,050.00 (VAT row #{$row->id} removed, 1 payment(s) moved to the fee)", $log);
        $this->assertStringContainsString("Fee #{$paid->id} (matter 1200/2026, ".FeeType::OTHER->getLabel().'): unpaid → paid — total 300.00, collected 300.00', $log);

        // Run again: nothing left to do.
        $again = $fix->run(apply: true);
        $this->assertSame([0, 0, 0], [$again['merged'], $again['fees'], $again['matters']]);
    }

    public function test_the_vat_summary_reads_each_fees_own_vat_and_the_rows_of_before(): void
    {
        $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'vat' => 50, 'is_vat_included' => true, 'date' => now()]);
        $this->matter->fees()->create(['type' => FeeType::VAT, 'amount' => 30, 'date' => now()]);
        $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 500, 'date' => now()]);

        Livewire::test(VatSummaryReport::class)
            ->assertCanSeeTableRecords(Fee::query()->where('type', FeeType::VAT->value)->orWhere('vat', '>', 0)->get())
            ->assertCountTableRecords(2);
    }

    public function test_the_matter_forms_fees_save_split_and_show_as_entered(): void
    {
        $this->matter->update(['level' => 'first_instance', 'next_session_date' => now()->addWeek()]);
        $this->matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'vat' => 50, 'is_vat_included' => true]);

        $page = Livewire::test(EditMatter::class, ['record' => $this->matter->getRouteKey()]);
        $fees = $page->get('data.fees');
        $this->assertSame([1050.0, true], [(float) reset($fees)['amount'], (bool) reset($fees)['is_vat_included']]);

        $key = array_key_first($fees);
        $page->set("data.fees.$key.amount", 2100)->call('save')->assertHasNoFormErrors();

        $fee = $this->matter->fees()->sole();
        $this->assertSame([2000.0, 100.0, true], [(float) $fee->amount, (float) $fee->vat, $fee->is_vat_included]);
    }
}
