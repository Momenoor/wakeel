<?php

namespace Tests\Feature;

use App\Filament\Mms\Pages\Chat;
use App\Filament\Mms\Resources\BulkMailCampaigns\BulkMailCampaignResource;
use App\Filament\Mms\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Mms\Resources\Letterheads\LetterheadResource;
use App\Filament\Mms\Resources\LetterItems\LetterItemResource;
use App\Filament\Mms\Resources\LetterTemplates\LetterTemplateResource;
use App\Filament\Mms\Resources\MailSenders\MailSenderResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Communication menu: the daily tools first, then each part next to
 * what it's built from — letter templates with their items and
 * letterheads, then the covering emails and the mailboxes mail goes from.
 */
class CommunicationNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_communication_menu_is_in_order(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('mms');

        $html = $this->get(Chat::getUrl())->assertSuccessful()->getContent();

        $order = [
            Chat::getUrl(),
            BulkMailCampaignResource::getUrl(),
            LetterTemplateResource::getUrl(),
            LetterItemResource::getUrl(),
            LetterheadResource::getUrl(),
            EmailTemplateResource::getUrl(),
            MailSenderResource::getUrl(),
        ];

        $positions = array_map(fn (string $url) => strpos($html, 'href="'.$url.'"'), $order);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }
}
