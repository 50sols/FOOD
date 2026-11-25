<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseOrganizationAdministration;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2OrganizationSetupGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_organization_setup_preview_validates_without_creating_record_or_audit(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $before = DB::table('audit_logs')->count();

        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'code' => 'example_company',
            'name' => 'Example Company',
            'is_active' => '1',
            '_setup_form' => 'create',
            '_setup_test' => '1',
        ])->assertRedirect()
            ->assertSessionHas('status', __('enterprise_organization.setup_test_passed'))
            ->assertSessionHas('_old_input.name', 'Example Company')
            ->assertSessionHas('_old_input._setup_form', 'create')
            ->assertSessionMissing('_old_input.password');

        $this->assertDatabaseMissing('enterprise_organizations', ['code' => 'example_company']);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_existing_organization_preview_preserves_record_and_child_hierarchy(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->where('code', 'foodex_platform')->firstOrFail();
        $before = DB::table('audit_logs')->count();

        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'name' => 'Unpersisted Preview',
            'code' => $organization->code,
            'is_active' => '1',
            '_setup_form' => 'edit',
            '_setup_test' => 'edit',
        ])->assertRedirect()
            ->assertSessionHas('status', __('enterprise_organization.setup_test_passed'))
            ->assertSessionHas('_old_input.name', 'Unpersisted Preview')
            ->assertSessionHas('_old_input._setup_form', 'edit');

        $this->assertSame($organization->name, $organization->fresh()->name);
        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_omitted_activation_on_patch_does_not_disable_enterprise_hierarchy(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_patch_active_org', 'name' => 'Active Organization', 'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id, 'code' => 'v2_patch_active_company',
            'name' => 'Active Company', 'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id, 'code' => 'v2_patch_active_entity',
            'name' => 'Active Legal Entity', 'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id, 'code' => 'v2_patch_active_unit',
            'name' => 'Active Business Unit', 'is_active' => true,
        ]);

        foreach ([
            ['admin.enterprise-organizations.update', $organization],
            ['admin.enterprise-companies.update', $company],
            ['admin.enterprise-legal-entities.update', $entity],
            ['admin.enterprise-business-units.update', $unit],
        ] as [$routeName, $record]) {
            $newName = 'Renamed '.$record->name;
            $payload = ['code' => $record->code, 'name' => $newName];
            $beforeAudit = DB::table('audit_logs')->count();

            // The same omitted checkbox must be safe in no-write preview.
            $this->actingAs($admin)->patch(route($routeName, $record), [
                ...$payload, '_setup_test' => 'edit',
            ])->assertRedirect()->assertSessionHas('status', __('enterprise_organization.setup_test_passed'));
            $this->assertSame($record->name, $record->fresh()->name);
            $this->assertTrue((bool) $record->fresh()->is_active);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

            $this->actingAs($admin)->patch(route($routeName, $record), $payload)
                ->assertRedirect()->assertSessionHas('status', __('enterprise_organization.saved'));
            $this->assertSame($newName, $record->fresh()->name);
            $this->assertTrue((bool) $record->fresh()->is_active);
            $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
        }

        // Explicit deactivation is still checked against active descendants.
        $beforeAudit = DB::table('audit_logs')->count();
        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'code' => $organization->code, 'name' => 'Not Deactivated', 'is_active' => '0',
        ])->assertSessionHasErrors('is_active');
        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        // Omission must not silently *activate* an inactive legacy child either.
        $inactive = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id, 'code' => 'v2_patch_inactive_company',
            'name' => 'Inactive Company', 'is_active' => false,
        ]);
        $this->actingAs($admin)->patch(route('admin.enterprise-companies.update', $inactive), [
            'code' => $inactive->code, 'name' => 'Still Inactive Company',
        ])->assertRedirect()->assertSessionHas('status', __('enterprise_organization.saved'));
        $this->assertSame('Still Inactive Company', $inactive->fresh()->name);
        $this->assertFalse((bool) $inactive->fresh()->is_active);
    }

    public function test_preview_rejects_invalid_or_duplicate_codes_without_mutation(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'name' => 'Invalid', 'code' => '../invalid', '_setup_test' => '1',
        ])->assertSessionHasErrors('code');

        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'name' => 'Duplicate', 'code' => 'foodex_platform', '_setup_test' => '1',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('enterprise_organizations', ['name' => 'Invalid']);
        $this->assertDatabaseMissing('enterprise_organizations', ['name' => 'Duplicate']);
    }

    public function test_setup_preview_and_save_reject_blank_names_without_writing_and_trim_valid_names(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $beforeAudit = DB::table('audit_logs')->count();

        foreach ([true, false] as $preview) {
            $payload = [
                'code' => $preview ? 'preview_blank_name' : 'save_blank_name',
                'name' => " \t ",
                'is_active' => '1',
            ];
            if ($preview) {
                $payload['_setup_test'] = '1';
            }

            $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), $payload)
                ->assertSessionHasErrors('name');
        }

        $this->assertDatabaseMissing('enterprise_organizations', ['code' => 'preview_blank_name']);
        $this->assertDatabaseMissing('enterprise_organizations', ['code' => 'save_blank_name']);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'code' => 'trimmed_valid_name',
            'name' => '  Canonical Organization  ',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_organizations', [
            'code' => 'trimmed_valid_name',
            'name' => 'Canonical Organization',
        ]);
        $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
    }

    public function test_business_codes_are_normalized_before_preview_and_save_uniqueness_validation(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $before = DB::table('audit_logs')->count();

        // A SQLite (case-sensitive) test database must reject the same
        // canonical code that MySQL/MariaDB generally reject via collation.
        // Both no-write preview and actual Save use the same validation.
        foreach ([true, false] as $preview) {
            $payload = [
                'code' => 'FOODEX_PLATFORM',
                'name' => 'Rejected Case Variant',
                'is_active' => '1',
            ];
            if ($preview) {
                $payload['_setup_test'] = '1';
            }

            $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), $payload)
                ->assertSessionHasErrors('code');
        }

        $this->assertDatabaseMissing('enterprise_organizations', ['name' => 'Rejected Case Variant']);
        $this->assertSame($before, DB::table('audit_logs')->count());

        // Legitimate uppercase input is still accepted and stored canonically.
        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'code' => 'VALID_NEW_ORG',
            'name' => 'Canonical New Organization',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_organizations', [
            'code' => 'valid_new_org',
            'name' => 'Canonical New Organization',
        ]);
        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    public function test_setup_preview_rejects_deactivation_with_active_descendants_before_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_preview_org',
            'name' => 'Preview Parent',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_preview_company',
            'name' => 'Preview Company',
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_preview_entity',
            'name' => 'Preview Entity',
            'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_preview_unit',
            'name' => 'Preview Unit',
            'is_active' => true,
        ]);
        $before = DB::table('audit_logs')->count();

        foreach ([
            [route('admin.enterprise-organizations.update', $organization), $organization],
            [route('admin.enterprise-companies.update', $company), $company],
            [route('admin.enterprise-legal-entities.update', $entity), $entity],
        ] as [$url, $record]) {
            foreach ([true, false] as $preview) {
                $payload = [
                    'code' => $record->code,
                    'name' => $record->name,
                    'is_active' => '0',
                ];
                if ($preview) {
                    $payload['_setup_test'] = 'edit';
                }

                $this->actingAs($admin)->patch($url, $payload)
                    ->assertSessionHasErrors('is_active');
                $this->assertTrue((bool) $record->fresh()->is_active);
            }
        }

        $this->assertTrue((bool) $unit->fresh()->is_active);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_preloaded_active_hierarchy_cannot_authorize_child_after_ancestor_is_disabled(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_cached_org',
            'name' => 'Cached Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_cached_company',
            'name' => 'Cached Company',
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_cached_entity',
            'name' => 'Cached Entity',
            'is_active' => true,
        ]);

        // Simulate a caller holding an earlier active hierarchy snapshot.
        $company->load('organization');
        $entity->load('company.organization');
        $this->assertTrue((bool) $entity->company->organization->is_active);
        $beforeAudit = DB::table('audit_logs')->count();
        $service = app(EnterpriseOrganizationAdministration::class);

        DB::table('enterprise_organizations')->where('id', $organization->id)->update(['is_active' => false]);
        try {
            $service->createLegalEntity($company, [
                'code' => 'v2_stale_entity',
                'name' => 'Disallowed Entity',
                'is_active' => true,
            ], $admin);
            $this->fail('A cached active organization must not authorize a child after deactivation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent', $exception->errors());
        }

        try {
            $service->createBusinessUnit($entity, [
                'code' => 'v2_stale_unit',
                'name' => 'Disallowed Unit',
                'is_active' => true,
            ], $admin);
            $this->fail('A cached active grandparent must not authorize a business unit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent', $exception->errors());
        }

        $this->assertDatabaseMissing('enterprise_legal_entities', ['code' => 'v2_stale_entity']);
        $this->assertDatabaseMissing('enterprise_business_units', ['code' => 'v2_stale_unit']);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        // Re-enable the organization, then disable only the intermediate
        // company: the cached entity-company relationship must still fail.
        DB::table('enterprise_organizations')->where('id', $organization->id)->update(['is_active' => true]);
        DB::table('enterprise_companies')->where('id', $company->id)->update(['is_active' => false]);
        try {
            $service->createBusinessUnit($entity, [
                'code' => 'v2_stale_company_unit',
                'name' => 'Disallowed Unit',
                'is_active' => true,
            ], $admin);
            $this->fail('A cached active company must not authorize a new business unit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent', $exception->errors());
        }

        $this->assertDatabaseMissing('enterprise_business_units', ['code' => 'v2_stale_company_unit']);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        DB::table('enterprise_companies')->where('id', $company->id)->update(['is_active' => true]);
        $created = $service->createBusinessUnit($entity, [
            'code' => 'v2_restored_unit',
            'name' => 'Restored Unit',
            'is_active' => true,
        ], $admin);
        $this->assertSame('v2_restored_unit', $created->code);
    }

    public function test_child_setup_preview_and_save_both_reject_inactive_parent_or_ancestor(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_inactive_create_org',
            'name' => 'Inactive Parent Organization',
            'is_active' => false,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_inactive_create_company',
            'name' => 'Inactive Parent Company',
            'is_active' => false,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_inactive_create_entity',
            'name' => 'Inactive Parent Entity',
            'is_active' => false,
        ]);
        $before = DB::table('audit_logs')->count();

        foreach ([
            route('admin.enterprise-companies.store', $organization),
            route('admin.enterprise-legal-entities.store', $company),
            route('admin.enterprise-business-units.store', $entity),
        ] as $index => $url) {
            foreach (['en', 'ar'] as $locale) {
                $admin->forceFill(['locale' => $locale])->save();
                foreach ([true, false] as $preview) {
                    $payload = [
                        'code' => 'v2_inactive_child_'.$index,
                        'name' => 'Must Not Be Created',
                        'is_active' => '1',
                    ];
                    if ($preview) {
                        $payload['_setup_test'] = '1';
                    }

                    $this->actingAs($admin->fresh())->post($url, $payload)
                        ->assertSessionHasErrors('parent');
                }
            }
        }

        $this->assertDatabaseMissing('enterprise_companies', ['code' => 'v2_inactive_child_0']);
        $this->assertDatabaseMissing('enterprise_legal_entities', ['code' => 'v2_inactive_child_1']);
        $this->assertDatabaseMissing('enterprise_business_units', ['code' => 'v2_inactive_child_2']);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_child_preview_and_save_reject_inactive_ancestor_with_active_immediate_parent(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');

        $inactiveOrganization = EnterpriseOrganization::query()->create([
            'code' => 'v2_inactive_ancestor_org',
            'name' => 'Inactive Ancestor Organization',
            'is_active' => false,
        ]);
        $activeCompany = EnterpriseCompany::query()->create([
            'organization_id' => $inactiveOrganization->id,
            'code' => 'v2_active_descendant_company',
            'name' => 'Active Child of Inactive Organization',
            'is_active' => true,
        ]);

        $activeOrganization = EnterpriseOrganization::query()->create([
            'code' => 'v2_active_ancestor_org',
            'name' => 'Active Ancestor Organization',
            'is_active' => true,
        ]);
        $inactiveCompany = EnterpriseCompany::query()->create([
            'organization_id' => $activeOrganization->id,
            'code' => 'v2_inactive_ancestor_company',
            'name' => 'Inactive Ancestor Company',
            'is_active' => false,
        ]);
        $activeEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $inactiveCompany->id,
            'code' => 'v2_active_descendant_entity',
            'name' => 'Active Child of Inactive Company',
            'is_active' => true,
        ]);

        $auditCount = DB::table('audit_logs')->count();
        foreach ([
            [route('admin.enterprise-legal-entities.store', $activeCompany), 'v2_denied_entity'],
            [route('admin.enterprise-business-units.store', $activeEntity), 'v2_denied_unit'],
        ] as [$url, $code]) {
            foreach (['en', 'ar'] as $locale) {
                $admin->forceFill(['locale' => $locale])->save();
                foreach ([true, false] as $preview) {
                    $payload = ['code' => $code, 'name' => 'Denied Descendant', 'is_active' => '1'];
                    if ($preview) {
                        $payload['_setup_test'] = '1';
                    }
                    $this->actingAs($admin->fresh())->post($url, $payload)
                        ->assertSessionHasErrors('parent');
                }
            }
        }

        $this->assertDatabaseMissing('enterprise_legal_entities', ['code' => 'v2_denied_entity']);
        $this->assertDatabaseMissing('enterprise_business_units', ['code' => 'v2_denied_unit']);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_preview_save_and_direct_service_refuse_reactivation_beneath_inactive_ancestors(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_reactivation_inactive_org',
            'name' => 'Inactive Legacy Organization',
            'is_active' => false,
        ]);
        $inactiveCompany = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_reactivation_inactive_company',
            'name' => 'Inactive Legacy Company',
            'is_active' => false,
        ]);
        $activeCompany = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_reactivation_active_company',
            'name' => 'Active Legacy Company',
            'is_active' => true,
        ]);
        $inactiveEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $activeCompany->id,
            'code' => 'v2_reactivation_inactive_entity',
            'name' => 'Inactive Legacy Entity',
            'is_active' => false,
        ]);
        $activeEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $inactiveCompany->id,
            'code' => 'v2_reactivation_active_entity',
            'name' => 'Active Legacy Entity',
            'is_active' => true,
        ]);
        $inactiveUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $activeEntity->id,
            'code' => 'v2_reactivation_inactive_unit',
            'name' => 'Inactive Legacy Unit',
            'is_active' => false,
        ]);
        $beforeAudit = DB::table('audit_logs')->count();

        // Include inactive direct parents and active immediate parents with
        // disabled grandparents: both paths must fail closed.
        $cases = [
            [$inactiveCompany, 'admin.enterprise-companies.update', 'updateCompany'],
            [$inactiveEntity, 'admin.enterprise-legal-entities.update', 'updateLegalEntity'],
            [$inactiveUnit, 'admin.enterprise-business-units.update', 'updateBusinessUnit'],
        ];

        foreach ($cases as [$record, $routeName, $serviceMethod]) {
            $payload = [
                'code' => $record->code,
                'name' => $record->name,
                'is_active' => '1',
                '_setup_form' => 'edit',
            ];

            foreach (['ar', 'en'] as $locale) {
                $admin->forceFill(['locale' => $locale])->save();
                foreach ([true, false] as $preview) {
                    $requestData = $payload;
                    if ($preview) {
                        $requestData['_setup_test'] = 'edit';
                    }

                    $this->actingAs($admin->fresh())
                        ->patch(route($routeName, $record), $requestData)
                        ->assertSessionHasErrors('parent');
                }
            }

            try {
                app(EnterpriseOrganizationAdministration::class)->{$serviceMethod}(
                    $record,
                    ['code' => $record->code, 'name' => $record->name, 'is_active' => true],
                    $admin,
                );
                $this->fail('A disabled ancestor must block service-level reactivation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent', $exception->errors());
            }

            $this->assertFalse((bool) $record->fresh()->is_active);
        }

        $this->assertFalse((bool) $organization->fresh()->is_active);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_direct_metadata_edits_preserve_inactive_legacy_hierarchy_without_reactivation(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_legacy_metadata_org',
            'name' => 'Inactive Organization',
            'is_active' => false,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_legacy_metadata_company',
            'name' => 'Inactive Company',
            'is_active' => false,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_legacy_metadata_entity',
            'name' => 'Inactive Entity',
            'is_active' => false,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_legacy_metadata_unit',
            'name' => 'Inactive Unit',
            'is_active' => false,
        ]);
        $auditBefore = DB::table('audit_logs')->count();

        // The caller submits no is_active key; name-only edits are not
        // activation operations and must not rewrite hierarchy state.
        $service->updateCompany($company, ['name' => 'Renamed Inactive Company'], $admin);
        $service->updateBusinessUnit($unit, ['name' => 'Renamed Inactive Unit'], $admin);

        $this->assertSame('Renamed Inactive Company', $company->fresh()->name);
        $this->assertSame('Renamed Inactive Unit', $unit->fresh()->name);
        $this->assertFalse((bool) $organization->fresh()->is_active);
        $this->assertFalse((bool) $company->fresh()->is_active);
        $this->assertFalse((bool) $entity->fresh()->is_active);
        $this->assertFalse((bool) $unit->fresh()->is_active);
        $this->assertSame($auditBefore + 2, DB::table('audit_logs')->count());

        // Changing the canonical hierarchy identity is not a display-name
        // edit; the inactive parent must still block that service path.
        try {
            $service->updateCompany($company, ['code' => 'v2_changed_legacy_identity'], $admin);
            $this->fail('Identity changes must not inherit the metadata-only exemption.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent', $exception->errors());
        }
        $this->assertSame('v2_legacy_metadata_company', $company->fresh()->code);

        foreach ([[$company, 'updateCompany'], [$unit, 'updateBusinessUnit']] as [$record, $method]) {
            try {
                $service->{$method}($record, ['is_active' => true], $admin);
                $this->fail('Explicit activation beneath an inactive ancestor must be denied.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent', $exception->errors());
            }

            $this->assertFalse((bool) $record->fresh()->is_active);
        }

        $this->assertSame($auditBefore + 2, DB::table('audit_logs')->count());
    }

    public function test_direct_service_cannot_move_existing_enterprise_records_across_parents(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $originalOrg = EnterpriseOrganization::query()->create([
            'code' => 'v2_immutable_original_org', 'name' => 'Original Org', 'is_active' => true,
        ]);
        $foreignOrg = EnterpriseOrganization::query()->create([
            'code' => 'v2_immutable_foreign_org', 'name' => 'Foreign Org', 'is_active' => true,
        ]);
        $originalCompany = EnterpriseCompany::query()->create([
            'organization_id' => $originalOrg->id,
            'code' => 'v2_immutable_original_company', 'name' => 'Original Company', 'is_active' => true,
        ]);
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $foreignOrg->id,
            'code' => 'v2_immutable_foreign_company', 'name' => 'Foreign Company', 'is_active' => true,
        ]);
        $originalEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $originalCompany->id,
            'code' => 'v2_immutable_original_entity', 'name' => 'Original Entity', 'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'v2_immutable_foreign_entity', 'name' => 'Foreign Entity', 'is_active' => true,
        ]);
        $originalUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $originalEntity->id,
            'code' => 'v2_immutable_original_unit', 'name' => 'Original Unit', 'is_active' => true,
        ]);
        $auditBefore = DB::table('audit_logs')->count();

        // All models are unguarded, so a direct service update must enforce
        // ownership ancestry instead of trusting the HTTP-only input schema.
        foreach ([
            [$originalCompany, 'updateCompany', 'organization_id', $foreignOrg->id],
            [$originalEntity, 'updateLegalEntity', 'company_id', $foreignCompany->id],
            [$originalUnit, 'updateBusinessUnit', 'legal_entity_id', $foreignEntity->id],
        ] as [$record, $method, $key, $foreignId]) {
            $originalParentId = (int) $record->getAttribute($key);
            $originalName = $record->name;
            foreach ([['name' => 'Unauthorized Scope Move'], [
                'name' => 'Unauthorized Scope Move', 'is_active' => true,
            ]] as $payload) {
                try {
                    $service->{$method}($record, [$key => $foreignId, ...$payload], $admin);
                    $this->fail('Direct service updates must not change enterprise parent identity.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('parent', $exception->errors());
                }

                $this->assertSame($originalParentId, (int) $record->fresh()->getAttribute($key));
                $this->assertSame($originalName, $record->fresh()->name);
            }

            // An integer-cast comparison used to accept these forms as
            // "unchanged" and pass their original bytes to an unguarded FK.
            // Rejection must happen before touching DB state or audit logs.
            foreach ([
                $originalParentId.'alias',
                $originalParentId.'.5',
                '0'.$originalParentId,
                ' '.$originalParentId,
                $originalParentId + 0.5,
                true,
                null,
            ] as $spoofedParent) {
                try {
                    $service->{$method}($record, [
                        $key => $spoofedParent,
                        'name' => 'Unauthorized Cast-Equivalent Identity',
                    ], $admin);
                    $this->fail('Noncanonical parent ID aliases must not reach Eloquent fill().');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('parent', $exception->errors());
                }

                $stored = $record->fresh();
                $this->assertSame($originalParentId, (int) $stored->getAttribute($key));
                $this->assertSame($originalName, $stored->name);
            }
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // Canonical, exactly unchanged parent IDs remain supported by the
        // historical service API even when submitted as decimal strings.
        foreach ([
            [$originalCompany, 'updateCompany', 'organization_id'],
            [$originalEntity, 'updateLegalEntity', 'company_id'],
            [$originalUnit, 'updateBusinessUnit', 'legal_entity_id'],
        ] as [$record, $method, $key]) {
            $originalParent = (int) $record->getAttribute($key);
            $updated = $service->{$method}($record, [
                $key => (string) $originalParent,
                'name' => 'Authorized Canonical Metadata',
            ], $admin);
            $this->assertSame($originalParent, (int) $updated->getAttribute($key));
            $this->assertSame('Authorized Canonical Metadata', $updated->name);
        }
        $this->assertSame($auditBefore + 3, DB::table('audit_logs')->count());
    }

    public function test_reactivation_with_active_ancestors_succeeds_only_on_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_reactivation_allowed_org',
            'name' => 'Active Parent Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_reactivation_allowed_company',
            'name' => 'Active Parent Company',
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_reactivation_allowed_entity',
            'name' => 'Active Parent Entity',
            'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_reactivation_allowed_unit',
            'name' => 'Reactivatable Unit',
            'is_active' => false,
        ]);
        $payload = ['code' => $unit->code, 'name' => $unit->name, 'is_active' => '1'];
        $beforeAudit = DB::table('audit_logs')->count();

        $this->actingAs($admin)->patch(route('admin.enterprise-business-units.update', $unit), [
            ...$payload,
            '_setup_test' => 'edit',
        ])->assertRedirect()
            ->assertSessionHas('status', __('enterprise_organization.setup_test_passed'));
        $this->assertFalse((bool) $unit->fresh()->is_active);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        $this->actingAs($admin)->patch(route('admin.enterprise-business-units.update', $unit), $payload)
            ->assertRedirect();
        $this->assertTrue((bool) $unit->fresh()->is_active);
        $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
    }

    public function test_deactivation_rejects_active_deep_descendants_hidden_by_inactive_intermediate_records(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);

        // Historical imports may contain inactive intermediate records with
        // active descendants. An immediate-child-only check is insufficient.
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_hidden_active_org',
            'name' => 'Legacy Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_hidden_inactive_company',
            'name' => 'Legacy Company',
            'is_active' => false,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_hidden_inactive_entity',
            'name' => 'Legacy Entity',
            'is_active' => false,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_hidden_active_unit',
            'name' => 'Active Legacy Unit',
            'is_active' => true,
        ]);

        $beforeAudit = DB::table('audit_logs')->count();
        foreach ([$organization, $company] as $record) {
            try {
                $service->assertUpdateAllowed($record, ['is_active' => '0']);
                $this->fail('Active deeper descendants must prevent deactivation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('is_active', $exception->errors());
            }
        }

        // The setup preview and persisted Save must enforce the same rule.
        foreach ([true, false] as $preview) {
            $payload = [
                'code' => $organization->code,
                'name' => $organization->name,
                'is_active' => '0',
            ];
            if ($preview) {
                $payload['_setup_test'] = 'edit';
            }

            $this->actingAs($admin)->patch(
                route('admin.enterprise-organizations.update', $organization),
                $payload,
            )->assertSessionHasErrors('is_active');
        }

        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertFalse((bool) $company->fresh()->is_active);
        $this->assertFalse((bool) $entity->fresh()->is_active);
        $this->assertTrue((bool) $unit->fresh()->is_active);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_store_assignment_refuses_active_unit_beneath_inactive_organization(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_inactive_scope_org',
            'name' => 'Inactive Store Assignment Organization',
            'is_active' => false,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_active_scope_company',
            'name' => 'Active Company',
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_active_scope_entity',
            'name' => 'Active Entity',
            'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_active_scope_unit',
            'name' => 'Active Unit',
            'is_active' => true,
        ]);
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'v2-inactive-parent-store',
            'name' => 'Denied Store Scope',
            'is_active' => true,
        ]);
        $before = DB::table('audit_logs')->count();

        try {
            app(EnterpriseOrganizationAdministration::class)->assignStore($store, $unit, $admin);
            $this->fail('Store assignment must reject an inactive enterprise ancestor.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent', $exception->errors());
        }

        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->id]);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_canonical_hierarchy_writer_rejects_dirty_store_and_unit_id_redirect_in_arabic_and_english(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $hierarchy = app(OrganizationHierarchyService::class);
        $defaultUnit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)
            ->whereHas('legalEntity.company.organization', fn ($query) => $query
                ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION))
            ->firstOrFail();

        $foreignOrg = EnterpriseOrganization::query()->create([
            'code' => 'v2_canonical_dirty_foreign_org', 'name' => 'Foreign Organization', 'is_active' => true,
        ]);
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $foreignOrg->id, 'code' => 'v2_canonical_dirty_foreign_company',
            'name' => 'Foreign Company', 'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id, 'code' => 'v2_canonical_dirty_foreign_entity',
            'name' => 'Foreign Entity', 'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $foreignEntity->id, 'code' => 'v2_canonical_dirty_foreign_unit',
            'name' => 'Foreign Unit', 'is_active' => true,
        ]);
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);
        $decoyStore = Store::query()->create([
            'store_type_id' => $typeId, 'code' => 'v2-canonical-dirty-decoy',
            'name' => 'Unrelated Store', 'is_active' => true,
        ]);
        $originalStores = [];
        foreach (['ar', 'en'] as $locale) {
            $originalStores[$locale] = Store::query()->create([
                'store_type_id' => $typeId, 'code' => 'v2-canonical-dirty-'.$locale,
                'name' => 'Original '.$locale, 'is_active' => true,
            ]);
        }
        $defaultStore = Store::query()->create([
            'store_type_id' => $typeId, 'code' => 'v2-canonical-dirty-default',
            'name' => 'Default-Path Store', 'is_active' => true,
        ]);
        $beforeAudit = DB::table('audit_logs')->count();

        // Exercise the public canonical writer directly: unlike the Admin
        // facade, it previously trusted both mutable Eloquent primary keys.
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $originalStore = $originalStores[$locale];
            $dirtyStore = Store::query()->findOrFail($originalStore->id);
            $dirtyUnit = EnterpriseBusinessUnit::query()->findOrFail($defaultUnit->id);
            $dirtyStore->forceFill(['id' => $decoyStore->id]);
            $dirtyUnit->forceFill(['id' => $foreignUnit->id]);
            $this->assertNotSame($dirtyStore->id, $dirtyStore->getRawOriginal('id'));
            $this->assertNotSame($dirtyUnit->id, $dirtyUnit->getRawOriginal('id'));

            $scope = $hierarchy->assignStore($dirtyStore, $dirtyUnit, $admin);
            $this->assertSame((int) $originalStore->id, (int) $scope->store_id);
            $this->assertSame((int) $defaultUnit->id, (int) $scope->business_unit_id);
            $this->assertDatabaseMissing('enterprise_store_scopes', [
                'store_id' => $originalStore->id, 'business_unit_id' => $foreignUnit->id,
            ]);
        }

        // Default-scope provisioning must resolve the original Store ID even
        // when another store has a syntactically valid, dirty caller key.
        $dirtyDefaultStore = Store::query()->findOrFail($defaultStore->id);
        $dirtyDefaultStore->forceFill(['id' => $decoyStore->id]);
        $scope = $hierarchy->ensureDefaultScopeForStore($dirtyDefaultStore, $admin);
        $this->assertSame((int) $defaultStore->id, (int) $scope->store_id);
        $this->assertSame((int) $defaultUnit->id, (int) $scope->business_unit_id);
        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $decoyStore->id]);
        $this->assertSame($beforeAudit + 3, DB::table('audit_logs')->count());

        // Existing canonical mapping is idempotent; no second owner/audit.
        $hierarchy->assignStore($defaultStore, $defaultUnit, $admin);
        $this->assertSame($beforeAudit + 3, DB::table('audit_logs')->count());
    }

    public function test_dirty_store_and_business_unit_keys_cannot_redirect_scope_assignment_in_both_locales(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $originalUnit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)->firstOrFail();

        $otherOrg = EnterpriseOrganization::query()->create([
            'code' => 'v2_scope_identity_other_org', 'name' => 'Other Organization', 'is_active' => true,
        ]);
        $otherCompany = EnterpriseCompany::query()->create([
            'organization_id' => $otherOrg->id,
            'code' => 'v2_scope_identity_other_company', 'name' => 'Other Company', 'is_active' => true,
        ]);
        $otherEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $otherCompany->id,
            'code' => 'v2_scope_identity_other_entity', 'name' => 'Other Entity', 'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $otherEntity->id,
            'code' => 'v2_scope_identity_other_unit', 'name' => 'Other Unit', 'is_active' => true,
        ]);
        $storeTypeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($storeTypeId);
        $stores = [];
        foreach (['ar', 'en'] as $locale) {
            $stores[$locale] = Store::query()->create([
                'store_type_id' => $storeTypeId,
                'code' => 'v2-scope-identity-'.$locale,
                'name' => 'Original Store '.$locale, 'is_active' => true,
            ]);
        }

        $originalStore = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'v2-scope-identity-target', 'name' => 'Persisted Target', 'is_active' => true,
        ]);
        $foreignStore = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => 'v2-scope-identity-foreign', 'name' => 'Unrelated Store', 'is_active' => true,
        ]);
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $store = $stores[$locale];
            $dirtyUnit = EnterpriseBusinessUnit::query()->findOrFail($originalUnit->id);
            $dirtyUnit->forceFill(['id' => $foreignUnit->id]);
            $this->assertNotSame($dirtyUnit->id, $dirtyUnit->getRawOriginal('id'));

            // Mutating an Eloquent instance never authorizes another unit's
            // ownership. The original persisted unit must be used instead.
            $service->assignStore($store, $dirtyUnit, $admin);
            $this->assertDatabaseHas('enterprise_store_scopes', [
                'store_id' => $store->id, 'business_unit_id' => $originalUnit->id,
            ]);
            $this->assertDatabaseMissing('enterprise_store_scopes', [
                'store_id' => $store->id, 'business_unit_id' => $foreignUnit->id,
            ]);
        }

        $dirtyStore = Store::query()->findOrFail($originalStore->id);
        $dirtyStore->forceFill(['id' => $foreignStore->id]);
        $this->assertNotSame($dirtyStore->id, $dirtyStore->getRawOriginal('id'));

        $service->assignStore($dirtyStore, $originalUnit, $admin);
        $this->assertDatabaseHas('enterprise_store_scopes', [
            'store_id' => $originalStore->id, 'business_unit_id' => $originalUnit->id,
        ]);
        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $foreignStore->id]);
        $this->assertSame($auditBefore + 3, DB::table('audit_logs')->count());
    }

    public function test_stale_active_business_unit_cannot_receive_store_after_persisted_deactivation(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_stale_store_scope_org',
            'name' => 'Stale Unit Store Scope Organization',
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_stale_store_scope_company',
            'name' => 'Stale Scope Company',
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_stale_store_scope_entity',
            'name' => 'Stale Scope Entity',
            'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_stale_store_scope_unit',
            'name' => 'Stale Scope Unit',
            'is_active' => true,
        ]);
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'v2-stale-store-scope',
            'name' => 'Stale Unit Scope Store',
            'is_active' => true,
        ]);
        $unit->load('legalEntity.company.organization');
        $beforeAudit = DB::table('audit_logs')->count();

        // Keep $unit and its loaded ancestor relations stale in memory.
        DB::table('enterprise_business_units')->where('id', $unit->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $unit->is_active);

        try {
            app(EnterpriseOrganizationAdministration::class)->assignStore($store, $unit, $admin);
            $this->fail('A deactivated persisted Business Unit must reject a stale assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('store', $exception->errors());
        }

        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->id]);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        // A fresh active record must still be assignable through the same
        // service, so the guard does not block legitimate first-time setup.
        DB::table('enterprise_business_units')->where('id', $unit->id)->update(['is_active' => true]);
        app(EnterpriseOrganizationAdministration::class)->assignStore($store, $unit, $admin);
        $this->assertDatabaseHas('enterprise_store_scopes', [
            'store_id' => $store->id,
            'business_unit_id' => $unit->id,
        ]);
        $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
    }

    public function test_failed_create_save_restores_values_only_to_create_form(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->where('code', 'foodex_platform')->firstOrFail();
        $showUrl = route('admin.enterprise-organizations.show', $organization);

        $this->actingAs($admin)->from($showUrl)
            ->post(route('admin.enterprise-companies.store', $organization), [
                'name' => 'Uncommitted Company',
                'code' => '../invalid',
                'is_active' => '0',
                '_setup_form' => 'create',
            ])->assertRedirect($showUrl)
            ->assertSessionHasErrors('code');

        $html = $this->actingAs($admin)->get($showUrl)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="name" value="Uncommitted Company"'));
        $this->assertSame(1, substr_count($html, 'name="code" value="../invalid"'));
        $this->assertSame(1, substr_count($html, 'name="is_active" value="1" checked'));
        $this->assertDatabaseMissing('enterprise_companies', ['name' => 'Uncommitted Company']);
    }

    public function test_failed_edit_save_restores_values_only_to_edit_form(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->where('code', 'foodex_platform')->firstOrFail();
        $originalName = $organization->name;
        $showUrl = route('admin.enterprise-organizations.show', $organization);

        $this->actingAs($admin)->from($showUrl)
            ->patch(route('admin.enterprise-organizations.update', $organization), [
                'name' => 'Uncommitted Organization Rename',
                'code' => '../invalid',
                'is_active' => '0',
                '_setup_form' => 'edit',
            ])->assertRedirect($showUrl)
            ->assertSessionHasErrors('code');

        $html = $this->actingAs($admin)->get($showUrl)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="name" value="Uncommitted Organization Rename"'));
        $this->assertSame(1, substr_count($html, 'name="code" value="../invalid"'));
        $this->assertSame(1, substr_count($html, 'name="is_active" value="1" checked'));
        $this->assertSame($originalName, $organization->fresh()->name);
    }

    public function test_non_admin_cannot_test_or_save_organization_inputs(): void
    {
        $finance = $this->actor('FINANCE', 'en');
        $payload = ['name' => 'Unauthorized', 'code' => 'forbidden', '_setup_test' => '1'];

        $this->actingAs($finance)->post(route('admin.enterprise-organizations.store'), $payload)
            ->assertForbidden();

        unset($payload['_setup_test']);
        $this->actingAs($finance)->post(route('admin.enterprise-organizations.store'), $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_organizations', ['code' => 'forbidden']);
    }

    public function test_bilingual_existing_admin_form_displays_help_unverified_readiness_and_test_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');

        $this->actingAs($admin)->get(route('admin.enterprise-organizations.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-v2-organization-setup-readiness="unverified"', false)
            ->assertSee('Test inputs (no save)')
            ->assertSee('Source: use the organization', false);

        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin->fresh())->get(route('admin.enterprise-organizations.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('غير مُتحقق منه')
            ->assertSee('اختبار الإدخال دون حفظ');
    }

    public function test_seeded_default_organization_code_and_activation_are_protected_on_preview_and_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $before = DB::table('audit_logs')->count();

        foreach ([true, false] as $preview) {
            $payload = [
                'code' => 'changed_platform_code',
                'name' => 'Corrected Organization Display Name',
                'is_active' => '1',
                '_setup_form' => 'edit',
            ];
            if ($preview) {
                $payload['_setup_test'] = 'edit';
            }

            $this->actingAs($admin)->patch(
                route('admin.enterprise-organizations.update', $organization), $payload,
            )->assertSessionHasErrors('code');
        }

        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'code' => $organization->code,
            'name' => 'Default Organization',
            'is_active' => '0',
            '_setup_test' => 'edit',
        ])->assertSessionHasErrors('is_active');

        $this->assertSame(OrganizationHierarchyService::DEFAULT_ORGANIZATION, $organization->fresh()->code);
        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_dirty_default_organization_instance_cannot_rename_reserved_identity(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $auditBefore = DB::table('audit_logs')->count();

        // An unguarded Eloquent instance can be changed in memory before a
        // service call. That forged code must not change what Preview sees.
        $organization->code = 'spoofed_platform_identity';
        foreach (['preview', 'save'] as $operation) {
            try {
                $data = ['code' => 'captured_platform_identity', 'name' => 'Hijacked Default Organization'];
                if ($operation === 'preview') {
                    $service->assertUpdateAllowed($organization, $data);
                } else {
                    $service->updateOrganization($organization, $data, $admin);
                }
                $this->fail('A dirty caller model must not authorize a default routing identity change.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('code', $exception->errors());
            }
        }

        $this->assertSame(OrganizationHierarchyService::DEFAULT_ORGANIZATION, $organization->fresh()->code);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // A real metadata-only update remains permitted, but cannot flush
        // the unrelated dirty caller attribute while saving the new name.
        $service->updateOrganization($organization, ['name' => 'Verified Platform Display'], $admin);
        $this->assertSame(OrganizationHierarchyService::DEFAULT_ORGANIZATION, $organization->fresh()->code);
        $this->assertSame('Verified Platform Display', $organization->fresh()->name);
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
    }

    public function test_dirty_business_unit_instance_cannot_reparent_during_metadata_only_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)->firstOrFail();
        $originalEntityId = (int) $unit->getRawOriginal('legal_entity_id');

        $otherOrganization = EnterpriseOrganization::query()->create([
            'code' => 'v2_dirty_model_other_org', 'name' => 'Other Organization', 'is_active' => true,
        ]);
        $otherCompany = EnterpriseCompany::query()->create([
            'organization_id' => $otherOrganization->id, 'code' => 'v2_dirty_model_other_company',
            'name' => 'Other Company', 'is_active' => true,
        ]);
        $otherEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $otherCompany->id, 'code' => 'v2_dirty_model_other_entity',
            'name' => 'Other Entity', 'is_active' => true,
        ]);
        $this->assertNotSame($originalEntityId, (int) $otherEntity->id);
        $auditBefore = DB::table('audit_logs')->count();

        $unit->legal_entity_id = $otherEntity->id;
        $service->updateBusinessUnit($unit, ['name' => 'Safe Business Unit Display'], $admin);

        $this->assertSame($originalEntityId, (int) $unit->fresh()->legal_entity_id);
        $this->assertSame(OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT, $unit->fresh()->code);
        $this->assertSame('Safe Business Unit Display', $unit->fresh()->name);
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
    }

    public function test_default_business_unit_code_is_guarded_and_display_name_stays_editable(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)->firstOrFail();
        $before = DB::table('audit_logs')->count();

        foreach ([true, false] as $preview) {
            $payload = [
                'name' => 'Renamed Business Unit',
                'code' => 'different_unit_code',
                'is_active' => '1',
                '_setup_form' => 'edit',
            ];
            if ($preview) {
                $payload['_setup_test'] = 'edit';
            }
            $this->actingAs($admin)->patch(route('admin.enterprise-business-units.update', $unit), $payload)
                ->assertSessionHasErrors('code');
        }

        $this->actingAs($admin)->patch(route('admin.enterprise-business-units.update', $unit), [
            'name' => 'Renamed Business Unit',
            'code' => $unit->code,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame('Renamed Business Unit', $unit->fresh()->name);
        $this->assertSame(OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT, $unit->fresh()->code);
        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    public function test_renamed_default_hierarchy_remains_usable_for_new_store_without_reseed(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'name' => 'Verified Display Name (not legal certification)',
            'code' => $organization->code,
            'is_active' => '1',
        ])->assertRedirect();

        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'D-DEFAULT-NEW-STORE',
            'name' => 'Disposable Test Store',
            'is_active' => true,
        ]);

        $scope = app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $this->assertSame($store->getKey(), $scope->store_id);
        $this->assertSame(OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT, $scope->businessUnit->code);
        $this->assertSame('Verified Display Name (not legal certification)', $organization->fresh()->name);
    }

    public function test_direct_service_cannot_spoof_cached_lineage_to_rename_reserved_default_unit(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)->firstOrFail();
        $foreignOrganization = EnterpriseOrganization::query()->create([
            'code' => 'v2_foreign_cached_lineage', 'name' => 'Foreign Cached Lineage', 'is_active' => true,
        ]);
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $foreignOrganization->id,
            'code' => 'v2_foreign_cached_company', 'name' => 'Foreign Company', 'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'v2_foreign_cached_entity', 'name' => 'Foreign Entity', 'is_active' => true,
        ]);
        $foreignEntity->load('company.organization');

        // The persisted unit still belongs to the protected default hierarchy.
        // A direct service caller must not authorize identity changes by
        // injecting a foreign preloaded relation on its Eloquent instance.
        $unit->setRelation('legalEntity', $foreignEntity);
        $before = DB::table('audit_logs')->count();

        try {
            app(EnterpriseOrganizationAdministration::class)->updateBusinessUnit($unit, [
                'code' => 'stolen_routing_identity',
                'name' => 'Attempted Reserved Unit Rename',
                'is_active' => true,
            ], $admin);
            $this->fail('Persisted default routing ancestry must override cached relations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame(OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT, $unit->fresh()->code);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_ambiguous_default_unit_in_another_legal_entity_cannot_capture_new_store_scope(): void
    {
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $originalUnit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)
            ->whereHas('legalEntity.company.organization', fn ($query) => $query
                ->whereKey($organization->getKey()))
            ->firstOrFail();

        // Business-unit codes are unique only within each legal entity.
        // An alternate legal entity inside FOODEX may reuse the code, but it
        // must never become the arbitrarily selected home for a new store.
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'v2_other_default_company',
            'name' => 'Other Default Candidate Company',
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->getKey(),
            'code' => 'v2_other_default_entity',
            'name' => 'Other Default Candidate Legal Entity',
            'is_active' => true,
        ]);
        $duplicate = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->getKey(),
            'code' => OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT,
            'name' => 'Ambiguous Other Legal Entity Unit',
            'is_active' => true,
        ]);
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'V2-AMBIGUOUS-DEFAULT-STORE',
            'name' => 'Unassigned Until Unique Default',
            'is_active' => true,
        ]);
        $auditBefore = DB::table('audit_logs')->count();

        try {
            app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
            $this->fail('A duplicate default business-unit code must not assign a new store.');
        } catch (MultipleRecordsFoundException) {
            // No arbitrary first() candidate is a legitimate default.
        }

        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->getKey()]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // The established seeded default still works after ambiguity is
        // resolved, without reseeding or changing the organization name.
        $duplicate->delete();
        $scope = app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $this->assertSame($originalUnit->getKey(), $scope->business_unit_id);
        $this->assertSame($store->getKey(), $scope->store_id);
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
    }

    public function test_direct_service_cannot_bypass_reserved_routing_code_guard(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'ar');
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(EnterpriseOrganizationAdministration::class)->updateOrganization(
            $organization,
            ['code' => 'unprotected_code', 'name' => $organization->name, 'is_active' => true],
            $admin,
        );
    }

    public function test_direct_service_rejects_string_and_integer_inactive_flags_without_bypassing_guards(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $protectedOrganization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_service_deactivation_guard',
            'name' => 'Guarded Organization',
            'is_active' => true,
        ]);
        EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_service_active_child',
            'name' => 'Active Child',
            'is_active' => true,
        ]);
        $before = DB::table('audit_logs')->count();
        $service = app(EnterpriseOrganizationAdministration::class);

        foreach (['0', 0, false] as $flag) {
            try {
                $service->updateOrganization($organization, [
                    'code' => $organization->code,
                    'name' => $organization->name,
                    'is_active' => $flag,
                ], $admin);
                $this->fail('Active descendants must block deactivation for all false-like flags.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('is_active', $exception->errors());
            }

            try {
                $service->updateOrganization($protectedOrganization, [
                    'code' => $protectedOrganization->code,
                    'name' => $protectedOrganization->name,
                    'is_active' => $flag,
                ], $admin);
                $this->fail('Default routing identity must remain active for all false-like flags.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('is_active', $exception->errors());
            }
        }

        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertTrue((bool) $protectedOrganization->fresh()->is_active);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_inactive_legacy_descendants_with_assigned_store_block_all_parent_deactivations(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_scope_guard_org', 'name' => 'Scoped Organization', 'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_scope_guard_company', 'name' => 'Legacy Company', 'is_active' => false,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_scope_guard_entity', 'name' => 'Legacy Entity', 'is_active' => false,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_scope_guard_unit', 'name' => 'Legacy Unit', 'is_active' => false,
        ]);
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);
        $store = Store::query()->create([
            'store_type_id' => $typeId, 'code' => 'V2-SCOPE-GUARD-STORE',
            'name' => 'Existing Scoped Store', 'is_active' => true,
        ]);
        $unit->storeScopes()->create(['store_id' => $store->id]);
        $auditCount = DB::table('audit_logs')->count();

        // Preview, Save and direct service must agree, even when the legacy
        // descendants are all inactive but their store binding is still live.
        foreach ([
            [route('admin.enterprise-organizations.update', $organization), $organization],
            [route('admin.enterprise-companies.update', $company), $company],
            [route('admin.enterprise-legal-entities.update', $entity), $entity],
        ] as [$url, $record]) {
            foreach ([true, false] as $preview) {
                $payload = [
                    'code' => $record->code, 'name' => $record->name, 'is_active' => '0',
                ];
                if ($preview) {
                    $payload['_setup_test'] = 'edit';
                }
                $this->actingAs($admin)->patch($url, $payload)
                    ->assertSessionHasErrors('is_active');
            }
        }

        $service = app(EnterpriseOrganizationAdministration::class);
        foreach ([
            [$organization, 'updateOrganization'],
            [$company, 'updateCompany'],
            [$entity, 'updateLegalEntity'],
        ] as [$record, $method]) {
            try {
                $service->{$method}($record, [
                    'code' => $record->code, 'name' => $record->name, 'is_active' => false,
                ], $admin);
                $this->fail('A store-scoped legacy descendant cannot be orphaned.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('is_active', $exception->errors());
            }
        }
        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertDatabaseHas('enterprise_store_scopes', [
            'business_unit_id' => $unit->id, 'store_id' => $store->id,
        ]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_direct_service_rejects_unguarded_identity_timestamp_and_extra_fields_without_audit(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_identity_guard_org', 'name' => 'Identity Guard Org', 'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_identity_guard_company', 'name' => 'Identity Guard Company', 'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_identity_guard_entity', 'name' => 'Identity Guard Entity', 'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_identity_guard_unit', 'name' => 'Identity Guard Unit', 'is_active' => true,
        ]);
        $beforeAudit = DB::table('audit_logs')->count();

        // The HTTP form validates just code/name/is_active, but internal
        // callers reach this service directly. Guarded=[] on every model
        // must not permit rewriting primary keys, timestamps or extra fields.
        foreach ([
            [$organization, 'updateOrganization'],
            [$company, 'updateCompany'],
            [$entity, 'updateLegalEntity'],
            [$unit, 'updateBusinessUnit'],
        ] as [$record, $method]) {
            $id = (int) $record->getKey();
            $created = $record->getRawOriginal('created_at');
            $updated = $record->getRawOriginal('updated_at');
            $name = $record->name;
            foreach ([
                ['id' => $id + 50000],
                ['created_at' => '2001-01-01 00:00:00'],
                ['updated_at' => '2001-01-01 00:00:00'],
                ['tenant_id' => 50000],
            ] as $injected) {
                try {
                    $service->{$method}($record, ['name' => 'Injected Name', ...$injected], $admin);
                    $this->fail('Unexpected unvalidated hierarchy service attribute was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('record', $exception->errors());
                }
            }

            $stored = $record->fresh();
            $this->assertNotNull($stored);
            $this->assertSame($id, (int) $stored->getKey());
            $this->assertSame($name, $stored->name);
            $this->assertSame($created, $stored->getRawOriginal('created_at'));
            $this->assertSame($updated, $stored->getRawOriginal('updated_at'));
        }

        // Related creation methods also mass-assign into unguarded models;
        // the parent relation and record identity must remain server-owned.
        foreach ([
            [null, 'createOrganization', 'v2_denied_create_org'],
            [$organization, 'createCompany', 'v2_denied_create_company'],
            [$company, 'createLegalEntity', 'v2_denied_create_entity'],
            [$entity, 'createBusinessUnit', 'v2_denied_create_unit'],
        ] as [$parent, $method, $code]) {
            $data = [
                'code' => $code, 'name' => 'Unvalidated New Record',
                'is_active' => true, 'id' => 50000,
            ];
            try {
                if ($parent === null) {
                    $service->{$method}($data, $admin);
                } else {
                    $service->{$method}($parent, $data, $admin);
                }
                $this->fail('Creating a hierarchy record with a caller-selected ID must be denied.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('record', $exception->errors());
            }
        }

        foreach ([
            ['enterprise_organizations', 'v2_denied_create_org'],
            ['enterprise_companies', 'v2_denied_create_company'],
            ['enterprise_legal_entities', 'v2_denied_create_entity'],
            ['enterprise_business_units', 'v2_denied_create_unit'],
        ] as [$table, $code]) {
            $this->assertDatabaseMissing($table, ['code' => $code]);
        }
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_direct_hierarchy_service_rejects_uncanonical_master_data_in_ar_and_en(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'p112_direct_integrity_org', 'name' => 'Direct Integrity Organization', 'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id, 'code' => 'p112_direct_integrity_company',
            'name' => 'Direct Integrity Company', 'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id, 'code' => 'p112_direct_integrity_entity',
            'name' => 'Direct Integrity Entity', 'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id, 'code' => 'p112_direct_integrity_unit',
            'name' => 'Direct Integrity Unit', 'is_active' => true,
        ]);
        $cases = [
            [$organization, null, 'createOrganization', 'updateOrganization', 'enterprise_organizations'],
            [$company, $organization, 'createCompany', 'updateCompany', 'enterprise_companies'],
            [$entity, $company, 'createLegalEntity', 'updateLegalEntity', 'enterprise_legal_entities'],
            [$unit, $entity, 'createBusinessUnit', 'updateBusinessUnit', 'enterprise_business_units'],
        ];
        $auditBefore = DB::table('audit_logs')->count();

        // Service entry points must enforce the same stored code/name
        // invariants as the authenticated setup Preview and Save endpoints.
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($cases as [$record, $parent, $create, $update, $table]) {
                foreach ([
                    ['code' => '../unsafe', 'name' => 'Rejected', 'error' => 'code'],
                    ['code' => 'MIXED_CODE', 'name' => 'Rejected', 'error' => 'code'],
                    ['code' => 'p112_invalid_blank', 'name' => '   ', 'error' => 'name'],
                    ['code' => str_repeat('a', 101), 'name' => 'Rejected', 'error' => 'code'],
                    ['code' => 'p112_invalid_long_name', 'name' => str_repeat('N', 256), 'error' => 'name'],
                    ['code' => 'p112_invalid_flag', 'name' => 'Rejected', 'is_active' => 'enabled', 'error' => 'is_active'],
                ] as $invalid) {
                    $createData = [
                        'code' => $invalid['code'],
                        'name' => $invalid['name'],
                        'is_active' => $invalid['is_active'] ?? true,
                    ];

                    try {
                        if ($parent === null) {
                            $service->{$create}($createData, $admin);
                        } else {
                            $service->{$create}($parent, $createData, $admin);
                        }
                        $this->fail('Direct hierarchy creation must reject uncanonical fields.');
                    } catch (ValidationException $exception) {
                        $this->assertArrayHasKey($invalid['error'], $exception->errors());
                    }

                    try {
                        $service->{$update}($record, $createData, $admin);
                        $this->fail('Direct hierarchy update must reject uncanonical fields.');
                    } catch (ValidationException $exception) {
                        $this->assertArrayHasKey($invalid['error'], $exception->errors());
                    }

                    $this->assertDatabaseMissing($table, [
                        'code' => $invalid['code'], 'name' => $invalid['name'],
                    ]);
                    $this->assertSame($record->code, $record->fresh()->code);
                    $this->assertSame($record->name, $record->fresh()->name);
                    $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                }
            }
        }

        // Legitimate canonical direct updates still work and remain audited.
        $service->updateOrganization($organization, [
            'code' => $organization->code,
            'name' => 'Authorized Canonical Rename',
            'is_active' => true,
        ], $admin);
        $this->assertSame('Authorized Canonical Rename', $organization->fresh()->name);
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());

        $inactive = $service->createOrganization([
            'code' => 'p112_direct_valid_inactive',
            'name' => 'Valid Inactive Hierarchy',
            'is_active' => '0',
        ], $admin);
        $this->assertFalse((bool) $inactive->is_active);
        $this->assertSame($auditBefore + 2, DB::table('audit_logs')->count());
    }

    public function test_child_creation_uses_persisted_parent_identity_and_rejects_stale_inactive_parent(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $service = app(EnterpriseOrganizationAdministration::class);
        $original = EnterpriseOrganization::query()->create([
            'code' => 'v2_child_origin', 'name' => 'Origin', 'is_active' => true,
        ]);
        $foreign = EnterpriseOrganization::query()->create([
            'code' => 'v2_child_foreign', 'name' => 'Foreign', 'is_active' => true,
        ]);
        $beforeAudit = DB::table('audit_logs')->count();

        // Unguarded Eloquent models allow a caller to dirty their primary key.
        // A service must bind the new company to the persisted original ID.
        $dirty = $original->fresh();
        $dirty->id = $foreign->id;
        $company = $service->createCompany($dirty, [
            'code' => 'v2_child_bound', 'name' => 'Bound', 'is_active' => true,
        ], $admin);
        $this->assertSame((int) $original->id, (int) $company->organization_id);
        $this->assertNotSame((int) $foreign->id, (int) $company->organization_id);

        $stale = $original->fresh();
        $original->update(['is_active' => false]);
        $auditAfterAllowed = DB::table('audit_logs')->count();

        try {
            $service->createCompany($stale, [
                'code' => 'v2_child_denied_stale', 'name' => 'Denied', 'is_active' => true,
            ], $admin);
            $this->fail('A disabled persisted parent must not accept new children.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent', $exception->errors());
        }
        $this->assertDatabaseMissing('enterprise_companies', ['code' => 'v2_child_denied_stale']);
        $this->assertSame($auditAfterAllowed, DB::table('audit_logs')->count());
        $this->assertGreaterThanOrEqual($beforeAudit, $auditAfterAllowed);
    }

    public function test_creation_parent_preflight_uses_original_identity_not_dirty_active_decoy(): void
    {
        $service = app(EnterpriseOrganizationAdministration::class);

        $inactive = EnterpriseOrganization::query()->create([
            'code' => 'v2_preflight_original_inactive', 'name' => 'Inactive Origin', 'is_active' => false,
        ]);
        $activeDecoy = EnterpriseOrganization::query()->create([
            'code' => 'v2_preflight_active_decoy', 'name' => 'Active Decoy', 'is_active' => true,
        ]);
        $beforeAudit = DB::table('audit_logs')->count();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $dirty = $inactive->fresh();
            $dirty->id = $activeDecoy->id;
            try {
                $service->assertCreationParentActive($dirty);
                $this->fail('A mutated primary key must never authorize an inactive persisted parent.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent', $exception->errors());
            }

            $positive = $activeDecoy->fresh();
            $positive->id = $inactive->id;
            $service->assertCreationParentActive($positive);
        }

        $this->assertDatabaseMissing('enterprise_companies', ['code' => 'v2_preflight_unexpected_child']);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_canonical_assignment_denies_persisted_inactive_store_even_from_stale_active_model(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $hierarchy = app(OrganizationHierarchyService::class);
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)
            ->whereHas('legalEntity.company.organization', fn ($query) => $query
                ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION))
            ->firstOrFail();
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $store = Store::query()->create([
                'store_type_id' => $typeId,
                'code' => 'v2-inactive-scope-'.$locale,
                'name' => 'Deactivated '.$locale,
                'is_active' => true,
            ]);
            $staleActive = $store->fresh();
            DB::table('stores')->where('id', $store->id)->update(['is_active' => false]);
            $this->assertTrue((bool) $staleActive->is_active);
            $auditBefore = DB::table('audit_logs')->count();

            try {
                $hierarchy->assignStore($staleActive, $unit, $admin);
                $this->fail('Persisted inactive store must not receive canonical enterprise scope.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('inactive store', $exception->getMessage());
            }
            $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->id]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            // Re-enable the actual persisted store: the normal first assignment
            // and its idempotent retry remain valid and produce one audit.
            DB::table('stores')->where('id', $store->id)->update(['is_active' => true]);
            $scope = $hierarchy->assignStore($staleActive, $unit, $admin);
            $this->assertSame((int) $store->id, (int) $scope->store_id);
            $this->assertSame((int) $unit->id, (int) $scope->business_unit_id);
            $hierarchy->assignStore($staleActive, $unit, $admin);
            $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
        }
    }

    public function test_direct_organization_writes_reject_forged_and_offboarded_audit_actors_in_both_locales(): void
    {
        $service = app(EnterpriseOrganizationAdministration::class);
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)
            ->whereHas('legalEntity.company.organization', fn ($query) => $query
                ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION))
            ->firstOrFail();
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $originalActor = $this->actor('SUPER_ADMIN', $locale);
            $independentActor = $this->actor('SUPER_ADMIN', $locale);
            $nonAdminActor = $this->actor('FINANCE', $locale);
            $organization = $service->createOrganization([
                'code' => 'v2_actor_guard_'.$locale,
                'name' => 'Original organization '.$locale,
                'is_active' => true,
            ], $originalActor);
            $store = Store::query()->create([
                'store_type_id' => $typeId,
                'code' => 'v2-actor-guard-'.$locale,
                'name' => 'Actor guard '.$locale,
                'is_active' => true,
            ]);

            // An unsaved Eloquent key change must not attribute a live write
            // to the second, otherwise legitimate, Super Admin.
            $forgedActor = $originalActor->fresh();
            $forgedActor->id = $independentActor->id;

            // An actor loaded before offboarding must not retain write/audit
            // authority through a stale in-memory is_active=true property.
            $offboardedActor = $originalActor->fresh();
            DB::table('users')->where('id', $originalActor->id)->update(['is_active' => false]);
            $this->assertTrue((bool) $offboardedActor->is_active);
            $auditBefore = DB::table('audit_logs')->count();

            foreach (['forged' => $forgedActor, 'offboarded' => $offboardedActor] as $case => $actor) {
                foreach ([
                    'create' => fn () => $service->createOrganization([
                        'code' => 'v2_forbidden_'.$case.'_'.$locale,
                        'name' => 'Forbidden',
                        'is_active' => true,
                    ], $actor),
                    'update' => fn () => $service->updateOrganization($organization, [
                        'code' => $organization->code,
                        'name' => 'Forbidden rename',
                        'is_active' => true,
                    ], $actor),
                    'assign' => fn () => $service->assignStore($store, $unit, $actor),
                ] as $operation => $write) {
                    try {
                        $write();
                        $this->fail("The {$case} actor must not {$operation} enterprise scope.");
                    } catch (HttpException $exception) {
                        $this->assertSame(403, $exception->getStatusCode());
                    }
                }
            }

            // Active FINANCE identity is genuine but does not have the
            // platform.manage capability. The canonical service must deny
            // direct writes just as the protected HTTP route does.
            foreach ([
                'create' => fn () => $service->createOrganization([
                    'code' => 'v2_forbidden_unprivileged_'.$locale,
                    'name' => 'Unauthorized organization',
                    'is_active' => true,
                ], $nonAdminActor),
                'update' => fn () => $service->updateOrganization($organization, [
                    'code' => $organization->code,
                    'name' => 'Unauthorized rename',
                    'is_active' => true,
                ], $nonAdminActor),
                'assign' => fn () => $service->assignStore($store, $unit, $nonAdminActor),
            ] as $operation => $write) {
                try {
                    $write();
                    $this->fail("FINANCE actor must not {$operation} enterprise hierarchy.");
                } catch (AuthorizationException $exception) {
                    $this->assertSame(403, $exception->status() ?? 403);
                }
            }
            $this->assertDatabaseMissing('enterprise_organizations', [
                'code' => 'v2_forbidden_unprivileged_'.$locale,
            ]);

            $this->assertDatabaseMissing('enterprise_organizations', [
                'code' => 'v2_forbidden_forged_'.$locale,
            ]);
            $this->assertDatabaseMissing('enterprise_organizations', [
                'code' => 'v2_forbidden_offboarded_'.$locale,
            ]);
            $this->assertSame('Original organization '.$locale, $organization->fresh()->name);
            $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->id]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            // Persisted, active Super Admin still executes the normal workflow
            // and the audit trail attributes each action to the real user.
            $service->updateOrganization($organization, [
                'code' => $organization->code,
                'name' => 'Legitimate rename '.$locale,
                'is_active' => true,
            ], $independentActor);
            $service->assignStore($store, $unit, $independentActor);
            $this->assertSame('Legitimate rename '.$locale, $organization->fresh()->name);
            $this->assertDatabaseHas('enterprise_store_scopes', ['store_id' => $store->id, 'business_unit_id' => $unit->id]);
            $this->assertSame($auditBefore + 2, DB::table('audit_logs')->count());
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'enterprise.organization.updated',
                'user_id' => $independentActor->id,
                'auditable_id' => $organization->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'enterprise.organization.store_scope_assigned',
                'user_id' => $independentActor->id,
                'auditable_id' => $store->id,
            ]);
        }
    }

    public function test_direct_organization_writes_recheck_persisted_permission_denial_in_both_locales(): void
    {
        $service = app(EnterpriseOrganizationAdministration::class);
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)
            ->whereHas('legalEntity.company.organization', fn ($query) => $query
                ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION))
            ->firstOrFail();
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'platform.manage')->value('id');
        $this->assertNotNull($typeId);
        $this->assertNotNull($permissionId);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $admin = $this->actor('SYSTEM_ADMINISTRATOR', $locale);
            $this->assertTrue($admin->hasPermission('platform.manage'));
            $staleActor = $admin->fresh();

            $organization = EnterpriseOrganization::query()->create([
                'code' => 'v2_revocation_existing_'.$locale,
                'name' => 'Original name',
                'is_active' => true,
            ]);
            $store = Store::query()->create([
                'store_type_id' => $typeId,
                'code' => 'v2-revocation-'.$locale,
                'name' => 'Revocation check',
                'is_active' => true,
            ]);

            // Simulate a live role override revocation after an authenticated
            // service caller captured the user. HTTP middleware normally
            // rehydrates the user; direct service entry points must too.
            DB::table('user_permission_overrides')->insert([
                'user_id' => $admin->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $admin->id)
                ->update(['has_permission_overrides' => true]);

            $this->assertFalse((bool) $staleActor->has_permission_overrides);
            $this->assertTrue($staleActor->hasPermission('platform.manage'));
            $this->assertFalse($admin->fresh()->hasPermission('platform.manage'));
            $auditBefore = DB::table('audit_logs')->count();

            foreach ([
                'create' => fn () => $service->createOrganization([
                    'code' => 'v2_revocation_forbidden_'.$locale,
                    'name' => 'Forbidden',
                    'is_active' => true,
                ], $staleActor),
                'update' => fn () => $service->updateOrganization($organization, [
                    'name' => 'Unauthorized rename',
                ], $staleActor),
                'assign' => fn () => $service->assignStore($store, $unit, $staleActor),
            ] as $operation => $write) {
                try {
                    $write();
                    $this->fail("Stale permission override must deny {$operation}.");
                } catch (AuthorizationException $exception) {
                    $this->assertSame(403, $exception->status() ?? 403);
                }
            }

            $this->assertDatabaseMissing('enterprise_organizations', [
                'code' => 'v2_revocation_forbidden_'.$locale,
            ]);
            $this->assertSame('Original name', $organization->fresh()->name);
            $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->id]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            // Removing the DENY restores the legitimate administrator path,
            // even when the caller still holds its original user instance.
            DB::table('user_permission_overrides')->where('user_id', $admin->id)->delete();
            DB::table('users')->where('id', $admin->id)
                ->update(['has_permission_overrides' => false]);
            $service->updateOrganization($organization, ['name' => 'Authorized rename'], $staleActor);
            $this->assertSame('Authorized rename', $organization->fresh()->name);
            $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
        }
    }

    private function actor(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-setup-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
