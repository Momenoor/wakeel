<?php

namespace Tests\Feature;

use App\Enums\BulkMailCampaignStatus;
use App\Enums\BulkMailRecipientStatus;
use App\Filament\Mms\Resources\BulkMailCampaigns\Pages\ViewBulkMailCampaign;
use App\Filament\Mms\Resources\BulkMailCampaigns\RelationManagers\RecipientsRelationManager;
use App\Filament\Mms\Resources\BulkMailCampaigns\Widgets\CampaignStatsWidget;
use App\Models\BulkMailCampaign;
use App\Models\BulkMailRecipient;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The campaign page follows the sending live: status, Start/Pause, stats
 * and each recipient's status refresh while it's open.
 */
class BulkMailCampaignLiveTest extends TestCase
{
    use RefreshDatabase;

    private BulkMailCampaign $campaign;

    private BulkMailRecipient $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail_senders.senders.test' => [
            'username' => 'sender@example.com', 'address' => 'sender@example.com', 'name' => 'Test Sender',
            'password' => 'secret', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls',
        ]]);

        $this->actingAs(User::factory()->create()->assignRole(
            Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web'])
        ));
        Filament::setCurrentPanel('admin');

        $this->campaign = BulkMailCampaign::create([
            'name' => 'Notice', 'subject' => 's', 'body' => 'b', 'from_sender_key' => 'test',
            'daily_send_limit' => 60, 'status' => BulkMailCampaignStatus::Active, 'total_recipients' => 1,
            'created_by' => auth()->id(),
        ]);

        $this->recipient = BulkMailRecipient::create([
            'campaign_id' => $this->campaign->id, 'email' => ['a@example.com'], 'name' => 'Alpha',
        ]);
    }

    public function test_an_active_campaign_polls_and_a_finished_one_does_not(): void
    {
        config(['filament.broadcasting.echo' => null]);

        Livewire::test(ViewBulkMailCampaign::class, ['record' => $this->campaign->getRouteKey()])
            ->assertSeeHtml('wire:poll.10s="refreshCampaign"');

        $this->campaign->update(['status' => BulkMailCampaignStatus::Completed]);

        Livewire::test(ViewBulkMailCampaign::class, ['record' => $this->campaign->getRouteKey()])
            ->assertDontSeeHtml('refreshCampaign');
    }

    public function test_with_pusher_the_poll_is_only_a_slow_safety_net(): void
    {
        config(['filament.broadcasting.echo' => ['broadcaster' => 'pusher', 'key' => 'k']]);

        Livewire::test(ViewBulkMailCampaign::class, ['record' => $this->campaign->getRouteKey()])
            ->assertSeeHtml('wire:poll.60s="refreshCampaign"');
    }

    public function test_a_refresh_picks_up_the_new_status_and_tells_the_stats_and_table(): void
    {
        $page = Livewire::test(ViewBulkMailCampaign::class, ['record' => $this->campaign->getRouteKey()])
            ->assertFormSet(['status' => BulkMailCampaignStatus::Active]);

        // What the sending did meanwhile, in another process.
        $this->campaign->update(['status' => BulkMailCampaignStatus::Completed, 'sent_count' => 1]);

        $page->call('refreshCampaign')
            ->assertFormSet(['status' => BulkMailCampaignStatus::Completed])
            ->assertDispatched('bulk-mail-campaign-refreshed')
            ->assertActionHidden('pause_campaign');
    }

    public function test_the_recipients_table_shows_the_new_status_on_refresh(): void
    {
        $table = Livewire::test(RecipientsRelationManager::class, [
            'ownerRecord' => $this->campaign,
            'pageClass' => ViewBulkMailCampaign::class,
        ])->assertTableColumnStateSet('status', BulkMailRecipientStatus::Pending, $this->recipient);

        $this->recipient->update(['status' => BulkMailRecipientStatus::Sent, 'sent_at' => now()]);

        $table->dispatch('bulk-mail-campaign-refreshed')
            ->assertTableColumnStateSet('status', BulkMailRecipientStatus::Sent, $this->recipient);
    }

    public function test_the_stats_follow_the_refresh(): void
    {
        $stats = Livewire::test(CampaignStatsWidget::class, ['record' => $this->campaign]);

        $this->campaign->update(['sent_count' => 1]);

        $stats->dispatch('bulk-mail-campaign-refreshed')
            ->assertSee('100%');
    }

    public function test_only_users_who_may_view_the_campaign_can_follow_it_live(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
            'driver' => 'pusher', 'key' => 'k', 'secret' => 's', 'app_id' => '1',
            'options' => ['cluster' => 'ap2', 'useTLS' => true],
        ]]);
        require base_path('routes/channels.php');

        $channel = 'private-bulk-mail-campaign.'.$this->campaign->id;

        $this->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertSuccessful();

        $this->actingAs(User::factory()->create()); // no permissions
        $this->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertForbidden();
    }
}
