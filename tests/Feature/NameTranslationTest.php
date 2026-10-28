<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Filament\Mms\Resources\Parties\Pages\CreateParty;
use App\Jobs\TranslateNames;
use App\Models\ExpertiseArea;
use App\Models\HrListItem;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\Letters\LetterComposer;
use App\Services\Translation\EnglishNames;
use App\Services\Translation\NameTranslator;
use App\Support\Integrations;
use Filament\Facades\Filament;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Arabic names put into English by Claude (NameTranslator): a hundred a request, each matched back by its
 * number; a party saved without its English name given one after the answer; all of them at once from
 * Settings → Integrations; English letters naming parties by it. No request leaves the test — Claude's
 * answers are made up here, from a list of known names.
 */
class NameTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH = [
        'محمد أحمد' => 'Mohammed Ahmed',
        'شركة النجوم للتجارة ذ.م.م' => 'Al Nujoom Trading Co. L.L.C',
        'مدير' => 'Manager',
        'تجاري' => 'Commercial',
    ];

    /** @var list<array<string, mixed>> the bodies sent to Claude */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        config(['services.anthropic.key' => 'sk-ant-test', 'services.anthropic.model' => 'claude-opus-5-5']);
        $this->fakeClaude();
    }

    /** Claude, answering each name of a request it knows, by its id. */
    private function fakeClaude(): void
    {
        $test = $this;

        $this->app->bind(NameTranslator::class, fn () => new class($test) extends NameTranslator
        {
            public function __construct(private NameTranslationTest $test) {}

            protected function client(): Client
            {
                return new Client(apiKey: 'sk-ant-test', requestOptions: ['maxRetries' => 0, 'middleware' => [
                    fn (RequestInterface $request) => $this->test->answer($request),
                ]]);
            }
        });
    }

    public function answer(RequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $this->sent[] = $body;

        $names = collect(json_decode($body['messages'][0]['content'], true))
            ->filter(fn (array $row): bool => isset(self::ENGLISH[$row['arabic']]))
            ->map(fn (array $row): array => ['id' => $row['id'], 'english' => self::ENGLISH[$row['arabic']]])
            ->values();

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $body['model'],
            'content' => [['type' => 'text', 'text' => json_encode(['names' => $names])]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ]));
    }

    public function test_names_are_asked_for_a_hundred_a_request_and_matched_back_by_number(): void
    {
        $names = [...array_keys(self::ENGLISH), 'John Smith', ...array_map(fn (int $i): string => 'اسم '.$i, range(1, 150))];

        $english = app(NameTranslator::class)->translate([...$names, ' محمد أحمد ']);

        // What has no Arabic is not asked about; a name given twice, once; those not known, left out.
        $this->assertEquals(self::ENGLISH, $english);
        $this->assertCount(2, $this->sent);
        $this->assertCount(100, json_decode($this->sent[0]['messages'][0]['content'], true));
        $this->assertCount(54, json_decode($this->sent[1]['messages'][0]['content'], true));

        // The settings' model, a JSON answer, with little thought.
        $this->assertSame('claude-opus-5-5', $this->sent[0]['model']);
        $this->assertSame('json_schema', $this->sent[0]['output_config']['format']['type']);
        $this->assertSame('low', $this->sent[0]['output_config']['effort']);
    }

    public function test_a_party_saved_without_its_english_name_is_given_one(): void
    {
        $party = Party::factory()->create(['name' => 'محمد أحمد']);
        $typed = Party::factory()->create(['name' => 'شركة النجوم للتجارة ذ.م.م', 'name_en' => 'Nujoom Co.']);
        $english = Party::factory()->create(['name' => 'John Smith']);

        $this->assertSame('Mohammed Ahmed', $party->fresh()->name_en);
        // One typed in is kept; a name with no Arabic is not sent.
        $this->assertSame('Nujoom Co.', $typed->fresh()->name_en);
        $this->assertNull($english->fresh()->name_en);
        $this->assertCount(1, $this->sent);
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        config(['services.anthropic.key' => null]);

        $party = Party::factory()->create(['name' => 'محمد أحمد']);

        $this->assertNull($party->fresh()->name_en);
        $this->assertSame([], $this->sent);
    }

    public function test_translate_names_now_fills_the_parties_and_the_lists(): void
    {
        config(['services.anthropic.key' => null]);
        $party = Party::factory()->create(['name' => 'محمد أحمد']);
        $unknown = Party::factory()->create(['name' => 'اسم غير معروف']);
        // As the lists began: the one name, in Arabic, in both.
        $designation = HrListItem::create(['kind' => HrListItem::DESIGNATION, 'name_en' => 'مدير', 'name_ar' => 'مدير']);
        $area = ExpertiseArea::create(['name_en' => 'تجاري', 'name_ar' => 'تجاري']);
        $fine = HrListItem::create(['kind' => HrListItem::DESIGNATION, 'name_en' => 'Lawyer', 'name_ar' => 'محامي']);
        config(['services.anthropic.key' => 'sk-ant-test']);

        $this->assertSame(4, EnglishNames::left());

        $stats = app(EnglishNames::class)->fill(20);

        $this->assertSame(['translated' => 3, 'left' => 1], $stats);
        $this->assertSame('Mohammed Ahmed', $party->fresh()->name_en);
        $this->assertNull($unknown->fresh()->name_en);
        $this->assertSame('Manager', $designation->fresh()->name_en);
        $this->assertSame('Commercial', $area->fresh()->name_en);
        $this->assertSame('Lawyer', $fine->fresh()->name_en);
    }

    public function test_in_the_background_whoever_started_it_is_told_in_the_bell(): void
    {
        config(['services.anthropic.key' => null]);
        Party::factory()->create(['name' => 'محمد أحمد']);
        config(['services.anthropic.key' => 'sk-ant-test']);
        $user = User::factory()->create();

        $this->assertTrue(TranslateNames::start($user->id));

        $this->assertFalse(TranslateNames::running());
        $this->assertSame(0, EnglishNames::left());
        $this->assertStringContainsString('1', (string) $user->notifications()->first()?->data['title']);
    }

    public function test_an_english_letter_names_a_party_in_english(): void
    {
        $matter = Matter::factory()->create();
        $party = Party::factory()->create(['name' => 'محمد أحمد']);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $party->id, 'role' => 'party']);

        $this->assertSame('Mohammed Ahmed', collect(LetterComposer::candidates($matter->fresh(), false))->first()['name']);
        $this->assertSame('محمد أحمد', collect(LetterComposer::candidates($matter->fresh(), true))->first()['name']);
    }

    public function test_the_party_form_fills_the_english_name_once_the_name_is_typed(): void
    {
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateParty::class)
            ->fillForm(['name' => 'محمد أحمد'])
            ->assertSchemaStateSet(['name_en' => 'Mohammed Ahmed'])
            // Corrected by hand, it is not translated over.
            ->fillForm(['name_en' => 'Mohamed Ahmed', 'name' => 'شركة النجوم للتجارة ذ.م.م'])
            ->assertSchemaStateSet(['name_en' => 'Mohamed Ahmed']);
    }

    public function test_the_key_is_kept_encrypted_and_switches_it_on(): void
    {
        config(['services.anthropic.key' => null]);

        Integrations::save([Integrations::CLAUDE_KEY => 'sk-ant-saved', Integrations::CLAUDE_MODEL => 'claude-haiku-5-5']);
        Integrations::apply();

        $this->assertTrue(Integrations::claudeOn());
        $this->assertSame('sk-ant-saved', config('services.anthropic.key'));
        $this->assertSame('claude-haiku-5-5', config('services.anthropic.model'));
        $this->assertNotSame('sk-ant-saved', Setting::get(Integrations::CLAUDE_KEY));
    }
}
