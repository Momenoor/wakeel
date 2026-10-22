<?php

namespace Tests\Feature;

use App\Enums\EmployeeRequestType;
use App\Enums\RequestStatus;
use App\Filament\Mms\Resources\HrListItems\Pages\ManageHrListItems;
use App\Filament\Mms\Resources\Parties\Pages\EditParty;
use App\Filament\Mms\Resources\Parties\RelationManagers\ExpertRegistrationsRelationManager;
use App\Models\Court;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRequest;
use App\Models\ExpertRegistration;
use App\Models\HrListItem;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\Setting;
use App\Models\User;
use App\Services\MMS\BulkMailPlaceholders;
use App\Services\MMS\EmployeePlaceholders;
use App\Services\MMS\Letters\LetterComposer;
use App\Support\CompanyContact;
use App\Support\Nationalities;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A letter speaks its own language: the office, the employee (name, designation, nationality) in Arabic or
 * English as the letter is written, either at will ({{…_ar}}, {{…_en}}); the employee's documents and their
 * expiry; a certified expert's registration number at the matter's court; a code like an Emirates ID kept
 * as written in Arabic text.
 */
class BilingualLettersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web'])));
    }

    private function template(string $locale, string $body = '<p>{{employee.name}}</p>'): LetterTemplate
    {
        return LetterTemplate::create(['name' => 'Certificate '.$locale, 'slug' => 'certificate-'.$locale.'-'.uniqid(), 'subject' => 'Certificate', 'body' => $body, 'placeholders' => [], 'locale' => $locale, 'category' => 'certificates', 'is_active' => true]);
    }

    public function test_an_employee_is_named_in_the_letters_language_with_their_documents(): void
    {
        $designation = HrListItem::create(['kind' => HrListItem::DESIGNATION, 'name_en' => 'Financial Expert', 'name_ar' => 'خبير مالي']);
        $party = Party::factory()->employee()->create(['name' => 'Amr Hassan']);
        EmployeeProfile::create(['party_id' => $party->id, 'date_of_joining' => '2021-03-01', 'name_ar' => 'عمرو حسن', 'display_name' => 'AMR HASSAN',
            'designation_id' => $designation->id, 'nationality' => 'EG', 'passport_no' => 'A1234567', 'passport_expiry' => '2029-05-31', 'emirates_id_no' => '784-1990-1234567-1', 'emirates_id_expiry' => '2027-01-15']);
        $request = fn (string $locale) => EmployeeRequest::create(['party_id' => $party->id, 'type' => EmployeeRequestType::CERTIFICATE, 'status' => RequestStatus::PENDING, 'letter_template_id' => $this->template($locale)->id]);

        $arabic = EmployeePlaceholders::values($request('ar'));
        $this->assertSame(['عمرو حسن', 'خبير مالي', 'مصري'], [$arabic['employee.name'], $arabic['employee.designation'], $arabic['employee.nationality']]);
        $english = EmployeePlaceholders::values($request('en'));
        $this->assertSame(['AMR HASSAN', 'Financial Expert', 'Egyptian'], [$english['employee.name'], $english['employee.designation'], $english['employee.nationality']]);
        // Either language at will; the documents and their expiry.
        $this->assertSame(['Financial Expert', 'مصري', 'A1234567', '31/05/2029', '784-1990-1234567-1', '15/01/2027'],
            [$arabic['employee.designation_en'], $english['employee.nationality_ar'], $arabic['employee.passport'], $arabic['employee.passport_expiry'], $arabic['employee.emirates_id'], $arabic['employee.emirates_id_expiry']]);
        // The designation kept as text too (its English name).
        $this->assertSame('Financial Expert', $party->employeeProfile->fresh()->designation);
    }

    public function test_a_designation_typed_becomes_one_of_the_list(): void
    {
        $profile = EmployeeProfile::create(['party_id' => Party::factory()->employee()->create()->id, 'date_of_joining' => '2022-01-01', 'designation' => 'Accountant']);

        $this->assertSame('Accountant', $profile->designationItem->name_en);
        Livewire::test(ManageHrListItems::class)->assertCanSeeTableRecords([$profile->designationItem]);
    }

    public function test_the_nationalities_are_a_fixed_list_in_both_languages(): void
    {
        $this->assertSame(['إماراتي', 'Emirati', ''], [Nationalities::name('AE', true), Nationalities::name('ae', false), Nationalities::name('ZZ', true)]);
        app()->setLocale('ar');
        $this->assertSame('إماراتي', Nationalities::options()['AE']);
        $this->assertGreaterThan(150, count(Nationalities::options()));
    }

    public function test_the_office_is_named_in_the_letters_language(): void
    {
        Setting::set('company_name', 'JPA Auditing & Accounting', 'general');
        Setting::set(CompanyContact::NAME_AR, 'جي بي ايه لتدقيق الحسابات', 'general');
        Setting::set(CompanyContact::ADDRESS_EN, 'Dubai, UAE', 'general');
        Setting::set(CompanyContact::ADDRESS_AR, 'دبي، الإمارات', 'general');
        Setting::set(CompanyContact::LICENSE_NO, 'CN-1234567', 'general');

        $this->assertSame(['جي بي ايه لتدقيق الحسابات', 'دبي، الإمارات', 'CN-1234567'], array_values(array_intersect_key(CompanyContact::values(true), array_flip(['company.name', 'company.address', 'company.license_no']))));
        $this->assertSame(['JPA Auditing & Accounting', 'Dubai, UAE'], array_values(array_intersect_key(CompanyContact::values(false), array_flip(['company.name', 'company.address']))));
        // Not said (an email): its name as set.
        $this->assertSame('JPA Auditing & Accounting', CompanyContact::values()['company.name']);
    }

    public function test_a_code_like_an_emirates_id_is_kept_as_written_in_arabic_text(): void
    {
        $html = (new LetterComposer($this->template('ar', '<p>هوية رقم {{employee.emirates_id}} بتاريخ {{date}}</p>'), null, extra: ['employee.emirates_id' => '784-1990-1234567-1']))->bodyHtml();

        $this->assertStringContainsString('<bdo dir="ltr">784-1990-1234567-1</bdo>', $html);
        // A date reads right as it is.
        $this->assertStringNotContainsString('<bdo dir="ltr">'.now()->format('d/m/Y'), $html);
    }

    public function test_a_placeholder_keeps_the_formatting_given_to_it(): void
    {
        $html = (new LetterComposer($this->template('ar', '<p>السيد <span style="color: #e03e2d; font-size: 18pt;"><strong>{{employee.name}}</strong></span> هوية <em>{{employee.emirates_id}}</em></p>'), null,
            extra: ['employee.name' => 'عمرو حسن', 'employee.emirates_id' => '784-1990-1234567-1']))->bodyHtml();

        $this->assertStringContainsString('<span style="color: #e03e2d; font-size: 18pt;"><strong>عمرو حسن</strong></span>', $html);
        $this->assertStringContainsString('<em><bdo dir="ltr">784-1990-1234567-1</bdo></em>', $html);
    }

    public function test_a_certified_experts_registration_number_at_the_matters_court(): void
    {
        $dubai = Court::factory()->create(['name' => 'محاكم دبي']);
        $abuDhabi = Court::factory()->create(['name' => 'محاكم أبوظبي']);
        $expert = Party::factory()->certifiedExpert()->create();
        ExpertRegistration::create(['party_id' => $expert->id, 'court_id' => $dubai->id, 'number' => 'DXB-501']);
        ExpertRegistration::create(['party_id' => $expert->id, 'court_id' => $abuDhabi->id, 'number' => 'AUH-77']);
        $matter = Matter::factory()->create(['court_id' => $abuDhabi->id]);
        MatterParty::create(['matter_id' => $matter->id, 'party_id' => $expert->id, 'role' => 'expert', 'type' => 'certified']);

        $this->assertSame('AUH-77', BulkMailPlaceholders::forMatter($matter->fresh())['matter.experts.registration']);

        // Their page: the registrations, for a certified expert only.
        $this->assertTrue(ExpertRegistrationsRelationManager::canViewForRecord($expert, EditParty::class));
        $this->assertFalse(ExpertRegistrationsRelationManager::canViewForRecord(Party::factory()->create(), EditParty::class));
        Livewire::test(ExpertRegistrationsRelationManager::class, ['ownerRecord' => $expert, 'pageClass' => EditParty::class])
            ->assertCanSeeTableRecords(ExpertRegistration::all());
    }
}
