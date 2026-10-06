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
        Setting::set('company_email', 'info@jpaemirates.com');

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('سياسة الخصوصية')
            ->assertSee('Privacy Policy')
            ->assertSee('JPA Emirates')
            ->assertSee('WhatsApp Business')
            ->assertSee('id="data-deletion"', false)
            ->assertSee('mailto:info@jpaemirates.com', false);
    }

    public function test_the_terms_are_public(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('شروط الاستخدام')
            ->assertSee('Terms of Use')
            ->assertSee(route('legal.privacy'), false);
    }

    public function test_the_contact_details_come_from_the_settings(): void
    {
        Setting::set('company_phone', '+971 4 328 7778');
        Setting::set('company_whatsapp', '+971 56 107 5965');
        Setting::set('company_email', 'office@example.ae');

        foreach (['/terms', '/privacy'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('href="tel:+97143287778"', false)
                ->assertSee('href="https://wa.me/971561075965"', false)
                ->assertSee('mailto:office@example.ae', false)
                ->assertDontSee('info@jpaemirates.com');
        }

        // None set: no empty lines.
        Setting::set('company_phone', '');
        $this->get('/terms')->assertDontSee('tel:', false);
    }
}
