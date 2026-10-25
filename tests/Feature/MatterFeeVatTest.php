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
 * A fee entered "including VAT" on the matter form is one fee carrying its VAT — its basic amount and its VAT
 * on it — never a fee and a VAT row beside it, as before.
 */
class MatterFeeVatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fee_including_vat_carries_its_vat_and_adds_no_vat_row(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel('mms');
        $this->actingAs(User::factory()->create());

        $matter = Matter::factory()->create();

        $page = Livewire::test(EditMatter::class, ['record' => $matter->getRouteKey()])
            ->set('data.fees', ['first' => ['type' => FeeType::EXPERT_FEE->value, 'amount' => 1050, 'is_vat_included' => true]]);

        // Still one row, as entered.
        $this->assertCount(1, $page->get('data.fees'));

        // Fields the form requires, unrelated to fees.
        $page->set('data.next_session_date', now()->addWeek()->toDateString())
            ->set('data.level', MatterLevel::FIRST_INSTANCE->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $fee = Fee::where('matter_id', $matter->id)->sole();
        $this->assertSame([FeeType::EXPERT_FEE, 1000.0, 50.0, true, 1050.0], [$fee->type, (float) $fee->amount, (float) $fee->vat, $fee->is_vat_included, $fee->total]);
        $this->assertSame(0, Fee::where('type', FeeType::VAT->value)->count());
    }
}
