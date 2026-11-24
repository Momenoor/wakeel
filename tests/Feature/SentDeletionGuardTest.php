<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Mms\Resources\Matters\RelationManagers\MinutesRelationManager;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterLetter;
use App\Models\MatterMinutes;
use App\Models\MinutesDelivery;
use App\Models\User;
use App\Services\MMS\Letters\LetterIssuer;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A letter or minutes already sent is deleted by a super admin only; anyone
 * else who can change the matter deletes it only while unsent.
 */
class SentDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        // Whoever these tests act as may change the matter.
        Gate::before(fn () => true);
        Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026']);
    }

    private function letter(bool $sent): MatterLetter
    {
        $letter = app(LetterIssuer::class)->issue(
            LetterTemplate::query()->firstOrCreate(['slug' => 'n'], ['name' => 'n', 'locale' => 'ar', 'category' => 'letter', 'subject' => 's', 'body' => '<p>x</p>']),
            $this->matter, [['name' => 'منى', 'role' => null, 'emails' => ['mona@example.com']]], [],
        );

        return $sent ? tap($letter)->update(['sent_at' => now()]) : $letter;
    }

    private function minutes(bool $sent): MatterMinutes
    {
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->where('category', 'minutes')->value('id'),
            'number' => MatterMinutes::nextNumber($this->matter), 'meeting_at' => '2026-09-30 16:00:00', 'status' => MatterMinutes::DRAFT]);

        if ($sent) {
            $minutes->deliveries()->create(['name' => 'منى', 'channel' => MinutesDelivery::WHATSAPP, 'address' => '971500000000', 'status' => MinutesDelivery::SENT, 'sent_at' => now()]);
        }

        return $minutes;
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']));
    }

    public function test_a_sent_letter_is_deleted_by_a_super_admin_only(): void
    {
        $sent = $this->letter(sent: true);
        $unsent = $this->letter(sent: false);

        $this->actingAs($this->staff());
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertActionDisabled(TestAction::make('deleteLetter')->table($sent))
            ->assertActionEnabled(TestAction::make('deleteLetter')->table($unsent))
            ->callAction(TestAction::make('deleteLetter')->table($sent))
            ->callAction(TestAction::make('deleteLetter')->table($unsent));
        $this->assertModelExists($sent);
        $this->assertModelMissing($unsent);

        $this->actingAs($this->superAdmin());
        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertActionEnabled(TestAction::make('deleteLetter')->table($sent))
            ->callAction(TestAction::make('deleteLetter')->table($sent));
        $this->assertModelMissing($sent);
    }

    public function test_sent_minutes_are_deleted_by_a_super_admin_only(): void
    {
        $sent = $this->minutes(sent: true);
        $unsent = $this->minutes(sent: false);

        $this->actingAs($this->staff());
        Livewire::test(MinutesRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->assertActionDisabled(TestAction::make('deleteMinutes')->table($sent))
            ->assertActionEnabled(TestAction::make('deleteMinutes')->table($unsent))
            ->callAction(TestAction::make('deleteMinutes')->table($sent))
            ->callAction(TestAction::make('deleteMinutes')->table($unsent));
        $this->assertModelExists($sent);
        $this->assertModelMissing($unsent);

        $this->actingAs($this->superAdmin());
        Livewire::test(MinutesRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callAction(TestAction::make('deleteMinutes')->table($sent));
        $this->assertModelMissing($sent);
    }
}
