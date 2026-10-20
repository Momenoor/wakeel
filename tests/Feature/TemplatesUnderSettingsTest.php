<?php

namespace Tests\Feature;

use App\Filament\Mms\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Mms\Resources\LetterFonts\LetterFontResource;
use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Filament\Mms\Resources\LetterItems\LetterItemResource;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use App\Filament\Mms\Resources\WhatsAppTemplates\WhatsAppTemplateResource;
use App\Filament\Shared\Clusters\Settings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The templates and what a letter is built from live under Settings, in a Templates group of their own; an old
 * address under /mms/templates still finds its page.
 */
class TemplatesUnderSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_is_under_settings_in_the_templates_group(): void
    {
        Filament::setCurrentPanel('mms');

        foreach ([LetterTemplateResource::class, LetterItemResource::class, LetterheadResource::class, SignatureLayoutResource::class,
            LetterFontResource::class, EmailTemplateResource::class, WhatsAppTemplateResource::class] as $resource) {
            $this->assertSame(Settings::class, $resource::getCluster(), $resource);
            $this->assertSame(__('Templates'), $resource::getNavigationGroup(), $resource);
        }

        $this->assertStringContainsString('/mms/settings/letter-templates', LetterTemplateResource::getUrl('index'));
    }

    public function test_an_old_address_finds_its_page(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());

        $this->get('/mms/templates/letter-templates/5/edit?tab=body')
            ->assertRedirect('/mms/settings/letter-templates/5/edit?tab=body');
    }
}
