<?php

namespace Tests\Feature;

use App\Enums\SourceFileStatus;
use App\Enums\UploadType;
use App\Models\SourceFile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * The upload tab end to end: who may reach it, what happens to a good file, and what
 * happens to a wrong one (blueprint §J, §O).
 */
class UploadFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    private function upload(FakeWorkbook $book, string $name, string $extension = 'xlsx'): UploadedFile
    {
        $path = $this->tempFiles[] = $book->write($extension);

        return new UploadedFile($path, $name, null, null, true);
    }

    // --- Access -------------------------------------------------------------

    public function test_admin_can_open_the_upload_tab(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get('/uploads')
            ->assertOk()
            ->assertSee('Upload a file');
    }

    /** At launch every upload is Admin-only, so nobody else even sees the tab (§O). */
    public function test_non_admin_roles_cannot_open_the_upload_tab_at_launch(): void
    {
        foreach (['Finance', 'Sales', 'Procurement', 'Warehouse'] as $role) {
            $this->actingAs($this->user($role))->get('/uploads')->assertForbidden();
        }
    }

    public function test_the_dropdown_only_offers_types_the_user_may_upload(): void
    {
        config(['operon.uploads_admin_only' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Warehouse holds upload-packing-list but not upload-po.
        $response = $this->actingAs($this->user('Warehouse'))->get('/uploads')->assertOk();

        $response->assertSee(UploadType::AmazonInterimPacking->value, escape: false);
        $response->assertDontSee('"'.UploadType::AmazonPoBulk->value.'"', escape: false);
    }

    public function test_a_user_cannot_upload_a_type_they_lack_permission_for(): void
    {
        config(['operon.uploads_admin_only' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user('Warehouse'))
            ->post('/uploads', [
                'upload_type' => UploadType::AmazonPoBulk->value,
                'file' => $this->upload(FakeWorkbook::amazonPo(), 'POItemExport.xlsx'),
            ])
            ->assertSessionHasErrors('upload_type');

        $this->assertSame(0, SourceFile::count());
    }

    // --- Uploading ----------------------------------------------------------

    public function test_a_valid_file_is_stored_imported_and_audited(): void
    {
        $admin = $this->user('Admin');

        $this->actingAs($admin)->post('/uploads', [
            'upload_type' => UploadType::AmazonPoBulk->value,
            'file' => $this->upload(FakeWorkbook::amazonPo(), 'POItemExport_2026-08-03.xlsx'),
        ])->assertRedirect();

        $file = SourceFile::sole();

        $this->assertSame(SourceFileStatus::Imported, $file->status);
        $this->assertSame(2, $file->rows_imported);
        $this->assertSame('POItemExport_2026-08-03.xlsx', $file->original_filename);
        $this->assertSame($admin->id, $file->uploaded_by);
        $this->assertSame('Line Items', data_get($file->summary, 'sheet'));
        $this->assertNotNull($file->content_hash);
        $this->assertNotNull($file->imported_at);
        Storage::disk('local')->assertExists($file->stored_path);
    }

    /** Each warning is reported once, however many stages produced it. */
    public function test_warnings_are_not_duplicated_between_validation_and_import(): void
    {
        $this->actingAs($this->user('Admin'))->post('/uploads', [
            'upload_type' => UploadType::AmazonPoBulk->value,
            'file' => $this->upload(FakeWorkbook::amazonPo(), 'POItemExport.xlsx'),
        ]);

        $warnings = SourceFile::sole()->warnings ?? [];

        $this->assertSame(count($warnings), count(array_unique($warnings)));
    }

    /** The guardrail: a wrong file is rejected and NOTHING is written. */
    public function test_a_mismatched_file_is_rejected_with_an_explanation(): void
    {
        $this->actingAs($this->user('Admin'))->post('/uploads', [
            'upload_type' => UploadType::AmazonPoBulk->value,
            'file' => $this->upload(FakeWorkbook::packingList(), 'PACKING LIST - Interim.xlsx'),
        ])->assertRedirect();

        $file = SourceFile::sole();

        $this->assertSame(SourceFileStatus::Rejected, $file->status);
        $this->assertStringContainsString('Line Items', $file->rejection_reason);
        $this->assertSame(0, $file->rows_imported);

        // The audit record survives so a rejected attempt is still traceable.
        $this->actingAs($this->user('Admin'))
            ->get(route('uploads.show', $file))
            ->assertOk()
            ->assertSee('rejected');
    }

    /** §C: real Amazon PO exports are .xls, and must upload without conversion. */
    public function test_an_xls_file_uploads_without_conversion(): void
    {
        $this->actingAs($this->user('Admin'))->post('/uploads', [
            'upload_type' => UploadType::AmazonPoBulk->value,
            'file' => $this->upload(FakeWorkbook::amazonPo(), 'POItemExport_2026-08-03.xls', 'xls'),
        ])->assertRedirect();

        $this->assertSame(SourceFileStatus::Imported, SourceFile::sole()->status);
    }

    /**
     * File types whose parser is not built yet (Noon, DFS, sell-out, master sheet) still
     * validate and store, and say plainly that nothing was written.
     */
    public function test_a_type_with_no_parser_yet_rests_at_validated(): void
    {
        $sellout = (new FakeWorkbook)->sheet('Sheet1', [
            ['Viewing Range: 2026-07-01 to 2026-07-31', 'Currency: AED'],
            ['ASIN', 'Product Title', 'Brand', 'Shipped Revenue', 'Shipped Units', 'Customer Returns'],
            ['B08TEST0001', 'Test product', 'TestBrand', 4410.0, 180, 2],
        ]);

        $this->actingAs($this->user('Admin'))->post('/uploads', [
            'upload_type' => UploadType::AmazonSellout->value,
            'file' => $this->upload($sellout, 'Sales_ASIN_Sourcing_Retail_x.xlsx'),
        ]);

        $file = SourceFile::sole();
        $this->assertSame(SourceFileStatus::Validated, $file->status);

        $this->actingAs($this->user('Admin'))
            ->get(route('uploads.show', $file))
            ->assertOk()
            ->assertSee('Nothing has been imported yet');
    }

    // --- The cancellations template (§T) ------------------------------------

    public function test_admin_can_download_the_cancellations_template(): void
    {
        $response = $this->actingAs($this->user('Admin'))
            ->get(route('uploads.cancellation-template'));

        $response->assertOk();
        $response->assertDownload('Amazon_Cancellations_TEMPLATE.xlsx');
    }

    public function test_the_generated_template_passes_its_own_validation(): void
    {
        $path = $this->tempFiles[] = tempnam(sys_get_temp_dir(), 'operon_tpl_').'.xlsx';
        (new \App\Services\Upload\CancellationTemplate)->writeTo($path);

        $result = (new \App\Services\Upload\UploadValidator)
            ->validate($path, UploadType::AmazonCancellations, 'Amazon_Cancellations_TEMPLATE.xlsx');

        $this->assertTrue($result->passed, $result->message());
        $this->assertSame('Cancellations', $result->sheetName);
    }

    public function test_a_user_without_the_cancellation_permission_cannot_get_the_template(): void
    {
        config(['operon.uploads_admin_only' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user('Procurement'))
            ->get(route('uploads.cancellation-template'))
            ->assertForbidden();
    }

    // --- Freshness nudge (§J) -----------------------------------------------

    public function test_a_type_that_has_never_been_uploaded_is_reported_overdue(): void
    {
        $overdue = collect(SourceFile::overdueTypes())->pluck('type')->all();

        // DFS is weekly; POs are event-driven and must never nag.
        $this->assertContains(UploadType::AmazonDfs, $overdue);
        $this->assertNotContains(UploadType::AmazonPoBulk, $overdue);
    }

    public function test_a_recent_upload_clears_the_nudge(): void
    {
        SourceFile::create([
            'upload_type' => UploadType::AmazonDfs,
            'original_filename' => 'DFS_2026-08.xlsx',
            'status' => SourceFileStatus::Imported,
            'imported_at' => now()->subDay(),
        ]);

        $overdue = collect(SourceFile::overdueTypes())->pluck('type')->all();

        $this->assertNotContains(UploadType::AmazonDfs, $overdue);
    }

    public function test_a_stale_upload_triggers_the_nudge(): void
    {
        SourceFile::create([
            'upload_type' => UploadType::AmazonDfs,
            'original_filename' => 'DFS_2026-07.xlsx',
            'status' => SourceFileStatus::Imported,
            'imported_at' => now()->subDays(9),
        ]);

        $overdue = collect(SourceFile::overdueTypes())->keyBy(fn ($o) => $o['type']->value);

        $this->assertArrayHasKey(UploadType::AmazonDfs->value, $overdue->all());
        $this->assertSame(9, $overdue[UploadType::AmazonDfs->value]['days']);
    }

    public function test_the_dashboard_shows_the_nudge_banner(): void
    {
        $this->actingAs($this->user('Admin'))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Some files are due for an update');
    }
}
