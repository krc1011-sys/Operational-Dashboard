<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Money screens need BOTH the role permission and the session PIN (blueprint §S).
 */
class MoneyPinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operon.money_pin' => '4321']);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return tap(User::factory()->create())->assignRole('Admin');
    }

    public function test_money_screen_redirects_to_the_pin_prompt_when_not_yet_confirmed(): void
    {
        $this->actingAs($this->admin())
            ->get('/money')
            ->assertRedirect(route('money-pin.prompt'));
    }

    public function test_correct_pin_unlocks_the_money_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/money-pin', ['pin' => '4321'])->assertRedirect();
        $this->actingAs($admin)->get('/money')->assertOk();
    }

    public function test_wrong_pin_is_rejected_and_does_not_unlock(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/money-pin', ['pin' => '0000'])
            ->assertSessionHasErrors('pin');

        $this->actingAs($admin)->get('/money')->assertRedirect(route('money-pin.prompt'));
    }

    public function test_locking_re_protects_the_money_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/money-pin', ['pin' => '4321']);
        $this->actingAs($admin)->post('/money-pin/lock')->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->get('/money')->assertRedirect(route('money-pin.prompt'));
    }

    public function test_a_correct_pin_cannot_bypass_a_missing_permission(): void
    {
        // Warehouse has no money permissions at all (§O) - the PIN must not help.
        $warehouse = tap(User::factory()->create())->assignRole('Warehouse');

        $this->actingAs($warehouse)->post('/money-pin', ['pin' => '4321']);
        $this->actingAs($warehouse)->get('/money')->assertForbidden();
    }

    public function test_guests_cannot_reach_the_money_screen(): void
    {
        $this->get('/money')->assertRedirect(route('login'));
    }
}
