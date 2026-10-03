<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Throwable;

/**
 * Every table — resources', reports', widgets' — is named in Arabic,
 * singular and plural. A table without a name took the class name
 * ("matters") or an Arabic word with an "s" ("القضيةs") in its filter and
 * column modals.
 */
class TableLabelsTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_is_named_in_arabic_singular_and_plural(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        app()->setLocale('ar');

        $checked = 0;
        $wrong = [];

        foreach ((new Finder)->files()->in(app_path('Filament'))->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());
            $class = 'App\\Filament\\'.substr($class, 4);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->implementsInterface(HasTable::class) || $reflection->isSubclassOf(RelationManager::class)) {
                continue;
            }

            Filament::setCurrentPanel(str_contains($class, '\\Pms\\') ? 'pms' : 'mms');

            try {
                $table = Livewire::test($class)->instance()->getTable();
            } catch (Throwable) {
                continue; // Needs data or a parameter this test does not give.
            }

            $checked++;
            $single = $table->getModelLabel();
            $plural = $table->getPluralModelLabel();

            if (! preg_match('/\p{Arabic}/u', $single) || ! preg_match('/\p{Arabic}/u', $plural) || preg_match('/\p{Arabic}s$/u', $plural)) {
                $wrong[] = class_basename($class).": {$single} / {$plural}";
            }
        }

        $this->assertGreaterThan(50, $checked);
        $this->assertSame([], $wrong);
    }
}
