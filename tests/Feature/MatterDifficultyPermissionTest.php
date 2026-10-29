<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\EditMatter;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A matter's difficulty (it weighs on the incentives) and whether it is office work: set when it is made, changed
 * after only with a permission of their own — not by whoever may edit the matter.
 */
class MatterDifficultyPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = ['UpdateDifficulty:Matter', 'UpdateOfficeWork:Matter'];

    private User $user;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        // Everything else allowed; these two as the user's permissions have them.
        Gate::before(fn ($user, string $ability) => in_array($ability, self::OWN, true) ? null : true);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->matter = Matter::factory()->create(['level' => 'first_instance', 'difficulty' => 'easy', 'is_office_work' => false, 'next_session_date' => now()->addWeek()]);
    }

    public function test_whoever_may_edit_the_matter_cannot_change_them_without_their_permissions(): void
    {
        Livewire::test(EditMatter::class, ['record' => $this->matter->getRouteKey()])
            ->assertFormFieldDisabled('difficulty')
            ->assertFormFieldDisabled('is_office_work')
            ->set('data.difficulty', 'hard')
            ->set('data.is_office_work', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['easy', false], [$this->matter->fresh()->difficulty->value, (bool) $this->matter->fresh()->is_office_work]);
    }

    public function test_with_their_permissions_they_are_changed(): void
    {
        $this->user->givePermissionTo(self::OWN);

        Livewire::test(EditMatter::class, ['record' => $this->matter->getRouteKey()])
            ->assertFormFieldEnabled('difficulty')
            ->fillForm(['difficulty' => 'hard', 'is_office_work' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['hard', true], [$this->matter->fresh()->difficulty->value, (bool) $this->matter->fresh()->is_office_work]);
    }
}
