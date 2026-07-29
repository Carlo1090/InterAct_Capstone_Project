<?php

namespace Tests\Feature\Services;

use App\Models\Department;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Guards every Cache::remember() site against config/cache.php's
 * `serializable_classes => false` (Laravel's default hardening against
 * gadget-chain attacks if APP_KEY leaks).
 *
 * That setting makes the framework unserialize cached payloads with
 * `allowed_classes: false`, so ANY object stored in a serializing store —
 * database, file, redis — comes back as __PHP_Incomplete_Class. Caching a
 * Collection or an Eloquent model therefore works on the FIRST (cold) request
 * and 500s on every request after it, which is exactly how it shipped: the
 * coordinator dashboard and the admin programs/departments pages died on their
 * second load.
 *
 * The rest of the suite cannot catch this, because phpunit.xml pins
 * CACHE_STORE=array and ArrayStore keeps live PHP objects in memory without
 * ever serializing them. These tests deliberately force a serializing store.
 */
class CacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Swap in the `file` store (which really serializes) with the production
     * `serializable_classes => false` guard active, so a warm read here behaves
     * exactly like a warm read against the deployed database cache store.
     */
    protected function useSerializingCache(): void
    {
        config()->set('cache.serializable_classes', false);
        config()->set('cache.default', 'file');

        Cache::purge('file');
        Cache::store('file')->clear();
    }

    private function makeCoordinator(): User
    {
        $department = Department::create(['code' => 'CAST', 'name' => 'CAST', 'is_active' => true]);
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BSIT', 'is_active' => true]);
        Program::create(['department_id' => $department->id, 'code' => 'BSCS', 'name' => 'BSCS', 'is_active' => true]);

        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($department->id);

        return $coordinator;
    }

    public function test_a_cached_object_would_not_survive_a_serializing_store(): void
    {
        // Pins the underlying constraint this whole file exists for, so if a
        // future Laravel/config change lifts it, this test says so out loud.
        $this->useSerializingCache();

        Cache::put('probe:object', collect([1, 2, 3]), 60);
        Cache::put('probe:array', [1, 2, 3], 60);

        $this->assertInstanceOf(
            \__PHP_Incomplete_Class::class,
            Cache::get('probe:object'),
            'Objects are still refused on read — so nothing may cache an object.',
        );
        $this->assertSame([1, 2, 3], Cache::get('probe:array'), 'Plain arrays must round-trip intact.');
    }

    public function test_coordinator_program_ids_survives_a_warm_cache_read(): void
    {
        $this->useSerializingCache();
        $coordinator = $this->makeCoordinator();

        $cold = $coordinator->coordinatorProgramIds();

        // The read that used to 500: a fresh instance pulling the stored value.
        $warm = User::find($coordinator->id)->coordinatorProgramIds();

        $this->assertInstanceOf(Collection::class, $cold);
        $this->assertInstanceOf(Collection::class, $warm);
        $this->assertCount(2, $warm);
        $this->assertSame($cold->all(), $warm->all());
    }

    public function test_system_settings_survive_a_warm_cache_read(): void
    {
        $this->useSerializingCache();
        SystemSetting::create(['key' => 'system_email', 'value' => 'ojt@mdc.edu.ph']);

        $cold = SystemSetting::cached();
        $warm = SystemSetting::cached();

        $this->assertInstanceOf(Collection::class, $cold);
        $this->assertInstanceOf(Collection::class, $warm);
        $this->assertSame('ojt@mdc.edu.ph', $warm->get('system_email'));
    }

    public function test_admin_reference_endpoints_survive_a_warm_cache_read(): void
    {
        $this->useSerializingCache();
        $this->makeCoordinator();
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['/api/admin/programs', '/api/admin/departments'] as $endpoint) {
            $cold = $this->actingAs($admin)->getJson($endpoint);
            $warm = $this->actingAs($admin)->getJson($endpoint);

            $cold->assertOk();
            $warm->assertOk();               // this is the request that used to 500
            $this->assertSame(
                $cold->json(),
                $warm->json(),
                "Warm response for {$endpoint} must match the cold one.",
            );
            $this->assertNotEmpty($warm->json(), "{$endpoint} must not return an empty payload from cache.");
        }
    }
}
