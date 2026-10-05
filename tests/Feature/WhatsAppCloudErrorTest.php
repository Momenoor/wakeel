<?php

namespace Tests\Feature;

use App\Services\WhatsAppCloud;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Meta's refusals name the mismatch, not what to do: the commonest come
 * with an explanation first.
 */
class WhatsAppCloudErrorTest extends TestCase
{
    public function test_a_template_whose_header_differs_in_meta_says_what_to_fix(): void
    {
        config(['services.whatsapp.token' => 'wa-token', 'services.whatsapp.phone_id' => '1234']);
        app()->setLocale('ar');

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => '(#132012) Parameter format does not match format in the created template',
            'code' => 132012,
            'error_data' => ['details' => 'header: Format mismatch, expected TEXT, received DOCUMENT'],
        ]], 400)]);

        try {
            app(WhatsAppCloud::class)->sendTemplate('971500000000', 'minutes_sign', 'ar', ['name' => 'محمود'], ['id' => 'media-1', 'filename' => 'minutes.pdf']);
            $this->fail('No exception');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('القالب المعتمد في Meta لا يطابق إعداده في وكيل', $e->getMessage());
            // Meta's own words kept after it.
            $this->assertStringContainsString('expected TEXT, received DOCUMENT', $e->getMessage());
        }
    }

    public function test_an_unknown_refusal_reads_as_meta_wrote_it(): void
    {
        config(['services.whatsapp.token' => 'wa-token', 'services.whatsapp.phone_id' => '1234']);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Something else', 'code' => 999]], 400)]);

        $this->expectExceptionMessage('WhatsApp 400: Something else');

        app(WhatsAppCloud::class)->sendTemplate('971500000000', 'minutes_sign', 'ar', []);
    }
}
