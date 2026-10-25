<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Filament\Shared\Pages\SystemUpdates;
use App\Models\Matter;
use App\Models\User;
use App\Services\Backup\BackupStore;
use App\Services\Backup\DatabaseBackup;
use App\Services\Backup\DatabaseRestore;
use App\Services\Backup\SqlStatements;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The database backed up by the app itself and downloaded, and replaced whole by a backup — a step at a time,
 * by a super admin only; the restore run from a page its own key opens, the database being dropped under it.
 */
class DatabaseBackupTest extends TestCase
{
    // Not in a transaction: SQLite switches its foreign keys off for a restore only outside one.
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Filament::setCurrentPanel('mms');
        Gate::before(fn () => true);
        $this->admin = User::factory()->create(['password' => 'secret-pass'])->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->actingAs($this->admin);
    }

    private function backUp(): string
    {
        $backup = app(DatabaseBackup::class);
        $backup->start();

        // Never for ever: a step that goes nowhere fails the test.
        foreach (range(1, 200) as $ignored) {
            $state = $backup->step();
            if ($state['done'] ?? false) {
                break;
            }
        }
        $this->assertTrue($state['done'] ?? false, 'The backup did not finish.');

        $this->assertNull($state['error']);

        return $state['name'];
    }

    private function restore(string $name): array
    {
        $restore = app(DatabaseRestore::class);
        $key = $restore->prepare($name);

        foreach (range(1, 200) as $ignored) {
            $state = $restore->step($key);
            $this->assertNull($state['error'], (string) $state['error']);
            if ($state['phase'] === 'done') {
                break;
            }
        }
        $this->assertSame('done', $state['phase']);

        return $state;
    }

    public function test_a_backup_restored_gives_the_database_back_as_it_was(): void
    {
        $matter = Matter::factory()->create(['number' => '1200', 'year' => '2026']);
        $matter->fees()->create(['type' => FeeType::EXPERT_FEE, 'amount' => 1000, 'vat' => 50, 'is_vat_included' => true, 'description' => "قضية «تجريبية»; it's \"quoted\"\nover two lines"]);
        $matter->fees()->create(['type' => FeeType::OTHER, 'amount' => 10, 'description' => null]);
        DB::table('sessions')->insert(['id' => 'x', 'payload' => 'p', 'last_activity' => 1]);
        $users = User::query()->orderBy('id')->get(['id', 'email', 'password'])->toArray();

        $name = $this->backUp();
        $this->assertFileExists(app(BackupStore::class)->path($name));

        // Changed after the backup: undone by the restore.
        $matter->fees()->update(['description' => 'changed']);
        Matter::factory()->create(['number' => '9999']);

        $this->restore($name);

        $this->assertSame(1, Matter::count());
        [$fee, $other] = Matter::sole()->fees()->orderBy('id')->get()->all();
        $this->assertSame([1000.0, 50.0, true, "قضية «تجريبية»; it's \"quoted\"\nover two lines"], [(float) $fee->amount, (float) $fee->vat, $fee->is_vat_included, $fee->description]);
        $this->assertNull($other->description);
        $this->assertSame($users, User::query()->orderBy('id')->get(['id', 'email', 'password'])->toArray());
        // A session is of the moment: its table made again, empty.
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_statements_are_read_one_at_a_time_however_written(): void
    {
        $sql = "-- a comment\nSET NAMES utf8mb4;\n\nCREATE TABLE `t` (\n  `a` text\n);\nINSERT INTO `t` VALUES ('a;b'),('it\\'s'),(\"x;\"),('');\n# another\nINSERT INTO `t` VALUES ('last')";
        $file = fopen('php://memory', 'r+');
        fwrite($file, $sql);

        $statements = iterator_to_array(SqlStatements::from($file, 0), false);

        $this->assertSame(['SET NAMES utf8mb4', "CREATE TABLE `t` (\n  `a` text\n)", "INSERT INTO `t` VALUES ('a;b'),('it\\'s'),(\"x;\"),('')", "INSERT INTO `t` VALUES ('last')"], array_column($statements, 'sql'));

        // Gone on with from where one ended: the rest.
        $rest = iterator_to_array(SqlStatements::from($file, $statements[1]['next']), false);
        $this->assertSame(array_column(array_slice($statements, 2), 'sql'), array_column($rest, 'sql'));
    }

    public function test_a_step_cut_off_goes_on_without_running_a_statement_twice(): void
    {
        Matter::factory()->create(['number' => '1']);
        $name = $this->backUp();
        $restore = app(DatabaseRestore::class);
        $key = $restore->prepare($name);
        $store = app(BackupStore::class);

        // Imported to its end, then told it was cut off just before its last INSERT into matters: that one ran.
        foreach (range(1, 200) as $ignored) {
            $state = $restore->step($key);
            if ($state['phase'] === 'migrate' || $state['error']) {
                break;
            }
        }
        $this->assertSame('migrate', $state['phase'], (string) $state['error']);
        $sql = (string) file_get_contents($store->path($state['sql']));
        $at = strrpos($sql, 'INSERT INTO `matters`');
        $store->saveState(DatabaseRestore::STATE, [...$state, 'phase' => 'import', 'offset' => $at, 'in_step' => true]);

        $state = $restore->step($key);
        $this->assertNull($state['error']);
        $this->assertSame(1, Matter::count());

        // Not cut off: the same statement again is an error, shown.
        $store->saveState(DatabaseRestore::STATE, [...$state, 'phase' => 'import', 'offset' => $at, 'in_step' => false]);
        $this->assertNotNull($restore->step($key)['error']);
    }

    public function test_the_last_five_made_are_kept(): void
    {
        $store = app(BackupStore::class);
        foreach (range(1, 7) as $i) {
            touch($store->path('wakeel-2026010'.$i.'-120000.sql.gz'), now()->subDays(10 - $i)->getTimestamp());
        }
        touch($store->path('uploaded-20250101-000000.sql'), now()->subYear()->getTimestamp());

        $store->prune();

        // The newest five; an uploaded one is left to whoever put it there.
        $this->assertSame(['wakeel-20260107-120000.sql.gz', 'wakeel-20260106-120000.sql.gz', 'wakeel-20260105-120000.sql.gz', 'wakeel-20260104-120000.sql.gz', 'wakeel-20260103-120000.sql.gz', 'uploaded-20250101-000000.sql'],
            $store->all()->pluck('name')->all());
    }

    public function test_only_a_super_admin_backs_up_downloads_or_restores(): void
    {
        $name = $this->backUp();

        Livewire::test(SystemUpdates::class)->assertSee(__('Database backups'))->assertSee($name);
        $this->get(route('system-updates.backup', $name))->assertOk()->assertDownload($name);
        $this->get(route('system-updates.backup', '..%2F.env'))->assertNotFound();

        // An administrator who manages updates, not a super admin.
        $this->actingAs(User::factory()->create());
        Livewire::test(SystemUpdates::class)->assertDontSee(__('Database backups'));
        $this->get(route('system-updates.backup', $name))->assertForbidden();
    }

    public function test_a_restore_asks_for_the_password_and_the_word_and_opens_only_with_its_key(): void
    {
        $name = $this->backUp();

        Livewire::test(SystemUpdates::class)
            ->callAction(TestAction::make('restoreDatabase')->schemaComponent('database-backups', 'content'), ['from' => 'server', 'backup' => $name, 'password' => 'wrong', 'confirm' => 'restore'])
            ->assertHasActionErrors(['password', 'confirm']);
        $this->assertNull(app(BackupStore::class)->state(DatabaseRestore::STATE));

        Livewire::test(SystemUpdates::class)
            ->callAction(TestAction::make('restoreDatabase')->schemaComponent('database-backups', 'content'), ['from' => 'server', 'backup' => $name, 'password' => 'secret-pass', 'confirm' => 'RESTORE'])
            ->assertHasNoActionErrors()
            ->assertRedirectContains('/system-restore/');

        // Its page: by its key only, no login needed.
        auth()->logout();
        $this->get(route('system-restore.show', 'not-the-key'))->assertNotFound();
        $this->post(route('system-restore.step', 'not-the-key'))->assertNotFound();
    }
}
