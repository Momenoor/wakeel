<?php

namespace Tests\Feature;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every relation manager names its tab (getTitle) and its records
 * (getModelLabel / getPluralModelLabel) — the model label is what the
 * Create / Edit / Delete modal headings are built from — and each of those
 * reads differently in Arabic, so none is left in English.
 */
class RelationManagerTranslationTest extends TestCase
{
    /**
     * @return list<class-string<RelationManager>>
     */
    private function relationManagers(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Filament'))->name('*RelationManager.php') as $file) {
            $class = 'App\\'.Str::of($file->getRealPath())->after(app_path().DIRECTORY_SEPARATOR)->beforeLast('.php')->replace(DIRECTORY_SEPARATOR, '\\');

            if (class_exists($class) && is_subclass_of($class, RelationManager::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function label(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);

        return (string) ($method === 'getTitle'
            ? $reflection->invoke(null, new class extends Model {}, '')
            : $reflection->invoke(null));
    }

    public function test_every_relation_manager_has_translated_labels(): void
    {
        $classes = $this->relationManagers();
        $this->assertNotEmpty($classes);

        $problems = [];

        foreach ($classes as $class) {
            foreach (['getTitle', 'getModelLabel', 'getPluralModelLabel'] as $method) {
                if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName() !== $class) {
                    $problems[] = class_basename($class)."::{$method}() is not defined";

                    continue;
                }

                app()->setLocale('en');
                $english = $this->label($class, $method);
                app()->setLocale('ar');
                $arabic = $this->label($class, $method);

                if ($english === $arabic) {
                    $problems[] = class_basename($class)."::{$method}() has no Arabic translation for \"{$english}\"";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
