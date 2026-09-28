<?php

namespace Tests\Feature\PMS;

use App\Enums\PMS\QuotationStatus;
use App\Filament\Pms\Resources\Leases\Pages\CreateLease;
use App\Filament\Pms\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Pms\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Pms\Resources\Quotations\Pages\ViewQuotation;
use App\Models\Party;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\PMS\QuotationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuotationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        Filament::setCurrentPanel(Filament::getPanel('pms'));
    }

    public function test_creating_a_quotation_through_the_form_computes_totals_via_the_service(): void
    {
        $tenant = Party::factory()->tenant()->create();
        $unit = Unit::factory()->commercial()->create();

        Livewire::test(CreateQuotation::class)
            ->fillForm([
                'party_id' => $tenant->id,
                'validity_date' => now()->addDays(10)->toDateString(),
                'security_deposit' => 5000,
                'number_of_installments' => 2,
                'units' => [
                    ['unit_id' => $unit->id, 'offered_rent' => 100000],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $quotation = Quotation::where('party_id', $tenant->id)->sole();
        $this->assertSame('100000.00', $quotation->base_rent);
        $this->assertSame('5000.00', $quotation->vat_amount);
        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
    }

    public function test_a_quotation_can_be_edited_and_is_repriced(): void
    {
        $tenant = Party::factory()->tenant()->create();
        $flat = Unit::factory()->residential()->create();
        $shop = Unit::factory()->commercial()->create();

        $quotation = app(QuotationService::class)->generate([
            'party_id' => $tenant->id,
            'units' => [['unit_id' => $flat->id, 'offered_rent' => 60000]],
            'start_date' => '2026-06-21',
            'validity_date' => now()->addDays(10)->toDateString(),
        ]);
        app(QuotationService::class)->send($quotation);

        Livewire::test(EditQuotation::class, ['record' => $quotation->getKey()])
            ->assertSchemaStateSet(['start_date' => '2026-06-21'])
            ->fillForm([
                'units' => [['unit_id' => $shop->id, 'offered_rent' => 100000]],
                'number_of_installments' => 4,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $quotation->refresh();
        $this->assertSame('100000.00', $quotation->base_rent);
        $this->assertSame('5000.00', $quotation->vat_amount);
        $this->assertSame(4, $quotation->number_of_installments);
        $this->assertSame([$shop->id], $quotation->units->pluck('id')->all());
        $this->assertSame(QuotationStatus::SENT, $quotation->status);
    }

    public function test_expected_instalment_dates_can_be_changed_and_carry_into_the_lease(): void
    {
        $tenant = Party::factory()->tenant()->create();
        $unit = Unit::factory()->residential()->create();

        $component = Livewire::test(CreateQuotation::class)
            ->fillForm([
                'party_id' => $tenant->id,
                'validity_date' => now()->addDays(10)->toDateString(),
                'start_date' => '2026-06-21',
                'security_deposit' => 5000,
                'units' => [['unit_id' => $unit->id, 'offered_rent' => 60000]],
            ])
            ->fillForm(['number_of_installments' => 2]);

        $schedule = $component->instance()->data['schedule'];
        $this->assertSame(['2026-06-21', '2026-12-21', '2026-06-21'], array_column(array_values($schedule), 'due_date'));

        // The second rent cheque and the deposit moved by hand.
        [$first, $second, $deposit] = array_keys($schedule);
        $component
            ->set("data.schedule.{$second}.due_date", '2027-01-05')
            ->set("data.schedule.{$deposit}.due_date", '2026-06-01')
            ->call('create')
            ->assertHasNoFormErrors();

        $quotation = Quotation::sole();
        $this->assertSame(['2026-06-21', '2027-01-05', '2026-06-01'], $quotation->installment_dates);
        $this->assertSame(
            ['2026-06-21', '2027-01-05', '2026-06-01'],
            array_map(fn ($row) => $row['due_date']->toDateString(), app(QuotationService::class)->expectedInstallments($quotation)),
        );

        // Kept on the edit page, and in the lease wizard.
        Livewire::test(EditQuotation::class, ['record' => $quotation->getKey()])
            ->assertSet('data.schedule', fn ($rows) => array_column(array_values($rows), 'due_date') === ['2026-06-21', '2027-01-05', '2026-06-01']);

        $data = Livewire::withQueryParams(['quotation' => $quotation->getKey()])->test(CreateLease::class)->instance()->data;
        $this->assertSame(['2026-06-21', '2027-01-05'], array_column(array_values($data['installments']), 'payment_date'));
        $this->assertSame('2026-06-01', $data['deposit_payment']['payment_date']);
    }

    public function test_the_printout_shows_the_building_and_its_lessor(): void
    {
        $unit = Unit::factory()->residential()->create();
        $unit->property->update(['name' => 'Al Noor Tower']);
        $unit->property->owners()->attach(Party::factory()->owner()->create(['name' => 'Khalid Owner'])->id, ['ownership_percentage' => 100]);

        $quotation = app(QuotationService::class)->generate([
            'party_id' => Party::factory()->tenant()->create()->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 60000]],
            'validity_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->get(route('pms.quotations.print', $quotation))
            ->assertOk()
            ->assertSeeInOrder(['Building Name', 'Al Noor Tower', 'Lessor Name', 'Khalid Owner']);
    }

    public function test_an_accepted_quotation_can_no_longer_be_edited(): void
    {
        $quotation = app(QuotationService::class)->generate([
            'party_id' => Party::factory()->tenant()->create()->id,
            'units' => [['unit_id' => Unit::factory()->residential()->create()->id, 'offered_rent' => 60000]],
            'validity_date' => now()->addDays(10)->toDateString(),
        ]);
        app(QuotationService::class)->send($quotation);
        app(QuotationService::class)->accept($quotation);

        Livewire::test(ViewQuotation::class, ['record' => $quotation->getKey()])
            ->assertActionHidden('edit');

        $this->expectException(\RuntimeException::class);
        app(QuotationService::class)->update($quotation, ['party_id' => $quotation->party_id, 'units' => [], 'validity_date' => now()->toDateString()]);
    }

    public function test_the_view_page_walks_a_quotation_from_draft_to_accepted(): void
    {
        $tenant = Party::factory()->tenant()->create();
        $unit = Unit::factory()->residential()->create();

        $quotation = app(QuotationService::class)->generate([
            'party_id' => $tenant->id,
            'units' => [['unit_id' => $unit->id, 'offered_rent' => 60000]],
            'validity_date' => now()->addDays(10)->toDateString(),
        ]);

        $component = Livewire::test(ViewQuotation::class, ['record' => $quotation->getKey()]);

        $component->callAction('send');
        $this->assertSame(QuotationStatus::SENT, $quotation->fresh()->status);

        $component->callAction('accept');
        $this->assertSame(QuotationStatus::ACCEPTED, $quotation->fresh()->status);
    }
}
