<?php

namespace Tests\Feature;

use App\Support\Honorific;
use Tests\TestCase;

/**
 * Greetings that agree with the title: السيد/ and الأستاذ/ … المحترم,
 * السيدة/ and الأستاذة/ … المحترمة, السادة/ … المحترمين — and a title
 * already on the name never written twice.
 */
class HonorificTest extends TestCase
{
    public function test_each_title_takes_its_own_honorific(): void
    {
        $this->assertSame('الأستاذ/ أحمد علي المحترم', Honorific::salutation('الأستاذ/ أحمد علي'));
        $this->assertSame('السيد/ أحمد علي المحترم', Honorific::salutation('السيد/ أحمد علي'));
        $this->assertSame('الأستاذة/ موزة هاشل خلف الغيث المحترمة', Honorific::salutation('الأستاذة/ موزة هاشل خلف الغيث'));
        $this->assertSame('السيدة/ منى المحترمة', Honorific::salutation('السيدة/ منى'));
        $this->assertSame('السادة/ شركة المهاد المحترمين', Honorific::salutation('السادة/ شركة المهاد'));
        // No title: the plural, polite for anyone.
        $this->assertSame('السادة/ محمد علي المحترمين', Honorific::salutation('محمد علي'));
    }

    public function test_a_title_written_twice_is_kept_once(): void
    {
        $this->assertSame('الأستاذة/ موزة هاشل المحترمة', Honorific::salutation('السادة/ الأستاذة/ موزة هاشل'));
        $this->assertSame('الأستاذ/ أحمد المحترم', Honorific::salutation('الاستاذ / أحمد'));
        // "السيد" without a slash is a name, not a title.
        $this->assertSame(['title' => 'السيد/', 'name' => 'السيد أحمد'], Honorific::split('السيد/ السيد أحمد'));
    }

    public function test_the_recipient_placeholders(): void
    {
        $this->assertSame([
            'recipient.name' => 'موزة هاشل خلف الغيث',
            'recipient.title' => 'الأستاذة/',
            'recipient.suffix' => 'المحترمة',
            'recipient.salutation' => 'الأستاذة/ موزة هاشل خلف الغيث المحترمة',
        ], Honorific::values('الأستاذة/ موزة هاشل خلف الغيث'));

        $this->assertSame('Mr. John Smith', Honorific::salutation('Mr. John Smith', false));
    }
}
