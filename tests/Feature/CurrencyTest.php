<?php

namespace Tests\Feature;

use App\Filament\Mms\Widgets\CollectionsAgingWidget;
use App\Filament\Pms\Widgets\PmsRevenueChartWidget;
use App\Support\Currency;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\TextColumn;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The UAE Dirham sign, before the amount, wherever an amount is shown.
 */
class CurrencyTest extends TestCase
{
    public function test_an_amount_is_the_sign_then_the_number(): void
    {
        $html = (string) Currency::format(1234.5);

        $this->assertMatchesRegularExpression('~<span class="wakeel-aed" role="img" aria-label="AED">D</span> 1,234\.50</span>~', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertNull(Currency::format(null));
        $this->assertSame('AED 1,234.50', Currency::text(1234.5));
    }

    public function test_labels_show_the_sign_in_english_and_arabic(): void
    {
        $this->assertStringStartsWith('Amount (<span class="wakeel-aed"', (string) Currency::label('Amount (AED)'));
        $this->assertStringStartsWith('المبلغ (<span class="wakeel-aed"', (string) Currency::label('المبلغ (درهم)'));
        // Escaped like any label.
        $this->assertStringContainsString('&lt;b&gt;', (string) Currency::label('<b> AED'));
    }

    public function test_in_a_sentence_the_sign_moves_before_the_amount(): void
    {
        $html = (string) Currency::label('5,000.00 AED outstanding');

        $this->assertMatchesRegularExpression('~^<span dir="ltr"[^>]*><span class="wakeel-aed"[^>]*>D</span> 5,000\.00</span> outstanding$~', $html);
    }

    public function test_the_font_is_shipped_and_loaded(): void
    {
        $this->assertFileExists(public_path('fonts/AED.otf'));
        $this->assertStringContainsString('src: url("./AED.otf")', (string) file_get_contents(public_path('fonts/aed.css')));
        $this->assertStringContainsString('fonts/aed.css', (string) Currency::fontLink());

        // Every panel page loads it.
        $this->assertStringContainsString('fonts/aed.css', FilamentView::renderHook(PanelsRenderHook::HEAD_END)->toHtml());

        // The print pages that are documents of their own load it too.
        foreach (['filament/pages/incentive/calculation-print.blade.php', 'filament/pages/incentive/calculation-print-assistant.blade.php', 'filament/pms/quotation-print.blade.php'] as $view) {
            $this->assertStringContainsString('Currency::fontLink()', (string) file_get_contents(resource_path('views/'.$view)), $view);
        }
    }

    public function test_the_money_charts_title_their_value_axis_with_the_sign(): void
    {
        foreach ([PmsRevenueChartWidget::class, CollectionsAgingWidget::class] as $widget) {
            $options = (new ReflectionMethod($widget, 'getOptions'))->invoke(new $widget);

            $this->assertSame('D', $options['scales']['y']['title']['text'], $widget);
            $this->assertSame('AED', $options['scales']['y']['title']['font']['family'], $widget);
        }
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
        $this->assertStringContainsString('>D</span> 2,500.00', (string) $column->formatState(2500));
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
