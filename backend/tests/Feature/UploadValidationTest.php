<?php

namespace Tests\Feature;

use App\Enums\UploadType;
use App\Services\Spreadsheet\CellValue;
use App\Services\Spreadsheet\HeaderMap;
use App\Services\Spreadsheet\Workbook;
use App\Services\Upload\UploadValidator;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The §J guardrail: choose the type, then the file is checked against that type's
 * fingerprint and rejected with a clear message before anything is imported.
 */
class UploadValidationTest extends TestCase
{
    private UploadValidator $validator;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new UploadValidator;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function file(FakeWorkbook $book, string $extension = 'xlsx'): string
    {
        return $this->tempFiles[] = $book->write($extension);
    }

    public function test_a_matching_amazon_po_file_passes(): void
    {
        $path = $this->file(FakeWorkbook::amazonPo());

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'POItemExport_2026-08-03.xlsx');

        $this->assertTrue($result->passed, $result->message());
        $this->assertSame('Line Items', $result->sheetName);
        $this->assertSame(1, $result->headerRow);
        $this->assertTrue($result->headers->has('asin'));
        $this->assertTrue($result->headers->has('accepted quantity'));
    }

    /** §C: the real Amazon export is .xls (Excel 97-2003), not .xlsx. */
    public function test_a_real_xls_file_is_readable(): void
    {
        $path = $this->file(FakeWorkbook::amazonPo(), 'xls');

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'POItemExport_2026-08-03.xls');

        $this->assertTrue($result->passed, $result->message());
        $this->assertSame('Line Items', $result->sheetName);
    }

    /** The core guardrail: right dropdown choice, wrong file. */
    public function test_choosing_the_wrong_type_rejects_the_file_with_a_clear_message(): void
    {
        $path = $this->file(FakeWorkbook::packingList());

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'PACKING LIST.xlsx');

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('Line Items', $result->message());
        $this->assertStringContainsString('Simple List', $result->message());
    }

    public function test_a_file_missing_a_required_column_is_rejected_and_names_the_column(): void
    {
        $path = $this->file((new FakeWorkbook)->sheet('Line Items', [
            ['PO', 'Product name', 'Requested quantity', 'Ship-to location'], // no ASIN, no Accepted
            ['774FV9FB', 'Test', 200, 'DXB3'],
        ]));

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'export.xlsx');

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('ASIN', $result->message());
        $this->assertStringContainsString('Accepted quantity', $result->message());
    }

    public function test_the_wrong_extension_is_rejected_before_the_file_is_opened(): void
    {
        $path = $this->file(FakeWorkbook::amazonPo());

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'export.csv');

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('.csv', $result->message());
    }

    public function test_a_header_with_no_data_under_it_is_rejected(): void
    {
        $path = $this->file((new FakeWorkbook)->sheet('Line Items', [
            ['PO', 'ASIN', 'Requested quantity', 'Accepted quantity', 'Ship-to location'],
        ]));

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'empty.xlsx');

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('no data rows', $result->message());
    }

    /** §K: the packing list's header sits on row 4, under two banner rows. */
    public function test_the_packing_list_header_is_found_on_row_four(): void
    {
        $path = $this->file(FakeWorkbook::packingList());

        $result = $this->validator->validate($path, UploadType::AmazonInterimPacking, 'PACKING LIST - Interim.xlsx');

        $this->assertTrue($result->passed, $result->message());
        $this->assertSame('Simple List', $result->sheetName);
        $this->assertSame(4, $result->headerRow);
    }

    /**
     * The header row is FOUND, not assumed. A file whose header slipped a row still
     * imports rather than failing - the point of mapping by name, not position.
     */
    public function test_a_shifted_header_row_is_still_found(): void
    {
        $path = $this->file((new FakeWorkbook)->sheet('Simple List', [
            [null, null, null, 'Shipment Name: Aug-01-22161389743'],
            [null, null, null, 'Shipment Date: 2026-08-12'],
            [],
            [],
            ['PO', 'ASIN', 'Model Number', 'Title', 'Qty', 'Carton'], // row 5, not 4
            ['774FV9FB', 'B08TEST0001', '0634562947130', 'Test', 100, '1'],
        ]));

        $result = $this->validator->validate($path, UploadType::AmazonInterimPacking, 'shifted.xlsx');

        $this->assertTrue($result->passed, $result->message());
        $this->assertSame(5, $result->headerRow);
    }

    public function test_missing_optional_columns_warn_rather_than_reject(): void
    {
        $path = $this->file((new FakeWorkbook)->sheet('Simple List', [
            [], [], [],
            ['PO', 'ASIN', 'Qty'], // no Title, Carton, Model Number
            ['774FV9FB', 'B08TEST0001', 100],
        ]));

        $result = $this->validator->validate($path, UploadType::AmazonInterimPacking, 'minimal.xlsx');

        $this->assertTrue($result->passed, $result->message());
        $this->assertNotEmpty($result->warnings);
    }

    public function test_a_file_that_is_not_a_spreadsheet_fails_gracefully(): void
    {
        $path = $this->tempFiles[] = tempnam(sys_get_temp_dir(), 'operon_test_').'.xlsx';
        file_put_contents($path, 'this is not a spreadsheet');

        $result = $this->validator->validate($path, UploadType::AmazonPoBulk, 'broken.xlsx');

        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->message());
    }

    /** §P: the sell-out report's header is row 2, under a metadata row. */
    public function test_the_sellout_header_is_found_under_the_metadata_row(): void
    {
        $path = $this->file((new FakeWorkbook)->sheet('Sheet1', [
            ['Viewing Range: 2026-07-01 to 2026-07-31', 'Currency: AED', 'View By: ASIN'],
            ['ASIN', 'Product Title', 'Brand', 'Shipped Revenue', 'Shipped COGS', 'Shipped Units', 'Customer Returns'],
            ['B08TEST0001', 'Test product', 'TestBrand', 4410.0, 3200.0, 180, 2],
        ]));

        $result = $this->validator->validate($path, UploadType::AmazonSellout, 'Sales_ASIN_Sourcing_Retail_x.xlsx');

        $this->assertTrue($result->passed, $result->message());
        $this->assertSame(2, $result->headerRow);
    }

    // --- The header-name matcher itself -------------------------------------

    public function test_header_matching_ignores_case_punctuation_and_spacing(): void
    {
        $map = HeaderMap::fromRow([1 => 'Ship-to location', 2 => 'ACCEPTED QUANTITY', 3 => 'P.O No']);

        $this->assertSame(1, $map->column('ship to location'));
        $this->assertSame(2, $map->column('accepted quantity'));
        $this->assertSame(3, $map->column('po number', 'p o no'));
        $this->assertNull($map->column('nonexistent column'));
    }

    public function test_header_matching_falls_back_to_a_contains_match(): void
    {
        $map = HeaderMap::fromRow([1 => 'Accepted quantity (units)']);

        $this->assertSame(1, $map->column('accepted quantity'));
    }

    // --- Cell reading -------------------------------------------------------

    /** §B: a barcode read as a number would lose its leading zero. */
    public function test_identifiers_survive_as_text(): void
    {
        $this->assertSame('0634562947130', CellValue::asText('0634562947130'));
        $this->assertSame('634562947130', CellValue::asText(634562947130.0));
        $this->assertSame('B08TEST0001', CellValue::asText("  B08TEST0001\n"));
        $this->assertNull(CellValue::asText(''));
    }

    public function test_numbers_survive_formatting(): void
    {
        $this->assertSame(1250, CellValue::asInt('1,250'));
        $this->assertSame(180, CellValue::asInt(180.0));
        $this->assertEqualsWithDelta(14240.95, CellValue::asDecimal('AED 14,240.95'), 0.001);
        $this->assertNull(CellValue::asInt(null));
    }

    public function test_dates_parse_from_text_and_excel_serials(): void
    {
        $this->assertSame('2026-08-03', CellValue::asDate('2026-08-03')?->toDateString());
        $this->assertSame('2026-08-03', CellValue::asDate(46237)?->toDateString());
        $this->assertNull(CellValue::asDate('not a date at all'));
    }

    /** §K: the ASN banner is found by its label, wherever it sits. */
    public function test_the_shipment_banner_is_found_by_label_not_coordinate(): void
    {
        $path = $this->file(FakeWorkbook::packingList());
        $workbook = Workbook::open($path);

        $banner = $workbook->sheet('Simple List')->findTextContaining('Shipment Name');

        $this->assertNotNull($banner);
        $this->assertStringContainsString('22161389743', $banner);

        $workbook->close();
    }

    public function test_every_upload_type_has_a_definition(): void
    {
        foreach (UploadType::cases() as $type) {
            $definition = \App\Services\Upload\FileTypeRegistry::for($type);

            $this->assertNotEmpty($definition->requiredHeaders, "{$type->value} has no required headers.");
            $this->assertNotEmpty($definition->extensions, "{$type->value} has no allowed extensions.");
            $this->assertNotEmpty($type->permission(), "{$type->value} has no upload permission.");
        }
    }
}
