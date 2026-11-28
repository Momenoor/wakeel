<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\LettersRelationManager;
use App\Filament\Mms\Resources\Parties\Pages\EditParty;
use App\Filament\Mms\Resources\Parties\Pages\ListParties;
use App\Filament\Mms\Resources\Parties\RelationManagers\ContactsRelationManager;
use App\Filament\Support\EmailSendFields;
use App\Models\Letterhead;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\Party;
use App\Models\User;
use App\Services\MMS\ContactMap;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The shared contact map (Contact_Map.pdf) as external entities with their contacts under them: imported (a file on
 * the Parties page, or the command line) once and again without doubling, picked as a letter's recipient (the letter to the entity, its email to the contact),
 * copied in on an email, and managed on the entity's page.
 */
class ContactMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->actingAs(User::factory()->create()->assignRole('super-admin'));
    }

    private function entity(string $name): Party
    {
        return Party::query()->whereNull('parent_id')->where('name', $name)->sole();
    }

    /**
     * A contact map of made-up cards, of the shape the real one has.
     *
     * @return list<array<string, mixed>>
     */
    private function cards(): array
    {
        return [
            // A bank's general number; one of its people, with their position.
            ['entity' => 'Test Bank', 'name' => null, 'title' => null, 'kind' => 'bank', 'city' => 'أبوظبي', 'phones' => ['021111111'], 'emails' => []],
            ['entity' => 'Test Bank', 'name' => 'سالم أحمد', 'title' => 'Head of Litigation', 'kind' => 'bank', 'city' => 'أبوظبي', 'phones' => ['0501111111'], 'emails' => ['salem@testbank.ae']],
            // A court office with no one named, by its own name; the same person twice, both addresses kept.
            ['entity' => 'محاكم الاختبار', 'name' => 'قسم الحسابات', 'title' => null, 'kind' => 'judicial', 'city' => 'الشارقة', 'phones' => ['065555555'], 'emails' => []],
            ['entity' => 'محاكم الاختبار', 'name' => 'منى خالد', 'title' => 'أمين سر', 'kind' => 'judicial', 'city' => 'الشارقة', 'phones' => [], 'emails' => ['mona@court.test']],
            ['entity' => 'محاكم الاختبار', 'name' => 'منى خالد', 'title' => 'قيد القضايا', 'kind' => 'judicial', 'city' => 'الشارقة', 'phones' => [], 'emails' => ['MONA@court.test', 'mona2@court.test']],
            // A toll-free number, kept as it is.
            ['entity' => 'هيئة الاختبار', 'name' => null, 'title' => null, 'kind' => 'government', 'city' => 'دبي', 'phones' => ['800990'], 'emails' => ['info@authority.test']],
            ['entity' => '', 'name' => 'No entity', 'phones' => [], 'emails' => []],
        ];
    }

    public function test_the_map_becomes_external_entities_with_their_contacts(): void
    {
        $made = app(ContactMap::class)->import($this->cards());

        $this->assertSame(['entities' => 3, 'contacts' => 3], $made);
        $this->assertSame(3, Party::query()->withRole('external')->whereNull('parent_id')->count());
        $this->assertSame(3, Party::query()->withRole('external')->whereNotNull('parent_id')->count());

        // A bank, and one of its people with their position there.
        $bank = $this->entity('Test Bank');
        $this->assertSame('bank', $bank->entity_kind);
        // Its own number and its contacts', and their emails: its full information.
        $this->assertSame(['021111111', '0501111111'], $bank->phone);
        $this->assertSame(['salem@testbank.ae'], $bank->email);
        $this->assertSame('أبوظبي', $bank->address);
        $salem = $bank->contacts()->sole();
        $this->assertSame(['سالم أحمد', 'Head of Litigation', ['salem@testbank.ae'], ['0501111111'], 'أبوظبي'],
            [$salem->name, $salem->title, $salem->email, $salem->phone, $salem->address]);
        $this->assertNull($salem->entity_kind);

        // The same person twice: one contact, the first position, both addresses (no case doubled).
        $court = $this->entity('محاكم الاختبار');
        $this->assertSame('judicial', $court->entity_kind);
        $mona = $court->contacts()->where('name', 'منى خالد')->sole();
        $this->assertSame('أمين سر', $mona->title);
        $this->assertSame(['mona@court.test', 'mona2@court.test'], $mona->email);
        $this->assertSame(['065555555'], $court->phone);
        $this->assertSame(['mona@court.test', 'mona2@court.test'], $court->email);
        $this->assertSame(['800990'], $this->entity('هيئة الاختبار')->phone);

        // Again: nothing doubled.
        $this->assertSame(['entities' => 0, 'contacts' => 0], app(ContactMap::class)->import($this->cards()));
        $this->assertSame(6, Party::query()->withRole('external')->count());
    }

    public function test_a_party_there_already_by_the_name_becomes_an_external_entity_too(): void
    {
        $bank = Party::factory()->create(['name' => 'Test Bank', 'role' => ['role' => ['party']], 'email' => ['old@testbank.ae'], 'phone' => []]);

        $this->assertSame(['entities' => 2, 'contacts' => 3], app(ContactMap::class)->import($this->cards()));

        $bank->refresh();
        $this->assertSame(['party', 'external'], $bank->role['role']);
        $this->assertSame('bank', $bank->entity_kind);
        $this->assertSame(['old@testbank.ae', 'salem@testbank.ae'], $bank->email);
        $this->assertSame(['021111111', '0501111111'], $bank->phone);
        $this->assertSame(1, Party::query()->where('name', 'Test Bank')->count());
        $this->assertTrue($bank->contacts()->where('name', 'سالم أحمد')->exists());
    }

    public function test_every_name_is_in_arabic_and_english_and_an_english_named_one_takes_its_arabic(): void
    {
        // As an earlier import left them: the bank and its officer by their English names only.
        $old = Party::factory()->create(['name' => 'Sample Bank', 'name_en' => null, 'role' => ['role' => ['external']], 'email' => ['info@samplebank.test'], 'phone' => []]);
        $officer = Party::factory()->create(['name' => 'John Smith', 'name_en' => null, 'parent_id' => $old->id, 'role' => ['role' => ['external']], 'email' => ['john@samplebank.test'], 'phone' => []]);

        $made = app(ContactMap::class)->import([
            ['entity' => 'البنك النموذجي', 'entity_en' => 'Sample Bank', 'name' => 'جون سميث', 'name_en' => 'John Smith', 'kind' => 'bank', 'phones' => [], 'emails' => ['john@samplebank.test']],
            ['entity' => 'البنك النموذجي', 'entity_en' => 'Sample Bank', 'name' => 'سارة علي', 'name_en' => 'Sara Ali', 'kind' => 'bank', 'phones' => [], 'emails' => []],
            ['entity' => 'هيئة نموذجية', 'entity_en' => 'Sample Authority', 'name' => null, 'kind' => 'government', 'phones' => [], 'emails' => []],
        ]);

        $this->assertSame(['entities' => 1, 'contacts' => 1], $made);
        $this->assertSame(['البنك النموذجي', 'Sample Bank'], [$old->refresh()->name, $old->name_en]);
        $this->assertSame(['جون سميث', 'John Smith'], [$officer->refresh()->name, $officer->name_en]);
        $this->assertSame('Sara Ali', $old->contacts()->where('name', 'سارة علي')->sole()->name_en);
        $this->assertSame('Sample Authority', $this->entity('هيئة نموذجية')->name_en);
        $this->assertSame(1, Party::query()->whereNull('parent_id')->where('name_en', 'Sample Bank')->count());
    }

    public function test_the_map_is_imported_from_a_file_on_the_parties_page(): void
    {
        Storage::fake('local');
        // Whatever type the browser names it (here none it knows).
        $file = UploadedFile::fake()->createWithContent('contact_map.json', json_encode($this->cards()))->mimeType('application/octet-stream');

        Livewire::test(ListParties::class)
            ->callAction('importContactMap', ['file' => $file])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Contact map imported'));

        $this->assertSame(6, Party::query()->withRole('external')->count());
        // Read, then not kept.
        $this->assertSame([], Storage::disk('local')->allFiles('contact-maps'));

        // Not a list of cards: said so, nothing made.
        Livewire::test(ListParties::class)
            ->callAction('importContactMap', ['file' => UploadedFile::fake()->createWithContent('other.json', '{"a": 1}')])
            ->assertNotified(__('That file is not a contact map: a JSON list of cards was expected.'));
        $this->assertSame(6, Party::query()->withRole('external')->count());

        // From the command line too.
        $path = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($path, json_encode([['entity' => 'جهة أخرى', 'kind' => 'company', 'phones' => [], 'emails' => []]]));
        $this->artisan('parties:import-contact-map', ['path' => $path])->assertSuccessful();
        @unlink($path);
        $this->assertSame('company', $this->entity('جهة أخرى')->entity_kind);
    }

    public function test_a_contact_picked_is_a_letter_to_its_entity_reaching_the_contact(): void
    {
        $bank = Party::factory()->create(['name' => 'Sample Bank', 'role' => ['role' => ['external']], 'email' => ['info@samplebank.test'], 'phone' => ['021234567']]);
        $officer = Party::factory()->create(['name' => 'خالد سعيد', 'title' => 'مستشار قانوني', 'parent_id' => $bank->id, 'role' => ['role' => ['external']], 'email' => ['officer@samplebank.test'], 'phone' => []]);
        $desk = Party::factory()->create(['name' => 'المكتب', 'parent_id' => $bank->id, 'role' => ['role' => ['external']], 'email' => [], 'phone' => []]);

        $this->assertSame(['name' => 'Sample Bank', 'role' => null, 'emails' => ['officer@samplebank.test'], 'phones' => ['021234567'], 'party_id' => $bank->id],
            collect($officer->asLetterRecipient())->except('entity')->all());
        // A contact with no address of its own: the entity's.
        $this->assertSame(['info@samplebank.test'], $desk->asLetterRecipient()['emails']);

        $options = Party::externalOptions();
        $this->assertSame('Sample Bank', $options[$bank->id]);
        $this->assertSame('خالد سعيد — مستشار قانوني (Sample Bank)', $options[$officer->id]);
        $this->assertArrayNotHasKey($desk->id, Party::externalOptions(withEmail: true));

        // On the issue form: the bank's template chosen, the bank the recipient, the officer's address on it.
        Letterhead::create(['name' => 'Main', 'is_default' => true, 'elements' => Letterhead::defaultElements()]);
        $template = LetterTemplate::create(['name' => 'طلب بيانات', 'slug' => 'bank-statement', 'locale' => 'ar', 'category' => 'letter',
            'subject' => 'كشف حساب', 'body' => '<p>{{recipients}}</p>']);
        $template->entities()->attach($bank);
        $matter = Matter::factory()->create();

        Livewire::test(LettersRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
            ->mountAction(TestAction::make('issue')->table())
            ->set('mountedActions.0.data.external_entity', $officer->id)
            ->assertSet('mountedActions.0.data.letter_template_id', $template->id)
            ->assertSet('mountedActions.0.data.extra_recipients', fn ($rows) => count($rows) === 1
                && array_values($rows)[0]['name'] === 'Sample Bank'
                && array_values($rows)[0]['party_id'] === $bank->id
                && array_values($rows)[0]['emails'] === ['officer@samplebank.test'])
            // The bank itself picked after: its address added to the same row, not a second recipient.
            ->set('mountedActions.0.data.external_entity', $bank->id)
            ->assertSet('mountedActions.0.data.extra_recipients', fn ($rows) => count($rows) === 1
                && array_values($rows)[0]['emails'] === ['officer@samplebank.test', 'info@samplebank.test']);
    }

    public function test_an_entity_or_contact_copied_in_adds_its_emails_to_the_cc(): void
    {
        $court = Party::factory()->create(['name' => 'محكمة نموذجية', 'role' => ['role' => ['external']], 'email' => ['mail@court.test', 'accounts@court.test'], 'phone' => []]);
        $someone = Party::factory()->create(['name' => 'Someone', 'role' => ['role' => ['party']], 'email' => ['someone@example.com'], 'phone' => []]);

        $this->assertSame(['a@x.ae', 'mail@court.test', 'accounts@court.test'], EmailSendFields::withEntityCc(['a@x.ae'], $court->id));
        // One there already (in any case) is not added again.
        $this->assertSame(['a@x.ae', 'MAIL@court.test', 'accounts@court.test'], EmailSendFields::withEntityCc(['a@x.ae', 'MAIL@court.test'], $court->id));
        // Only external entities: another party's id adds nothing.
        $this->assertSame(['a@x.ae'], EmailSendFields::withEntityCc(['a@x.ae'], $someone->id));
        $this->assertSame([], EmailSendFields::withEntityCc([], null));
    }

    public function test_the_entity_page_lists_and_adds_its_contacts(): void
    {
        $bank = Party::factory()->create(['name' => 'Model Bank', 'role' => ['role' => ['external']], 'address' => 'دبي', 'email' => [], 'phone' => []]);
        $contact = Party::factory()->create(['name' => 'ريم ناصر', 'title' => 'General Counsel', 'parent_id' => $bank->id, 'role' => ['role' => ['external']], 'email' => [], 'phone' => []]);
        $client = Party::factory()->create(['name' => 'A client', 'role' => ['role' => ['party']], 'email' => [], 'phone' => []]);

        $this->assertTrue(ContactsRelationManager::canViewForRecord($bank, EditParty::class));
        $this->assertFalse(ContactsRelationManager::canViewForRecord($contact, EditParty::class));
        $this->assertFalse(ContactsRelationManager::canViewForRecord($client, EditParty::class));

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $bank, 'pageClass' => EditParty::class])
            ->assertCanSeeTableRecords([$contact])
            ->callAction(TestAction::make('create')->table(), ['name' => 'يوسف علي', 'title' => 'Legal', 'phone' => ['0501234567'], 'email' => ['yousef@modelbank.test']])
            ->assertHasNoFormErrors();

        $new = $bank->contacts()->where('name', 'يوسف علي')->sole();
        $this->assertSame(['external'], $new->role['role']);
        $this->assertSame('دبي', $new->address);
        $this->assertSame('Legal', $new->title);
    }
}
