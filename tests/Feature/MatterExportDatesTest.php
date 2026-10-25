<?php

namespace Tests\Feature;

use App\Filament\Mms\Exports\MatterExporter;
use App\Models\Matter;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The matters' Export All: every date a matter keeps, as the system writes dates.
 */
class MatterExportDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_all_has_every_date_of_the_matter_in_the_systems_format(): void
    {
        $matter = Matter::factory()->create([
            'received_at' => '2026-03-01', 'distributed_at' => '2026-03-02', 'next_session_date' => '2026-11-04 10:30:00',
            'initial_report_at' => '2026-05-10', 'final_report_at' => '2026-06-20', 'final_report_memo_date' => '2026-07-01',
        ]);

        // The row Export All writes for it, every column on.
        $map = collect(MatterExporter::getColumns())->mapWithKeys(fn (ExportColumn $column): array => [$column->getName() => $column->getLabel()])->all();
        $row = array_combine(array_keys($map), (new MatterExporter(new Export, $map, []))(MatterExporter::modifyQuery(Matter::query())->findOrFail($matter->id)));
        $state = fn (string $name): string => (string) $row[$name];

        $this->assertSame('01/03/2026', $state('received_at'));
        $this->assertSame('02/03/2026', $state('distributed_at'));
        $this->assertSame('04/11/2026 10:30 AM', $state('next_session_date'));
        $this->assertSame('10/05/2026', $state('initial_report_at'));
        $this->assertSame('20/06/2026', $state('final_report_at'));
        $this->assertSame('01/07/2026', $state('final_report_memo_date'));
        $this->assertSame($matter->created_at->format('d/m/Y'), $state('created_at'));
        $this->assertSame('', $state('deleted_at'));
    }
}
