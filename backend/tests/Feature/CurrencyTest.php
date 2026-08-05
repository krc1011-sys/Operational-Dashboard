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

    /**
     * AED shows as its plain ISO code, by decision - "AED 1,234.50".
     *
     * The drawn mark still exists and still works; it is one config line away. What this
     * test pins down is that the choice lives in config and not in a view, so it can be
     * changed back without touching a screen.
     */
    public function test_the_dirham_renders_as_plain_text(): void
    {
        $html = Currency::html(1234.5, 'AED')->toHtml();

        $this->assertStringContainsString('AED', $html);
        $this->assertStringContainsString('1,234.50', $html);
        $this->assertStringNotContainsString('<svg', $html);

        // No glyph that depends on the viewer having a font for it, either.
        $this->assertStringNotContainsString('د.إ', $html);
        $this->assertStringNotContainsString('&#x', $html);
    }

    /** Turning the drawn mark back on is a config change and nothing else. */
    public function test_the_drawn_dirham_can_be_switched_back_on_from_config(): void
    {
        $this->assertFileExists(resource_path('svg/currency/aed.svg'),
            'the mark is kept so the decision stays reversible');

        config(['currencies.currencies.AED.symbol' => 'aed.svg']);

        $html = Currency::html(1234.5, 'AED')->toHtml();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('aria-label="AED"', $html, 'named for screen readers');
        $this->assertStringContainsString('currentColor', $html, 'takes the colour of its text');
        $this->assertStringContainsString('em', $html, 'takes the size of its text');
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
     * dirhams and not a crash. Still meaningful with AED on plain text: the point is that
     * JPY says JPY.
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
