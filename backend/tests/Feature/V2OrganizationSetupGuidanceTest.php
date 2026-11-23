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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
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
