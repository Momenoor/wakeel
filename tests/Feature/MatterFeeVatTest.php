<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\MatterLevel;
use App\Filament\Mms\Resources\Matters\Pages\EditMatter;
use App\Models\Fee;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fee entered "including VAT" splits into the fee and a VAT row — typed
 * as VAT, so it is saved and counted as VAT.
 */
class MatterFeeVatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fee_including_vat_adds_a_vat_row_with_the_vat_type(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $matter = Matter::factory()->create();

        $page = Livewire::test(EditMatter::class, ['record' => $matter->getRouteKey()])
            ->set('data.fees', ['first' => [
                'type' => FeeType::EXPERT_FEE->value,
                'amount' => 1050,
                'including_vat' => false,
                'row_id' => 'row-1',
            ]])
            ->set('data.fees.first.including_vat', true);

        $rows = array_values($page->get('data.fees'));

        $this->assertCount(2, $rows);
        $this->assertEquals(1000, $rows[0]['amount']);
        $this->assertTrue(FeeType::isVat($rows[1]['type']));
        $this->assertEquals(50, $rows[1]['amount']);

        // Fields the form requires, unrelated to fees.
        $page->set('data.next_session_date', now()->addWeek()->toDateString())
            ->set('data.level', MatterLevel::FIRST_INSTANCE->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $vat = Fee::where('matter_id', $matter->id)->where('type', FeeType::VAT->value)->get();

        $this->assertCount(1, $vat);
        $this->assertEquals(50, $vat->first()->amount);
    }

    public function test_is_vat_reads_the_enum_or_its_value(): void
    {
        $this->assertTrue(FeeType::isVat(FeeType::VAT));
        $this->assertTrue(FeeType::isVat('VAT'));
        $this->assertFalse(FeeType::isVat('vat'));
        $this->assertFalse(FeeType::isVat(FeeType::EXPERT_FEE));
        $this->assertFalse(FeeType::isVat(null));
    }
}
