<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\MatterCollectionStatus;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter's fee on its page: changed — its date and description
 * optional, its date saved — and dated back to a payment made before it.
 */
class MatterFeeEditTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private Fee $fee;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $this->matter = Matter::factory()->create();
        $this->fee = $this->matter->fees()->create(['type' => FeeType::cases()[0], 'amount' => 1000, 'date' => '2026-01-10', 'description' => 'Old']);
    }

    /** A button on the fee's row of the page (the first fee). */
    private function action(string $name): TestAction
    {
        return TestAction::make($name)->schemaComponent('fees.0', 'infolist');
    }

    private function page()
    {
        return Livewire::test(ViewMatter::class, ['record' => $this->matter->getRouteKey()]);
    }

    public function test_a_fees_date_and_description_are_changed_and_neither_is_required(): void
    {
        $this->page()
            ->mountAction($this->action('editFee'))
            ->assertActionDataSet(fn (array $data) => str_starts_with((string) $data['date'], '2026-01-10') && $data['description'] === 'Old')
            ->fillForm(['amount' => 1500, 'date' => '2026-03-05', 'description' => null])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->fee->refresh();
        $this->assertSame([1500.0, '2026-03-05', null], [(float) $this->fee->amount, $this->fee->date->toDateString(), $this->fee->description]);

        // No date given: today's, not an error.
        $this->page()->callAction($this->action('editFee'), ['amount' => 1500, 'date' => null, 'description' => null])->assertHasNoActionErrors();
        $this->assertSame(today()->toDateString(), $this->fee->refresh()->date->toDateString());
    }

    public function test_a_payment_before_the_fees_date_dates_the_fee_from_it_and_says_so(): void
    {
        $this->page()
            ->callAction($this->action('collect_fee'), ['amount' => 400, 'date' => '2026-01-02', 'description' => null])
            ->assertHasNoActionErrors()
            ->assertNotified(__("The fee's date was updated"));

        $this->assertSame('2026-01-02', $this->fee->refresh()->date->toDateString());

        // A payment after it: the fee's date stays.
        $this->page()->callAction($this->action('collect_fee'), ['amount' => 100, 'date' => '2026-02-01', 'description' => null]);
        $this->assertSame('2026-01-02', $this->fee->refresh()->date->toDateString());
    }

    public function test_a_fee_of_a_deleted_matter_is_collected(): void
    {
        // Open to whoever may see deleted matters (Gate::before here).
        $this->matter->delete();

        $this->page()
            ->callAction($this->action('collect_fee'), ['amount' => 1000, 'date' => '2026-01-20', 'description' => null])
            ->assertHasNoActionErrors();

        $this->assertSame(1000.0, (float) $this->fee->allocations()->sum('amount'));
        $this->assertSame(MatterCollectionStatus::PAID, Matter::withTrashed()->find($this->matter->id)->collection_status);
    }
}
