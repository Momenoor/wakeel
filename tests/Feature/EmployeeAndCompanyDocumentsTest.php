<?php

namespace Tests\Feature;

use App\Console\Commands\WarnExpiringDocuments;
use App\Enums\AttachmentCategory;
use App\Enums\NotificationType;
use App\Filament\Mms\RelationManagers\DocumentsRelationManager;
use App\Filament\Mms\Resources\Companies\CompanyResource;
use App\Filament\Mms\Resources\Companies\Pages\CreateCompany;
use App\Filament\Mms\Resources\Companies\Pages\EditCompany;
use App\Filament\Mms\Resources\Companies\Pages\ListCompanies;
use App\Filament\Mms\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\EditEmployeeProfile;
use App\Filament\Mms\Resources\EmployeeProfiles\Pages\ListEmployeeProfiles;
use App\Filament\Support\AttachmentFields;
use App\Models\Company;
use App\Models\EmployeeProfile;
use App\Models\Party;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * An employee's documents (labour contract, Emirates ID, visa, passport, personal picture, others) and the
 * companies' (trade licence, establishment card), and the alarm as they near their expiry: in the tables, on the
 * menu and on the bell.
 */
class EmployeeAndCompanyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
        Filament::setCurrentPanel('mms');
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(9, 0));
    }

    private function profile(array $attributes = []): EmployeeProfile
    {
        return EmployeeProfile::create(['party_id' => Party::factory()->employee()->create()->id, 'date_of_joining' => '2020-01-01', ...$attributes]);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    public function test_an_employees_documents_are_attached_by_kind_and_seen_only_by_those_who_may_see_the_employee(): void
    {
        $profile = $this->profile();
        $this->actingAs($this->user('ViewAny:EmployeeProfile', 'View:EmployeeProfile', 'Update:EmployeeProfile'));

        $this->assertSame(
            [AttachmentCategory::LABOUR_CONTRACT, AttachmentCategory::EMIRATES_ID, AttachmentCategory::VISA, AttachmentCategory::PASSPORT, AttachmentCategory::PERSONAL_PHOTO, AttachmentCategory::OTHER],
            $profile->attachmentCategories(),
        );

        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $profile, 'pageClass' => EditEmployeeProfile::class])
            ->assertSee(__('Documents'))
            ->callAction(TestAction::make('attachFiles')->table(), data: [
                'files' => [UploadedFile::fake()->create('passport.pdf', 5, 'application/pdf')],
                'category' => AttachmentCategory::PASSPORT->value,
            ])
            ->assertHasNoErrors();

        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $profile, 'pageClass' => EditEmployeeProfile::class])
            ->callAction(TestAction::make('attachFiles')->table(), data: [
                'files' => [UploadedFile::fake()->image('me.jpg')],
                'category' => AttachmentCategory::PERSONAL_PHOTO->value,
            ]);

        $this->assertSame(['passport', 'personal_photo'], $profile->ownedAttachments()->orderBy('id')->pluck('category')->map->value->all());
        // Their latest picture beside their name in the list.
        $this->assertSame('me.jpg', $profile->photo->name);

        // Whoever may not see employees sees no documents, and opens none.
        $this->actingAs($this->user());
        $this->assertFalse(DocumentsRelationManager::canViewForRecord($profile, EditEmployeeProfile::class));
        $this->assertFalse(AttachmentFields::canAttach($profile));
        $this->get($profile->photo->url())->assertForbidden();

        // Who may only see them sees the documents but adds none.
        $this->actingAs($this->user('View:EmployeeProfile'));
        $this->assertTrue(DocumentsRelationManager::canViewForRecord($profile, EditEmployeeProfile::class));
        $this->assertFalse(AttachmentFields::canAttach($profile));
    }

    public function test_a_company_keeps_its_licence_and_establishment_card_and_their_copies(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'JPA Legal Consultancy',
                'name_ar' => 'جي بي إيه للاستشارات القانونية',
                'trade_license_no' => 'CN-1234567',
                'license_authority' => 'Department of Economy and Tourism',
                'trade_license_start' => '2025-12-01',
                'trade_license_expiry' => '2026-11-30',
                'establishment_card_no' => '2/5/12345',
                'establishment_card_issue' => '2024-01-10',
                'establishment_card_expiry' => '2027-01-09',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::sole();
        $this->assertSame('CN-1234567', $company->trade_license_no);
        $this->assertSame('2026-11-30', $company->trade_license_expiry->toDateString());

        Livewire::test(EditCompany::class, ['record' => $company->getKey()])->assertOk();
        Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
            ->callAction(TestAction::make('attachFiles')->table(), data: [
                'files' => [UploadedFile::fake()->create('licence.pdf', 5, 'application/pdf')],
                'category' => AttachmentCategory::TRADE_LICENSE->value,
            ]);

        $this->assertSame(AttachmentCategory::TRADE_LICENSE, $company->ownedAttachments()->sole()->category);

        // The licence ends within 60 days: the alarm in the table and on the menu; the card is two months off.
        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$company])
            ->assertSee(__(':document — :date (:days days left)', ['document' => __('Trade licence'), 'date' => '30/11/2026', 'days' => 26]))
            ->assertDontSee(__('Establishment card').' — ');
        $this->assertSame('1', CompanyResource::getNavigationBadge());
    }

    public function test_expired_and_expiring_documents_are_flagged_and_filtered(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        $expired = $this->profile(['passport_expiry' => '2026-10-01']);
        $soon = $this->profile(['emirates_id_expiry' => '2026-12-01', 'residency_expiry' => '2026-11-20']);
        $fine = $this->profile(['passport_expiry' => '2028-01-01']);
        $left = $this->profile(['passport_expiry' => '2026-10-01', 'date_of_leaving' => '2026-06-30']);

        Livewire::test(ListEmployeeProfiles::class)
            ->assertSee(__(':document — expired :date', ['document' => __('Passport'), 'date' => '01/10/2026']))
            ->assertSee(__(':document — :date (:days days left)', ['document' => __('Residency'), 'date' => '20/11/2026', 'days' => 16]))
            ->filterTable('expiring_documents')
            ->assertCanSeeTableRecords([$expired, $soon])
            ->assertCanNotSeeTableRecords([$fine]);

        // On the menu: those on staff only.
        $this->assertSame('2', EmployeeProfileResource::getNavigationBadge());
        $this->assertNotNull($left);
    }

    public function test_the_bell_rings_for_a_document_60_30_and_7_days_before_and_on_the_day(): void
    {
        $hr = $this->user('Update:EmployeeProfile', 'Update:Company');
        $other = $this->user('View:EmployeeProfile');

        $this->profile(['passport_expiry' => '2026-12-04']);                                     // in 30 days
        $this->profile(['emirates_id_expiry' => '2026-11-04', 'labour_card_expiry' => '2026-11-05']); // today; tomorrow (no)
        $this->profile(['passport_expiry' => '2026-12-04', 'date_of_leaving' => '2026-01-31']);  // left: no
        Company::create(['name' => 'JPA', 'trade_license_expiry' => '2026-11-11', 'establishment_card_expiry' => '2027-01-03']); // 7 days; 60 days

        $this->artisan('documents:warn-expiring')->assertSuccessful();

        $bell = $hr->notifications()->get();
        $this->assertCount(4, $bell);
        $this->assertTrue($bell->every(fn ($n): bool => ($n->data['viewData'][NotificationType::DATA_KEY] ?? null) === NotificationType::DOCUMENTS_EXPIRING->value));
        $this->assertContains(__(':document — expires today', ['document' => __('Emirates ID')]), $bell->pluck('data.body')->all());
        $this->assertCount(0, $other->notifications()->get());

        // Once a day.
        $this->assertFalse(WarnExpiringDocuments::isDue());
        $this->travel(1)->day();
        $this->assertTrue(WarnExpiringDocuments::isDue());
    }
}
