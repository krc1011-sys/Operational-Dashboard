<?php

namespace Tests\Feature;

use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The currency layer: money is an amount plus the code stored beside it, never an
 * assumption. These tests exist mainly to protect the promise that entering a new
 * country is a config change - if someone hardcodes a symbol again, the SAR tests here
 * are what fail.
 */
class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dirham_renders_as_an_inline_svg_not_a_text_glyph(): void
    {
        $html = Currency::html(1234.5, 'AED')->toHtml();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('1,234.50', $html);

        // The whole point: no text symbol that depends on the viewer having a font for it.
        $this->assertStringNotContainsString('د.إ', $html);
        $this->assertStringNotContainsString('&#x', $html);
    }

    public function test_the_symbol_carries_its_iso_code_for_screen_readers(): void
    {
        $this->assertStringContainsString('aria-label="AED"', Currency::symbol('AED')->toHtml());
    }

    public function test_the_symbol_inherits_the_colour_and_size_of_its_text(): void
    {
        $svg = Currency::symbol('AED')->toHtml();

        // currentColor and em sizing mean no screen needs its own currency styling.
        $this->assertStringContainsString('currentColor', $svg);
        $this->assertStringContainsString('em', $svg);
    }

    public function test_csv_gets_the_iso_code_because_a_cell_cannot_hold_an_svg(): void
    {
        $this->assertSame('AED 1,234.50', Currency::plain(1234.5, 'AED'));
    }

    /**
     * The KSA promise: adding a currency is data, not code. Nothing below touches a class
     * or a view - it adds a config entry and everything downstream follows.
     */
    public function test_adding_a_new_currency_is_a_pure_config_change(): void
    {
        config(['currencies.currencies.SAR' => [
            'name' => 'Saudi Riyal',
            'decimals' => 2,
            'symbol' => null,
        ]]);

        $this->assertSame('SAR 900.00', Currency::plain(900, 'SAR'));
        $this->assertSame('Saudi Riyal', Currency::definition('SAR')['name']);
        $this->assertStringContainsString('SAR', Currency::html(900, 'SAR')->toHtml());
    }

    /** A currency with its own symbol file picks it up without any code change either. */
    public function test_a_new_currency_with_a_symbol_file_renders_that_symbol(): void
    {
        $path = resource_path('svg/currency/test-riyal.svg');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2 2h4"/></svg>');

        try {
            config(['currencies.currencies.SAR' => [
                'name' => 'Saudi Riyal', 'decimals' => 2, 'symbol' => 'test-riyal.svg',
            ]]);

            $html = Currency::html(10, 'SAR')->toHtml();

            $this->assertStringContainsString('<svg', $html);
            $this->assertStringContainsString('aria-label="SAR"', $html);
        } finally {
            @unlink($path);
        }
    }

    /**
     * An unexpected currency in a file must be visible, not silently relabelled as
     * dirhams and not a crash.
     */
    public function test_an_unknown_currency_shows_its_code_rather_than_borrowing_a_symbol(): void
    {
        $html = Currency::html(50, 'JPY')->toHtml();

        $this->assertStringContainsString('JPY', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_a_blank_currency_falls_back_to_the_configured_default(): void
    {
        $this->assertSame('AED', Currency::code(null));
        $this->assertSame('AED', Currency::code(''));
        $this->assertSame('AED', Currency::code(' aed '));
    }

    public function test_decimal_places_come_from_the_currency_not_from_the_caller(): void
    {
        config(['currencies.currencies.JOD' => ['name' => 'Jordanian Dinar', 'decimals' => 3, 'symbol' => null]]);

        $this->assertSame('1.500', Currency::amount(1.5, 'JOD'));
        $this->assertSame('1.50', Currency::amount(1.5, 'AED'));
    }

    /** Totalling across currencies is meaningless, so a mixed set reports itself as mixed. */
    public function test_a_set_of_rows_reports_one_currency_or_none(): void
    {
        $this->assertSame('AED', Currency::single(['AED', 'AED', null]));
        $this->assertNull(Currency::single(['AED', 'SAR']));
        $this->assertSame('AED', Currency::single([]));
    }
}
