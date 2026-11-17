<?php

namespace Tests\Feature;

use App\Services\License\LicenseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The license server at more than one address (license.jpaemirates.com, license.jpaemirates.website): the next
 * asked when one cannot be reached or answers with an error page, and the one that answered asked first after.
 */
class LicenseServerAddressesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'license.server_url' => 'https://license.example.com',
            'license.fallback_urls' => 'https://license.example.website/',
        ]);
    }

    public function test_an_error_page_at_the_first_address_gives_way_to_the_next(): void
    {
        Http::fake([
            'license.example.com/*' => Http::response('<h1>Not Found</h1>', 404),
            'license.example.website/*' => Http::response(['valid' => true, 'latest_version' => '3.17.19']),
        ]);

        $result = app(LicenseClient::class)->verify('KEY', 'fp');

        $this->assertTrue($result['valid']);
        $this->assertSame('3.17.19', $result['latest_version']);
        Http::assertSentCount(2);

        // Next time, the one that answered first.
        $this->assertSame(['https://license.example.website', 'https://license.example.com'], LicenseClient::servers());
    }

    public function test_a_real_answer_is_final_even_when_it_refuses(): void
    {
        Http::fake(['*' => Http::response(['valid' => false, 'reason' => 'not_found'], 404)]);

        $result = app(LicenseClient::class)->verify('KEY', 'fp');

        $this->assertFalse($result['valid']);
        $this->assertSame('not_found', $result['reason']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://license.example.com/api/v1/license/verify'));
    }

    public function test_no_address_answering_is_a_server_error(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->assertSame('server_error', app(LicenseClient::class)->verify('KEY', 'fp')['reason']);
        Http::assertSentCount(2);
    }
}
