<?php

namespace Tests\Feature;

use App\Support\Currency;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The UAE Dirham sign, after the amount, wherever an amount is shown.
 */
class CurrencyTest extends TestCase
{
    public function test_an_amount_is_the_number_then_the_sign(): void
    {
        $html = (string) Currency::format(1234.5);

        $this->assertMatchesRegularExpression('~1,234\.50 <svg class="wakeel-aed"[^>]*aria-label="AED"~', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertNull(Currency::format(null));
        $this->assertSame('1,234.50 AED', Currency::text(1234.5));
    }

    public function test_labels_show_the_sign_in_english_and_arabic(): void
    {
        $this->assertStringStartsWith('Amount (<svg', (string) Currency::label('Amount (AED)'));
        $this->assertStringStartsWith('المبلغ (<svg', (string) Currency::label('المبلغ (درهم)'));
        // Escaped like any label.
        $this->assertStringContainsString('&lt;b&gt;', (string) Currency::label('<b> AED'));
    }

    public function test_the_pdf_sign_is_an_image(): void
    {
        $this->assertMatchesRegularExpression('~^<img src="data:image/svg\+xml;base64,[A-Za-z0-9+/=]+" alt="AED"~', (string) Currency::pdfSymbol());
    }

    public function test_aed_columns_format_with_the_sign(): void
    {
        $column = TextColumn::make('amount')->aed();

        $this->assertTrue($column->isHtml());
        $this->assertStringStartsWith('<span dir="ltr"', (string) $column->formatState(2500));
        $this->assertStringContainsString('2,500.00 <svg', (string) $column->formatState(2500));
    }

    public function test_no_amount_is_shown_with_the_letters_aed_any_more(): void
    {
        $left = collect(File::allFiles(app_path('Filament')))
            ->filter(fn ($file) => preg_match("~money\('AED'\)|(suffix|prefix)\('AED'\)~", $file->getContents()))
            ->map(fn ($file) => $file->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $left);
    }
}
