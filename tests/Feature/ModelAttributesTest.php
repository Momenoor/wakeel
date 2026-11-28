<?php

namespace Tests\Feature;

use App\Models\Matter;
use App\Models\Party;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The models' accessors and mutators written as Attribute methods (get:/set:), none as getXAttribute() /
 * setXAttribute() — and those converted read as before.
 */
class ModelAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_model_has_an_accessor_or_mutator_of_the_old_form(): void
    {
        $old = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            if (preg_match_all('/function\s+(get|set)[A-Z]\w*Attribute\s*\(/', (string) file_get_contents($file), $m)) {
                $old[] = basename($file).': '.implode(', ', $m[0]);
            }
        }

        $this->assertSame([], $old);
    }

    public function test_the_converted_ones_read_as_before(): void
    {
        $matter = Matter::factory()->create(['number' => '77', 'year' => '2026']);
        $this->assertSame('77/2026', $matter->reference);

        $party = Party::factory()->create(['role' => ['role' => ['expert'], 'type' => ['assistant'], 'field' => null], 'email' => [], 'phone' => []]);
        $this->assertSame(['expert'], $party->refresh()->role['role']);
        $this->assertSame(['assistant'], $party->role['type']);
        $this->assertSame([['role' => 'expert', 'type' => 'assistant', 'field' => null]], json_decode((string) $party->getRawOriginal('role'), true));

        $setting = Setting::set('an_amount', 12);
        $this->assertSame(12, $setting->refresh()->casted_value);
    }
}
