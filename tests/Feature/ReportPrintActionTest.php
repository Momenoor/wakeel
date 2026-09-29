<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Reports\AssistantMatterFeesReport;
use App\Filament\Mms\Pages\Reports\AssistantMattersReport;
use App\Filament\Mms\Pages\Reports\CourtWorkloadReport;
use App\Filament\Mms\Pages\Reports\FeeCollectionAgingReport;
use App\Filament\Mms\Pages\Reports\MattersMonthlyReport;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use App\Support\ReportPrintAction;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ReportPrintActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
    }

    public function test_report_print_action_configures_alpine_click_handler(): void
    {
        $action = ReportPrintAction::make();

        $this->assertInstanceOf(Action::class, $action);
        $this->assertSame('print', $action->getName());
        $this->assertSame('window.print()', $action->getAlpineClickHandler());
        $this->assertFalse($action->isLivewireClickHandlerEnabled());
    }

    public function test_report_pages_render_print_header(): void
    {
        Livewire::test(CourtWorkloadReport::class)
            ->assertSeeHtml('report-print-header')
            ->assertSeeHtml(__('Court Workload'))
            ->assertSeeHtml(__('Applied Filters'));

        Livewire::test(MattersMonthlyReport::class)
            ->assertSeeHtml('report-print-header')
            ->assertSeeHtml(__('Matters Monthly Report'));

        Livewire::test(AssistantMattersReport::class)
            ->assertSeeHtml('report-print-header')
            ->assertSeeHtml(__('Assistant Matters Report'));
    }

    public function test_the_assistant_matters_report_is_paged_on_screen_but_prints_everything(): void
    {
        $report = Livewire::test(AssistantMattersReport::class)->instance();
        $table = $report->getTable();

        $this->assertTrue($table->isPaginated());
        $this->assertContains('all', $table->getPaginationPageOptions());
        $this->assertTrue(ReportPrintAction::printsAllPages($report));

        // Print shows every row, prints, then puts the page size back.
        $handler = $table->getAction('print')->getAlpineClickHandler();
        $this->assertStringContainsString("\$wire.set('tableRecordsPerPage', 'all')", $handler);
        $this->assertStringContainsString('window.print()', $handler);
        $this->assertStringContainsString("\$wire.set('tableRecordsPerPage', before)", $handler);

        // A report that is not paged prints as it always did.
        $court = Livewire::test(CourtWorkloadReport::class)->instance();
        $this->assertFalse(ReportPrintAction::printsAllPages($court));
        $this->assertSame('window.print()', $court->getTable()->getAction('print')->getAlpineClickHandler());
    }

    public function test_the_assistant_matters_report_does_not_query_once_per_row(): void
    {
        $queriesFor = function (int $rows): int {
            foreach (range(1, $rows) as $i) {
                $matter = Matter::factory()->create();
                MatterParty::create(['matter_id' => $matter->id, 'party_id' => Party::factory()->assistant()->create()->id, 'role' => 'expert', 'type' => 'assistant']);
                MatterParty::create(['matter_id' => $matter->id, 'party_id' => Party::factory()->certifiedExpert()->create()->id, 'role' => 'expert', 'type' => 'certified']);
                MatterParty::create(['matter_id' => $matter->id, 'party_id' => Party::factory()->create()->id, 'role' => 'party', 'type' => 'plaintiff']);
            }

            $component = Livewire::test(AssistantMattersReport::class)->call('loadTable');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component->call('$refresh');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $few = $queriesFor(2);
        $many = $queriesFor(10);

        // Twelve rows cost (almost) the same queries as two.
        $this->assertLessThanOrEqual($few + 2, $many, "{$few} queries for 2 rows, {$many} for 12");
    }

    public function test_report_tables_are_not_paginated(): void
    {
        $courtReport = Livewire::test(CourtWorkloadReport::class)->instance();
        $this->assertFalse($courtReport->getTable()->isPaginated());

        $feesReport = Livewire::test(AssistantMatterFeesReport::class)->instance();
        $this->assertFalse($feesReport->getTable()->isPaginated());

        $agingReport = Livewire::test(FeeCollectionAgingReport::class)->instance();
        $this->assertFalse($agingReport->getTable()->isPaginated());
    }
}
