<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Mms\Pages\Reports\MatterExpensesReport;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Support\FeeVatFields;
use App\Models\Allocation;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter's expenses: what the client gives for its procedures is a fee of type "Matter expenses" — no VAT, no
 * incentive, not revenue — collected like any fee; what is paid out of it is recorded on the matter with its
 * receipt; the balance is what was received less what was paid out, below zero when paid out first.
 */
class MatterExpensesTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::clearCache();
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create(['name' => 'Accountant']));
        $this->matter = Matter::factory()->create(['number' => '1300', 'year' => '2026']);
    }

    private function page()
    {
        return Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()]);
    }

    public function test_the_expenses_fee_carries_no_vat_and_is_neither_incentive_nor_revenue(): void
    {
        $this->assertFalse(FeeType::MATTER_EXPENSES->carriesVat());
        $this->assertTrue(FeeType::MATTER_EXPENSES->isPassThrough());
        $this->assertFalse(FeeType::MATTER_EXPENSES->isNegative());
        $this->assertContains(FeeType::MATTER_EXPENSES->value, FeeType::excludedFromIncentiveValues());
        $this->assertArrayHasKey(FeeType::MATTER_EXPENSES->value, FeeType::newFeeOptions());

        // Added on the matter: no VAT asked, none kept — whatever is typed.
        $this->page()->mountAction(TestAction::make('create_fee')->schemaComponent('feesSection', 'infolist'))
            ->fillForm(['type' => FeeType::MATTER_EXPENSES->value, 'amount' => 2000])
            ->assertFormFieldHidden('is_vat_included')
            ->assertFormFieldHidden('is_vat_exempt')
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $fee = $this->matter->fees()->sole();
        $this->assertSame([2000.0, 0.0, 2000.0], [(float) $fee->amount, (float) $fee->vat, $fee->total]);
        // Passed on, not the office's fee: no credit note on it.
        $this->page()->assertDontSee(__('Credit note'));
        $this->assertSame(['amount' => 2000.0, 'vat' => 0.0, 'is_vat_included' => false, 'is_vat_exempt' => false],
            FeeVatFields::attributes(['type' => FeeType::MATTER_EXPENSES->value, 'amount' => 2000, 'is_vat_included' => true]));

        // Its payments are not revenue collected.
        $revenue = Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'date' => '2026-06-01']);
        Allocation::create(['matter_id' => $this->matter->id, 'fee_id' => $fee->id, 'amount' => 2000, 'date' => '2026-06-02']);
        Allocation::create(['matter_id' => $this->matter->id, 'fee_id' => $revenue->id, 'amount' => 1000, 'date' => '2026-06-02']);
        $this->assertSame(1000.0, (float) Allocation::query()->exclVat()->sum(Allocation::exclVatAmount()));
    }

    public function test_paid_out_before_received_the_balance_is_to_collect_then_settles(): void
    {
        // Paid out first: the court fee, its receipt with it.
        $this->page()->callAction(TestAction::make('addExpense')->schemaComponent('expensesSection', 'infolist'), [
            'amount' => 1800, 'date' => '2026-06-03', 'payee' => 'Dubai Courts', 'description' => 'Court fee',
        ])->assertHasNoActionErrors();

        $expense = MatterExpense::sole();
        $this->assertSame([1800.0, 'Dubai Courts', 'Court fee', auth()->id()], [(float) $expense->amount, $expense->payee, $expense->description, $expense->user_id]);
        $this->assertSame(['charged' => 0.0, 'received' => 0.0, 'spent' => 1800.0, 'balance' => -1800.0], $this->matter->fresh()->expensesSummary());

        // Charged to the client and collected in part, then in full.
        $fee = Fee::create(['matter_id' => $this->matter->id, 'type' => FeeType::MATTER_EXPENSES, 'amount' => 2000, 'date' => '2026-06-04']);
        Allocation::create(['matter_id' => $this->matter->id, 'fee_id' => $fee->id, 'amount' => 1500, 'date' => '2026-06-05']);
        $this->assertSame(['charged' => 2000.0, 'received' => 1500.0, 'spent' => 1800.0, 'balance' => -300.0], $this->matter->fresh()->expensesSummary());

        Allocation::create(['matter_id' => $this->matter->id, 'fee_id' => $fee->id, 'amount' => 500, 'date' => '2026-06-06']);
        $this->assertSame(200.0, $this->matter->fresh()->expensesSummary()['balance']);

        // Shown on the matter, the balance said for what it is.
        $this->page()
            ->assertSee(__('Matter expenses'))
            ->assertSee('Dubai Courts')
            ->assertSee(__('Received and not paid out yet: to spend, or to refund to the client.'));

        // Changed, then deleted.
        $this->page()->callAction(TestAction::make('editExpense')->schemaComponent('expenses.0', 'infolist'), ['amount' => 2000, 'date' => '2026-06-03', 'payee' => 'Dubai Courts', 'description' => 'Court fee'])
            ->assertHasNoActionErrors();
        $this->assertSame(0.0, $this->matter->fresh()->expensesSummary()['balance']);

        $this->page()->callAction(TestAction::make('deleteExpense')->schemaComponent('expenses.0', 'infolist'));
        $this->assertSame(0, MatterExpense::count());
    }

    public function test_the_report_lists_each_matters_expenses_and_its_balance(): void
    {
        $other = Matter::factory()->create(['number' => '1301', 'year' => '2026']);
        $untouched = Matter::factory()->create(['number' => '1302', 'year' => '2026']);
        Fee::create(['matter_id' => $untouched->id, 'type' => FeeType::EXPERT_FEE, 'amount' => 5000, 'date' => '2026-06-01']);

        // 1300: paid out 700 before anything was received — to collect.
        MatterExpense::create(['matter_id' => $this->matter->id, 'amount' => 700, 'date' => '2026-06-03']);
        // 1301: received 1,000, paid out 400 — to spend or refund.
        $fee = Fee::create(['matter_id' => $other->id, 'type' => FeeType::MATTER_EXPENSES, 'amount' => 1000, 'date' => '2026-06-01']);
        Allocation::create(['matter_id' => $other->id, 'fee_id' => $fee->id, 'amount' => 1000, 'date' => '2026-06-02']);
        MatterExpense::create(['matter_id' => $other->id, 'amount' => 400, 'date' => '2026-06-04']);

        Livewire::test(MatterExpensesReport::class)
            ->assertCanSeeTableRecords([$this->matter, $other])
            ->assertCanNotSeeTableRecords([$untouched])
            ->assertTableColumnStateSet('expenses_balance', -700, $this->matter)
            ->assertTableColumnStateSet('expenses_balance', 600, $other)
            ->filterTable('balance', 'collect')
            ->assertCanSeeTableRecords([$this->matter])
            ->assertCanNotSeeTableRecords([$other]);
    }
}
