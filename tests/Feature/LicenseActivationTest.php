<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use App\Services\Installer\EnvironmentFileWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * A key entered on the license page is activated with this installation's identity — made there and then
 * when it has none, since the license server answers a request without one "invalid_request".
 */
class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
        Http::preventStrayRequests();
        Http::fake(['*/api/v1/license/activate' => fn (Request $request) => Http::response(
            filled($request['fingerprint'])
                ? ['valid' => true, 'plan' => 'pro', 'expires_at' => now()->addYear()->toIso8601String()]
                : ['valid' => false, 'reason' => 'invalid_request'],
        )]);
    }

    public function test_an_installation_without_an_id_gets_one_and_the_key_is_activated(): void
    {
        config(['license.installation_id' => null]);

        $writer = Mockery::mock(EnvironmentFileWriter::class);
        $writer->shouldReceive('set')->once()->with(Mockery::on(fn (array $values) => array_keys($values) === ['INSTALLATION_ID'] && filled($values['INSTALLATION_ID'])));
        $this->app->instance(EnvironmentFileWriter::class, $writer);

        $this->post(route('license.activate'), ['license_key' => 'KEY-1234'])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => filled($request['fingerprint']) && $request['license_key'] === 'KEY-1234');

        $license = License::sole();
        $this->assertSame('active', $license->status);
        // The same identity the server was given, kept for the later check-ins.
        $this->assertSame(config('license.installation_id'), $license->fingerprint);
    }

    public function test_an_installation_with_an_id_keeps_it(): void
    {
        config(['license.installation_id' => 'install-abc']);

        $writer = Mockery::mock(EnvironmentFileWriter::class);
        $writer->shouldNotReceive('set');
        $this->app->instance(EnvironmentFileWriter::class, $writer);

        $this->post(route('license.activate'), ['license_key' => 'KEY-1234'])->assertRedirect('/');

        Http::assertSent(fn (Request $request) => $request['fingerprint'] === 'install-abc');
        $this->assertSame('install-abc', License::sole()->fingerprint);
    }
}
