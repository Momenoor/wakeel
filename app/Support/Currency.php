<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The UAE Dirham sign, shown wherever an amount is shown on screen or in
 * print — before the number ("⃁ 1,234.00").
 *
 * On screen it is text in the "AED" font (which draws the sign for "D"),
 * so it takes the size, weight and colour around it; in PDFs (mPDF) an
 * image of the same shape — mPDF cannot embed a CFF-outlined OpenType font. Plain text — Excel exports, notifications, email
 * subjects — keeps "AED": the sign's Unicode character (U+20C3) is too new
 * for most fonts and would show as an empty box.
 */
final class Currency
{
    public const CODE = 'AED';

    private const PATH = 'M 140.752 112.193 C 148.470 125.052, 151.902 133.805, 154.668 147.688 C 156.173 155.246, 156.428 162.619, 156.461 199.500 L 156.500 242.500 135 242.500 C 111.451 242.500, 111.249 242.458, 102.750 235.779 L 99 232.832 99 238.779 C 99 257.409, 107.298 272.270, 121.177 278.499 C 127.012 281.117, 128.048 281.252, 141.661 281.154 L 156 281.050 156 300.614 L 156 320.178 135.750 319.807 C 113.663 319.403, 111.205 318.876, 103.809 312.955 C 101.778 311.330, 99.803 310, 99.420 310 C 99.036 310, 98.956 313.938, 99.242 318.750 C 100.364 337.653, 109.004 350.835, 123.797 356.208 C 127.383 357.511, 131.898 357.927, 142.810 357.958 L 157.120 358 156.543 397.750 C 155.857 444.919, 155.436 453.402, 153.354 462 C 151.278 470.572, 145.179 483.588, 141.017 488.328 L 137.686 492.122 223.593 491.728 C 298.160 491.387, 311.018 491.109, 321 489.623 C 389.147 479.476, 435.799 449.966, 460.879 401.140 C 467.153 388.927, 472.133 376.032, 474.546 365.750 L 476.366 358 499.433 358.006 C 512.152 358.009, 524.161 358.473, 526.203 359.040 C 528.240 359.605, 532.239 361.912, 535.090 364.166 L 540.274 368.263 539.759 357.881 C 539.076 344.092, 536.537 337.473, 529.032 329.913 C 522.281 323.112, 514.971 320.003, 505.712 319.994 C 502.296 319.991, 495.787 319.699, 491.250 319.346 L 483 318.704 483 299.764 L 483 280.825 503.750 281.173 C 526.739 281.559, 529.761 282.268, 536.862 288.935 L 540.224 292.092 539.830 281.296 C 539.499 272.247, 539.007 269.575, 536.788 264.782 C 533.453 257.580, 526.597 250.194, 519.774 246.451 C 514.613 243.621, 514.094 243.547, 495.635 243.029 L 476.770 242.500 472.502 229.243 C 451.107 162.781, 400.204 123.389, 320.030 111.249 C 311.192 109.911, 295.779 109.593, 224.134 109.271 L 138.767 108.887 140.752 112.193 M 215 185.338 L 215 243 313 243 C 405.668 243, 411 242.905, 410.997 241.250 C 410.992 237.760, 405.011 215.940, 401.427 206.337 C 396.843 194.053, 395.122 190.618, 388.238 180 C 370.821 153.139, 342.527 135.951, 306.822 130.543 C 299.495 129.434, 284.677 128.791, 255.750 128.328 L 215 127.676 215 185.338 M 215 300.500 L 215 320 315 320 L 415 320 415 300.500 L 415 281 315 281 L 215 281 215 300.500 M 215 415.636 L 215 473.273 250.750 472.690 C 272.490 472.335, 291.007 471.537, 298 470.652 C 331.449 466.420, 353.014 456.811, 373.339 437.081 C 390.132 420.780, 400.668 401.041, 407.930 372.278 C 409.618 365.589, 411 359.641, 411 359.058 C 411 358.321, 381.277 358, 313 358 L 215 358 215 415.636';

    /**
     * The sign alone, inline, sized and coloured like the text around it.
     */
    public static function symbol(): HtmlString
    {
        // The "AED" font (public/fonts/aed.css) draws the sign for "D": real
        // text, so it takes the size, weight and colour around it.
        return new HtmlString('<span class="wakeel-aed" role="img" aria-label="'.self::CODE.'">D</span>');
    }

    /**
     * The stylesheet that loads the font — in every panel page (a render
     * hook) and in the print pages that are documents of their own.
     */
    public static function fontLink(): HtmlString
    {
        return new HtmlString('<link rel="stylesheet" href="'.e(asset('fonts/aed.css')).'">');
    }

    /**
     * An amount with the sign before it, kept on one line and in that order
     * in Arabic too. Null for no amount.
     */
    public static function format(float|int|string|null $amount, int $decimals = 2): ?HtmlString
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return new HtmlString(
            '<span dir="ltr" style="white-space:nowrap">'.self::symbol().' '.e(number_format((float) $amount, $decimals)).'</span>'
        );
    }

    /**
     * The sign for a PDF (mPDF draws an <img> of the SVG reliably).
     */
    public static function pdfSymbol(string $height = '0.8em'): HtmlString
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="90 100 460 400"><path fill="black" fill-rule="evenodd" d="'.self::PATH.'"/></svg>';

        return new HtmlString('<img src="data:image/svg+xml;base64,'.base64_encode($svg).'" alt="'.self::CODE.'" style="height:'.$height.';vertical-align:middle">');
    }

    /**
     * An amount with the sign before it, for a PDF.
     */
    public static function pdfFormat(float|int|string|null $amount, int $decimals = 2): HtmlString
    {
        return new HtmlString(self::pdfSymbol().' '.e(number_format((float) $amount, $decimals)));
    }

    /**
     * A translated label with its currency word ("AED", "درهم") shown as
     * the sign — "Amount (AED)" / "المبلغ (درهم)" → "Amount (⃁)". The
     * translations keep their words, so Arabic stays as it is.
     */
    public static function label(string $label): HtmlString
    {
        $label = e($label);
        // A placeholder until the end: the sign's own markup says "AED"
        // (aria-label), which the word replacement must not touch.
        $mark = "\u{E000}";

        // "5,000.00 AED" in a sentence: the sign moves before the amount.
        $label = preg_replace('~(\d[\d,]*(?:\.\d+)?)\s*(?:\bAED\b|درهم)~u', '<span dir="ltr" style="white-space:nowrap">'.$mark.' $1</span>', $label) ?? $label;
        $label = preg_replace('~\bAED\b|درهم~u', $mark, $label) ?? $label;

        return new HtmlString(str_replace($mark, (string) self::symbol(), $label));
    }

    /**
     * For plain text (exports, notifications): "AED 1,234.00".
     */
    public static function text(float|int|string|null $amount, int $decimals = 2): string
    {
        return self::CODE.' '.number_format((float) $amount, $decimals);
    }
}
