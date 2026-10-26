<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Pages\FixMattersDifficulty;
use App\Filament\Mms\Pages\FlightTickets;
use App\Filament\Mms\Pages\Reports\AssistantMatterFeesReport;
use App\Filament\Mms\Pages\Reports\AssistantMattersReport;
use App\Filament\Mms\Pages\Reports\AssistantPerformanceReport;
use App\Filament\Mms\Pages\Reports\CourtWorkloadReport;
use App\Filament\Mms\Pages\Reports\DeductionsReconciliationReport;
use App\Filament\Mms\Pages\Reports\FeeCollectionAgingReport;
use App\Filament\Mms\Pages\Reports\MarketingCommissionsReport;
use App\Filament\Mms\Pages\Reports\MatterExpensesReport;
use App\Filament\Mms\Pages\Reports\MatterQualityReport;
use App\Filament\Mms\Pages\Reports\MattersMonthlyReport;
use App\Filament\Mms\Pages\Reports\MyIncentiveReport;
use App\Filament\Mms\Pages\Reports\MyMattersReport;
use App\Filament\Mms\Pages\Reports\OverdueMattersReport;
use App\Filament\Mms\Pages\Reports\TypeProfitabilityReport;
use App\Filament\Mms\Pages\Reports\VatSummaryReport;
use App\Filament\Mms\Pages\UnfiledEmails;
use App\Models\Party;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pages laid out by Filament itself (content() schemas, no views of their
 * own): each opens, with what it is for.
 */
class FilamentPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        // A user with their party: "My matters" is theirs only.
        $user = User::factory()->create();
        Party::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user->fresh());
    }

    public function test_the_reports_open_with_their_table_and_print_header(): void
    {
        foreach ([AssistantMatterFeesReport::class, AssistantPerformanceReport::class, DeductionsReconciliationReport::class, FeeCollectionAgingReport::class,
            MatterQualityReport::class, MyMattersReport::class, OverdueMattersReport::class, TypeProfitabilityReport::class, VatSummaryReport::class,
            AssistantMattersReport::class, CourtWorkloadReport::class, MatterExpensesReport::class, MarketingCommissionsReport::class,
            MattersMonthlyReport::class, MyIncentiveReport::class] as $page) {
            Livewire::test($page)
                ->assertOk()
                ->assertSeeHtml('report-print-container')
                ->assertSeeHtml('report-print-header')
                ->assertSeeHtml('fi-ta');
        }
    }

    public function test_a_report_left_on_all_rows_opens_on_its_page_size_again(): void
    {
        $page = Livewire::test(AssistantMatterFeesReport::class)->set('tableRecordsPerPage', 'all');
        $this->assertSame('all', session()->get($page->instance()->getTablePerPageSessionKey()));

        // Opened again: its own page size, not every row.
        Livewire::test(AssistantMatterFeesReport::class)->assertSet('tableRecordsPerPage', 50);
    }

    public function test_the_table_pages_open_with_their_table(): void
    {
        Livewire::test(FlightTickets::class)->assertOk()->assertSeeHtml('fi-ta');
        Livewire::test(UnfiledEmails::class)->assertOk()->assertSee(__('No unfiled emails'));
    }

    public function test_the_other_pages_open(): void
    {
        Livewire::test(FixMattersDifficulty::class)->assertOk();
        Livewire::test(Chat::class)->assertOk()->assertSeeLivewire('chat-widget');
    }
}
