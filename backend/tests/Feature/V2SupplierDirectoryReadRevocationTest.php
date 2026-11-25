<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class V2SupplierDirectoryReadRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_list_and_360_recheck_persisted_view_deny_during_reads_in_ar_and_en(): void
    {
        $typeId = DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId === null) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'P111-SUPPLIER-READ-DENY',
            'name' => 'Supplier read revocation',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->create([
            'code' => 'P111_SUPPLIER_READER',
            'name' => 'Supplier reader',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $actor = User::factory()->create([
            'is_active' => true,
            'has_permission_overrides' => false,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $directory = app(SupplierDirectory::class);
        $supplier = $directory->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P111-REVOCATION-SUPPLIER',
            'name_en' => 'Scoped supplier',
        ]);
        $permissionId = Permission::query()
            ->where('code', 'procurement.suppliers.view')
            ->firstOrFail()->id;

        $armed = false;
        $revoked = false;
        DB::listen(static function ($event) use ($actor, $permissionId, &$armed, &$revoked): void {
            $sql = strtolower(ltrim((string) $event->sql));
            if (! $armed || $revoked || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_suppliers')) {
                return;
            }

            // The scoped SQL already read its result. Persist an explicit
            // DENY between query completion and the service response.
            $armed = false;
            $revoked = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['list', 'supplier360'] as $readType) {
                $read = $readType === 'list'
                    ? fn () => $directory->paginate($actor, $context)
                    : fn () => $directory->supplier360($actor, $context, $supplier->id);

                $positive = $read();
                if ($readType === 'list') {
                    $this->assertSame(1, $positive->total());
                } else {
                    $this->assertSame($supplier->id, $positive['supplier']->id);
                }

                $auditBefore = DB::table('audit_logs')->count();
                $armed = true;
                $revoked = false;
                try {
                    $read();
                    $this->fail('A supplier read must not return after a persisted midquery DENY.');
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                $this->assertTrue($revoked, 'The real scoped supplier SELECT must trigger revocation.');
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);

                try {
                    $read();
                    $this->fail('The same stale actor must stay denied after revocation.');
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }

                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertDatabaseHas('enterprise_suppliers', [
                    'id' => $supplier->id,
                    'name_en' => 'Scoped supplier',
                    'store_id' => $store->id,
                ]);

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);

                $restored = $read();
                if ($readType === 'list') {
                    $this->assertSame(1, $restored->total());
                } else {
                    $this->assertSame($supplier->id, $restored['supplier']->id);
                }
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            }
        }
    }

    public function test_supplier_page_rejects_inflight_foreign_store_transfer_in_ar_and_en(): void
    {
        $typeId = DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId === null) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'P111-PAGE-STORE-OWN',
            'name' => 'Original supplier store',
            'is_active' => true,
        ]);
        $foreignStore = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'P111-PAGE-STORE-OTHER',
            'name' => 'Foreign supplier store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($foreignStore);

        $role = Role::query()->create([
            'code' => 'P111_SUPPLIER_PAGE_READER',
            'name' => 'Supplier page reader',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $actor = User::factory()->create([
            'is_active' => true,
            'has_permission_overrides' => false,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $directory = app(SupplierDirectory::class);
        $supplier = $directory->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P111-LATE-MOVE-SUPPLIER',
            'name_en' => 'Foreign transfer must be invisible',
        ]);

        $armed = false;
        $transferred = false;
        DB::listen(static function ($event) use ($supplier, $foreignStore, &$armed, &$transferred): void {
            $sql = strtolower(ltrim((string) $event->sql));
            if (! $armed || $transferred || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_suppliers')
                || ! str_contains($sql, 'order by')
                || ! str_contains($sql, 'limit')) {
                return;
            }

            // Eloquent already hydrated the scoped page; transfer ownership
            // before the service releases that in-memory supplier to callers.
            $armed = false;
            $transferred = true;
            DB::table('enterprise_suppliers')
                ->where('id', $supplier->id)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(1, $directory->paginate($actor, $context)->total());
            $auditBefore = DB::table('audit_logs')->count();
            $supplierCount = DB::table('enterprise_suppliers')->count();
            $armed = true;
            $transferred = false;

            try {
                $directory->paginate($actor, $context);
                $this->fail('An in-flight supplier page must not reveal an already transferred store supplier.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            } finally {
                $armed = false;
            }

            $this->assertTrue($transferred, 'The real ordered supplier page SELECT must trigger the transfer.');
            $this->assertSame(0, $directory->paginate($actor, $context)->total());
            $this->assertSame($foreignStore->id, (int) $supplier->fresh()->store_id);
            $this->assertSame($supplierCount, DB::table('enterprise_suppliers')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('enterprise_suppliers')
                ->where('id', $supplier->id)
                ->update(['store_id' => $store->id]);
            $restored = $directory->paginate($actor, $context);
            $this->assertSame(1, $restored->total());
            $this->assertSame($supplier->id, $restored->items()[0]->id);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }
    }
}
