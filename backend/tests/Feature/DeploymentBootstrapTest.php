<?php

namespace Tests\Feature;

use App\Enums\SourceFileStatus;
use App\Enums\UploadType;
use App\Models\SourceFile;
use App\Models\User;
use App\Services\Spreadsheet\CellValue;
use App\Services\Upload\UploadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The pieces a deployed environment depends on, and the MySQL-only traps behind them.
 *
 * The tests run on SQLite, which is exactly why this file exists: SQLite ignores column
 * length limits, so the two failures covered here - a 700-character product name and an
 * error message the width of a bulk INSERT - were both invisible locally and fatal on
 * the managed MySQL the app deploys to. What is asserted below is the behaviour that
 * keeps them from coming back, in a form SQLite can still check.
 */
class DeploymentBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The command reads config, not env() - a deployed app runs on a cached config
     * with no .env file. config/operon.php is what maps the two, and
     * test_the_config_maps_the_documented_variable_names below pins that mapping.
     */
    private function setAdminConfig(?string $email, ?string $password): void
    {
        config([
            'operon.admin.email' => $email,
            'operon.admin.password' => $password,
        ]);
    }

    // --- The first Admin ----------------------------------------------------

    public function test_it_creates_the_first_admin_from_the_environment(): void
    {
        $this->setAdminConfig('karan@operon.test', 'a-long-enough-password');

        $this->artisan('operon:bootstrap-admin')->assertSuccessful();

        $admin = User::where('email', 'karan@operon.test')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('Admin'));
        // Stored hashed, never in the clear.
        $this->assertNotSame('a-long-enough-password', $admin->password);
    }

    public function test_it_refuses_to_invent_an_admin_when_the_environment_is_silent(): void
    {
        $this->artisan('operon:bootstrap-admin')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_password_short_enough_to_guess(): void
    {
        $this->setAdminConfig('karan@operon.test', 'short');

        $this->artisan('operon:bootstrap-admin')->assertFailed();

        $this->assertSame(0, User::count());
    }

    /** Re-running is how the password is rotated: change the variable, redeploy. */
    public function test_running_it_again_rotates_the_password_and_keeps_the_role(): void
    {
        $this->setAdminConfig('karan@operon.test', 'the-first-password');
        $this->artisan('operon:bootstrap-admin')->assertSuccessful();

        $before = User::where('email', 'karan@operon.test')->first()->password;

        $this->setAdminConfig('karan@operon.test', 'the-second-password');
        $this->artisan('operon:bootstrap-admin')->assertSuccessful();

        $admin = User::where('email', 'karan@operon.test')->first();

        $this->assertSame(1, User::count());
        $this->assertNotSame($before, $admin->password);
        $this->assertTrue($admin->hasRole('Admin'));
    }

    // --- The money PIN ------------------------------------------------------

    public function test_production_refuses_the_example_money_pin(): void
    {
        $this->app['env'] = 'production';
        config(['operon.money_pin' => '1234']);

        $this->setAdminConfig('karan@operon.test', 'a-long-enough-password');

        $this->artisan('operon:bootstrap-admin')->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * The names in DEPLOY.md are the names the config actually reads. If one of these
     * is renamed, the deployment silently stops creating its Admin - so pin them.
     */
    public function test_the_config_maps_the_documented_variable_names(): void
    {
        $config = require config_path('operon.php');

        foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD', 'MONEY_PIN'] as $name) {
            $this->assertStringContainsString(
                $name,
                file_get_contents(config_path('operon.php')),
                "config/operon.php no longer reads {$name}"
            );
        }

        // Unset in the test environment, so these prove the keys exist and default safely.
        $this->assertArrayHasKey('admin', $config);
        $this->assertArrayHasKey('email', $config['admin']);
        $this->assertArrayHasKey('password', $config['admin']);
        $this->assertSame('1234', $config['money_pin']);
    }

    // --- The MySQL-only traps ----------------------------------------------

    /**
     * One master-sheet description carries fifty-two `_x000D_` escapes in a row. Left
     * alone that is a 700-character name that MySQL rejects, taking the whole import
     * with it. Excel's escapes are decoded here, then collapsed by the whitespace rule.
     */
    public function test_it_decodes_excel_carriage_return_escapes(): void
    {
        $mangled = 'Kitchen Towel 2 Ply - 210 x'.str_repeat('_x000D_', 52).' 216mm per Sheet x4';

        $text = CellValue::asText($mangled);

        $this->assertStringNotContainsString('_x000D_', $text);
        $this->assertStringNotContainsString('_x000d_', $text);
        $this->assertSame('Kitchen Towel 2 Ply - 210 x 216mm per Sheet x4', $text);
        $this->assertLessThan(255, strlen($text));
    }

    public function test_it_leaves_ordinary_underscores_alone(): void
    {
        $this->assertSame('BD_07903074_x', CellValue::asText('BD_07903074_x'));
        // Excel's own escape for a literal underscore.
        $this->assertSame('_x000D_', CellValue::asText('_x005F_x000D_'));
    }

    // --- Uploads on an ephemeral filesystem ---------------------------------

    /**
     * A deploy resets the container filesystem, so an audit row can outlive its file.
     * That is a 404 about a missing file, not a 500.
     */
    public function test_downloading_a_file_the_deploy_wiped_is_a_404(): void
    {
        Storage::fake('local');

        $this->setAdminConfig('karan@operon.test', 'a-long-enough-password');
        $this->artisan('operon:bootstrap-admin')->assertSuccessful();

        $admin = User::where('email', 'karan@operon.test')->first();

        $file = SourceFile::create([
            'upload_type' => UploadType::MasterSheet,
            'original_filename' => 'OperON_Master_Merged.xlsx',
            'stored_path' => 'uploads/master_sheet/gone-with-the-last-deploy.xlsx',
            'size_bytes' => 2888356,
            'content_hash' => str_repeat('a', 64),
            'status' => SourceFileStatus::Imported,
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('uploads.download', $file))
            ->assertNotFound();
    }

    public function test_the_upload_disk_is_configurable(): void
    {
        $this->assertSame('local', UploadService::disk());

        config(['operon.uploads_disk' => 's3']);

        $this->assertSame('s3', UploadService::disk());
    }
}
