<?php

namespace Tests\Feature;

use App\Enums\ProgressType;
use App\Filament\Mms\Resources\Matters\Pages\ViewMatter;
use App\Filament\Mms\Resources\Matters\RelationManagers\ProgressRelationManager;
use App\Models\LetterTemplate;
use App\Models\Matter;
use App\Models\MatterEmail;
use App\Models\MatterMinutes;
use App\Models\MatterOneDriveFolder;
use App\Models\MatterProgress;
use App\Models\MinutesDelivery;
use App\Models\Party;
use App\Models\Setting;
use App\Models\Type;
use App\Models\User;
use App\Services\MMS\MatterOneDriveFolders;
use App\Services\MMS\OneDriveClient;
use App\Services\MMS\PeriodicReports;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\MemoryOneDrive;
use Tests\TestCase;

/**
 * A matter's periodic reports: steps of its progress chosen for one, their files moved into its folder in the
 * matter's OneDrive and the steps marked as in it — the next report's apart — and taken out again, back where they were.
 */
class PeriodicReportTest extends TestCase
{
    use RefreshDatabase;

    private Matter $matter;

    private MemoryOneDrive $oneDrive;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('mms'));
        $this->actingAs(User::factory()->create());
        app()->setLocale('ar');

        $this->oneDrive = new MemoryOneDrive('root-986');
        $this->app->instance(OneDriveClient::class, $this->oneDrive);

        $this->matter = Matter::factory()->create(['number' => '986', 'year' => '2026', 'type_id' => Type::factory()->create(['onedrive_periodic_reports' => '05 التقرير/تقرير دوري رقم {{number}}'])->id]);
        MatterOneDriveFolder::create([
            'matter_id' => $this->matter->id, 'folder_name' => '986-2026', 'status' => MatterOneDriveFolder::CREATED, 'drive_item_id' => 'root-986',
            'party_id' => Party::factory()->assistant()->create(['onedrive_email' => 'amr@firm.ae'])->id,
        ]);
        Setting::set(MatterOneDriveFolders::SIGNED_MINUTES, '06 محاضر موقعة', 'onedrive');
        Setting::clearCache();
    }

    /** An email of the matter — come in, or sent from its letter — its files in OneDrive where it was put, and its step. */
    private function step(string $folder, string $stem, array $email = [], bool $attachments = false): MatterProgress
    {
        $this->oneDrive->put($folder.'/'.$stem.'.pdf');
        if ($attachments) {
            $this->oneDrive->put($folder.'/'.$stem.'/فاتورة.pdf');
        }

        $record = MatterEmail::create([
            'matter_id' => $this->matter->id, 'direction' => MatterEmail::RECEIVED, 'status' => MatterEmail::FILED, 'sender_key' => 'iflas',
            'message_id' => uniqid().'@x.ae', 'subject' => $stem, 'from' => 'claims@mashreq.ae', 'at' => now(),
            'files' => [['name' => $stem.'.pdf', 'path' => null]], 'onedrive' => ['pdf' => true, 'folder' => $folder],
            ...$email,
        ]);

        return $this->matter->progress()->create(['type' => ProgressType::EMAIL_RECEIVED, 'title' => $stem, 'happened_at' => now(), 'source_type' => $record->getMorphClass(), 'source_id' => $record->id]);
    }

    private function tab()
    {
        return Livewire::test(ProgressRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);
    }

    public function test_steps_put_in_a_report_have_their_files_moved_into_its_folder_and_are_kept_apart(): void
    {
        $claim = $this->step('04 المراسلات', 'مطالبة بنك المشرق', attachments: true);
        $letter = $this->step('المراسلات الصادرة', 'خطاب للدائنين', ['direction' => MatterEmail::SENT, 'from' => null]);
        $byHand = $this->matter->progress()->create(['type' => ProgressType::OTHER, 'title' => 'جلسة', 'happened_at' => now()]);
        $later = $this->step('04 المراسلات', 'مطالبة لاحقة');

        // Minutes held: their signed copy too.
        $minutes = MatterMinutes::create(['matter_id' => $this->matter->id, 'letter_template_id' => LetterTemplate::query()->value('id'), 'number' => 1, 'meeting_at' => now(), 'status' => MatterMinutes::FINAL]);
        $this->oneDrive->put('06 محاضر موقعة/محضر 1 موقع.pdf');
        MinutesDelivery::create(['matter_minutes_id' => $minutes->id, 'name' => 'x', 'channel' => 'whatsapp', 'address' => '971', 'status' => 'signed', 'onedrive_url' => 'https://od/06 محاضر موقعة/محضر 1 موقع.pdf']);
        $held = $this->matter->progress()->create(['type' => ProgressType::MEETING_HELD, 'title' => 'محضر 1', 'happened_at' => now(), 'source_type' => $minutes->getMorphClass(), 'source_id' => $minutes->id]);

        $this->tab()
            ->callTableBulkAction('addToReport', [$claim, $letter, $byHand, $held], ['report' => 'new'])
            ->assertHasNoTableBulkActionErrors();

        $report = $this->matter->periodicReports()->sole();
        $this->assertSame([1, '05 التقرير/تقرير دوري رقم 1'], [$report->number, $report->folder]);
        $this->assertSame([$report->id, $report->id, $report->id, $report->id, null], [$claim->fresh()->periodic_report_id, $letter->fresh()->periodic_report_id, $byHand->fresh()->periodic_report_id, $held->fresh()->periodic_report_id, $later->fresh()->periodic_report_id]);
        $this->assertSame([
            '04 المراسلات/مطالبة لاحقة.pdf',
            '05 التقرير/تقرير دوري رقم 1/خطاب للدائنين.pdf',
            '05 التقرير/تقرير دوري رقم 1/محضر 1 موقع.pdf',
            '05 التقرير/تقرير دوري رقم 1/مطالبة بنك المشرق.pdf',
            '05 التقرير/تقرير دوري رقم 1/مطالبة بنك المشرق/فاتورة.pdf',
        ], $this->oneDrive->files());

        $email = MatterEmail::query()->whereKey($claim->source_id)->sole();
        $this->assertSame([$report->id, '04 المراسلات', '05 التقرير/تقرير دوري رقم 1'], [$email->onedrive['report'], $email->onedrive['before_report'], $email->onedrive['folder']]);
        $this->assertSame('https://od/05 التقرير/تقرير دوري رقم 1/محضر 1 موقع.pdf', MinutesDelivery::query()->sole()->onedrive_url);
        $this->assertNull($report->fresh()->error);

        // The next report's: only those not in one yet, at first.
        $this->tab()->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$claim, $byHand]);

        // The next one numbered after it.
        $this->tab()->callTableBulkAction('addToReport', [$later], ['report' => 'new']);
        $this->assertSame([1, 2], $this->matter->periodicReports()->orderBy('number')->pluck('number')->all());
    }

    public function test_steps_taken_out_of_a_report_have_their_files_moved_back(): void
    {
        $claim = $this->step('04 المراسلات', 'مطالبة', attachments: true);
        $other = $this->step('04 المراسلات', 'رد');
        $report = PeriodicReports::add($this->matter, [$claim, $other]);

        $this->tab()
            ->filterTable('periodic_report_id', $report->id)
            ->callTableBulkAction('removeFromReport', [$claim])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull($claim->fresh()->periodic_report_id);
        $this->assertSame($report->id, $other->fresh()->periodic_report_id);
        $this->assertSame([
            '04 المراسلات/مطالبة.pdf',
            '04 المراسلات/مطالبة/فاتورة.pdf',
            '05 التقرير/تقرير دوري رقم 1/رد.pdf',
        ], $this->oneDrive->files());
        $email = MatterEmail::query()->whereKey($claim->source_id)->sole();
        $this->assertSame('04 المراسلات', $email->onedrive['folder']);
        $this->assertArrayNotHasKey('report', $email->onedrive);
    }
}
