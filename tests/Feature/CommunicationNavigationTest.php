<?php

namespace Tests\Feature;

use App\Filament\Mms\Clusters\Templates;
use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Filament\Mms\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Filament\Mms\Resources\LetterItems\LetterItemResource;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\SignatureLayouts\SignatureLayoutResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Communication menu: the daily tools first, then one Templates entry
 * whose own menu lists each part next to what it's built from — letter
 * templates with their items and letterheads and signature blocks, then
 * the covering emails.
 */
class CommunicationNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('mms');
    }

    public function test_the_communication_menu_is_in_order(): void
    {
        $html = $this->get(Chat::getUrl())->assertSuccessful()->getContent();

        $this->assertInOrder($html, [
            Chat::getUrl(),
            BulkMailCampaignResource::getUrl(),
            Templates::getUrl(),
        ]);

        // The templates themselves are only in the Templates menu.
        $this->assertStringNotContainsString('href="'.LetterItemResource::getUrl().'"', $html);
    }

    public function test_the_templates_menu_is_in_order(): void
    {
        $html = $this->get(LetterTemplateResource::getUrl())->assertSuccessful()->getContent();

        $this->assertInOrder($html, [
            LetterTemplateResource::getUrl(),
            LetterItemResource::getUrl(),
            LetterheadResource::getUrl(),
            SignatureLayoutResource::getUrl(),
            EmailTemplateResource::getUrl(),
        ]);
    }

    /**
     * @param  list<string>  $urls
     */
    private function assertInOrder(string $html, array $urls): void
    {
        $positions = array_map(fn (string $url) => strpos($html, 'href="'.$url.'"'), $urls);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }
}
