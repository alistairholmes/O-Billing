<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_load_panel_pages_for_their_municipality(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $municipality = Municipality::create(['name' => 'Smoke City', 'base_currency' => 'ZAR']);
        $user->municipalities()->attach($municipality);

        // A completed run with multi-currency totals, so report pages exercise
        // the totals formatting (an array-cast column) rather than rendering empty.
        \App\Models\BillingRun::create([
            'municipality_id' => $municipality->id,
            'run_number' => 'BR-SMOKE-0001',
            'period_month' => now()->startOfMonth(),
            'frequency' => 'monthly',
            'status' => 'completed',
            'invoice_count' => 2,
            'currency_totals' => ['ZAR' => 1234.5, 'USD' => 67.0],
            'run_at' => now(),
        ]);

        $this->actingAs($user);

        $base = "/admin/{$municipality->id}";

        $this->get($base)->assertSuccessful();                  // dashboard
        $this->get("{$base}/customers")->assertSuccessful();
        $this->get("{$base}/service-types")->assertSuccessful(); // Service Groups
        $this->get("{$base}/services")->assertSuccessful();
        $this->get("{$base}/tariffs")->assertSuccessful();
        $this->get("{$base}/areas")->assertSuccessful();
        $this->get("{$base}/billing-runs")->assertSuccessful();
        $this->get("{$base}/billing-runs/create")->assertSuccessful();
        $this->get("{$base}/pre-billing-report")->assertSuccessful();
        $this->get("{$base}/post-billing-report")->assertSuccessful();
        $this->get("{$base}/invoices")->assertSuccessful();
        $this->get("{$base}/setup-wizard")->assertSuccessful();
        $this->get("{$base}/users")->assertSuccessful();
    }

    public function test_non_admin_cannot_access_the_users_page(): void
    {
        $user = User::factory()->create(); // is_admin defaults to false
        $municipality = Municipality::create(['name' => 'Smoke City', 'base_currency' => 'ZAR']);
        $user->municipalities()->attach($municipality);

        $this->actingAs($user)
            ->get("/admin/{$municipality->id}/users")
            ->assertForbidden();
    }

    public function test_user_cannot_access_a_municipality_they_do_not_belong_to(): void
    {
        $user = User::factory()->create();
        $mine = Municipality::create(['name' => 'Mine', 'base_currency' => 'ZAR']);
        $other = Municipality::create(['name' => 'Other', 'base_currency' => 'ZAR']);
        $user->municipalities()->attach($mine);

        // Filament hides inaccessible tenants behind a 404 rather than a 403.
        $this->actingAs($user)
            ->get("/admin/{$other->id}")
            ->assertNotFound();
    }
}
