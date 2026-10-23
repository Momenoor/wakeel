<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\MatterResource;
use App\Filament\Mms\Resources\Parties\PartyResource;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Support\ContactSearch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Global search (Ctrl+K) by an email or a phone number: the party with it, and the matters it is a main party of.
 */
class GlobalSearchContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('mms');
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
    }

    public function test_what_is_typed_is_read_as_an_email_a_phone_or_neither(): void
    {
        $this->assertSame(['email', 'amr@jpa.ae'], ContactSearch::parse(' Amr@JPA.ae '));
        $this->assertSame(['phone', '501112233'], ContactSearch::parse('+971 50 111 2233'));
        $this->assertSame(['phone', '501112233'], ContactSearch::parse('٠٥٠١١١٢٢٣٣'));
        // A matter number or a name is not a phone.
        $this->assertNull(ContactSearch::parse('639/2025'));
        $this->assertNull(ContactSearch::parse('Amr Hassan'));
    }

    public function test_a_party_and_its_matters_are_found_by_its_email_or_phone(): void
    {
        $party = Party::factory()->create(['name' => 'Amr Hassan', 'phone' => ['0501112233'], 'email' => ['Amr.Hassan@Example.ae']]);
        Party::factory()->create(['name' => 'Someone else', 'phone' => ['0509998877'], 'email' => ['other@example.ae']]);
        $matter = Matter::factory()->create(['number' => '639', 'year' => 2025]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'party', 'type' => 'plaintiff']);

        $titles = fn (string $resource, string $search): array => $resource::getGlobalSearchResults($search)->pluck('title')->all();

        // However the phone is written; the email whatever its case.
        foreach (['0501112233', '+971 50 111 2233', '971501112233', 'amr.hassan@example.ae'] as $search) {
            $this->assertSame(['Amr Hassan'], $titles(PartyResource::class, $search), $search);
            $this->assertSame(['2025/639'], $titles(MatterResource::class, $search), $search);
        }

        // Its phone and email shown under its name.
        $details = PartyResource::getGlobalSearchResults('0501112233')->sole()->details;
        $this->assertSame(['0501112233', 'Amr.Hassan@Example.ae'], array_values($details));

        // A name is searched as before.
        $this->assertSame(['Amr Hassan'], $titles(PartyResource::class, 'Amr'));
    }
}
