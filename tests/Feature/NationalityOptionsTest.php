<?php

namespace Tests\Feature;

use App\Support\Nationalities;
use Tests\TestCase;

class NationalityOptionsTest extends TestCase
{
    public function test_the_options_are_in_alphabetical_order_in_english(): void
    {
        app()->setLocale('en');

        $options = Nationalities::options();
        $sorted = $options;
        (new \Collator('en'))->asort($sorted);

        $this->assertSame($sorted, $options);
        $this->assertSame('AE', array_search(Nationalities::name('AE', false), $options, true));
    }

    public function test_the_options_are_in_arabic_order_in_arabic(): void
    {
        app()->setLocale('ar');

        $options = Nationalities::options();
        $names = array_values($options);
        $sorted = $names;
        (new \Collator('ar'))->sort($sorted);

        $this->assertSame($sorted, $names);
        $this->assertSame(Nationalities::name('AE', true), $options['AE']);
    }

    public function test_sorting_them_raises_no_deprecation(): void
    {
        $deprecations = [];
        set_error_handler(function (int $no, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            app()->setLocale('en');
            Nationalities::options();
            app()->setLocale('ar');
            Nationalities::options();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }
}
