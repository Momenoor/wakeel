<?php

namespace Tests\Feature;

use App\Filament\Pms\Imports\PropertyImporter;
use App\Support\ImportDate;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dates in import files come in whatever format the spreadsheet used —
 * "27/11/2018" crashed the property import on the live server.
 */
class ImportDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_common_formats_are_read_day_first(): void
    {
        $this->assertSame('2018-11-27', ImportDate::parse('27/11/2018'));
        $this->assertSame('2018-11-27', ImportDate::parse('2018-11-27'));
        $this->assertSame('2018-11-27', ImportDate::parse('27-11-2018'));
        $this->assertSame('2018-11-27', ImportDate::parse('27.11.2018'));
        $this->assertSame('2018-11-27', ImportDate::parse('11/27/2018'));
        $this->assertSame('2018-03-04', ImportDate::parse('04/03/2018'));
        $this->assertNull(ImportDate::parse(' '));
    }

    public function test_an_unreadable_date_fails_only_its_row(): void
    {
        $this->expectException(RowImportFailedException::class);

        ImportDate::parse('not a date');
    }

    public function test_the_property_import_reads_the_title_deed_date(): void
    {
        $column = collect(PropertyImporter::getColumns())->first(fn (ImportColumn $column) => $column->getName() === 'title_deed_date');

        $this->assertSame('2018-11-27', $column->castState('27/11/2018', []));
    }

    public function test_the_failed_jobs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }
}
