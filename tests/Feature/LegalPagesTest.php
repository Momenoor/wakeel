<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The privacy policy and terms of use: public pages, the addresses Meta
 * asks for before the WhatsApp app is published.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_policy_is_public_with_the_data_deletion_instructions(): void
    {
        Setting::set('company_name', 'JPA Emirates');
        config(['mail.from.address' => 'info@example.test']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('سياسة الخصوصية')
            ->assertSee('Privacy Policy')
            ->assertSee('JPA Emirates')
            ->assertSee('WhatsApp Business')
            ->assertSee('id="data-deletion"', false)
            ->assertSee('mailto:info@example.test', false);
    }

    public function test_the_terms_are_public(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('شروط الاستخدام')
            ->assertSee('Terms of Use')
            ->assertSee(route('legal.privacy'), false);
    }
}
