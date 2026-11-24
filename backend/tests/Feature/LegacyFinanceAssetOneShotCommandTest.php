<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyFinanceAssetOneShotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.foodex_legacy' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('foodex_legacy');
        DB::connection('foodex_legacy')->getPdo()->exec("ATTACH DATABASE ':memory:' AS dbo");

        $this->createLegacySourceTables();
        $this->seedLegacyAssets();
    }

    public function test_dry_run_classifies_all_78_rows_and_preflights_only_candidates_without_writes(): void
    {
        $exitCode = Artisan::call('foodex:legacy-finance-assets-one-shot', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame('preserved_native_blocked', $report['status']);
        $this->assertSame(78, $report['classification']['source_rows']);
        $this->assertSame(74, $report['classification']['legacy_placeholder_rows']);
        $this->assertSame(4, $report['classification']['native_candidate_rows']);
        $this->assertSame(['1', '2'], array_slice($report['classification']['placeholder_source_keys'], 0, 2));
        $this->assertSame(4, $report['native_preflight']['rows']);
        $this->assertSame(4, $report['native_preflight']['blocked_rows']);
        $this->assertFalse($report['native_preflight']['ready_for_native_promotion']);
        $this->assertGreaterThan(0, $report['native_preflight']['blocker_counts']['missing_explicit_acquisition_offset_account_mapping']);
        $this->assertGreaterThan(0, $report['native_preflight']['blocker_counts']['missing_native_asset_category_mapping']);
        $this->assertSame(1, $report['source_authority']['legacy_group_asset_accounts']);
        $this->assertSame(1, $report['source_authority']['legacy_asset_headers']);
        $this->assertSame(0, $report['source_authority']['legacy_asset_headers_linked_to_gl']);
        $this->assertFalse($report['source_authority']['source_account_authority_complete']);
        $this->assertFalse($report['native_promotion_performed']);
        $this->assertNull($report['verification']);

        $this->assertSame(0, DB::table('legacy_fixed_asset_records')->count());
        $this->assertSame(0, DB::table('legacy_import_mappings')->count());
        $this->assertSame(0, DB::table('legacy_import_runs')->count());
    }

    public function test_apply_verifies_all_78_rows_preserves_bilingual_names_and_never_promotes_native_assets(): void
    {
        $exitCode = Artisan::call('foodex:legacy-finance-assets-one-shot', ['--apply' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('apply', $report['mode']);
        $this->assertTrue($report['verification']['healthy']);
        $this->assertSame(78, $report['verification']['source_rows']);
        $this->assertSame(78, $report['verification']['materialized_rows']);
        $this->assertSame(78, $report['verification']['mapping_rows']);
        $this->assertSame(4, $report['native_preflight']['rows']);
        $this->assertFalse($report['native_preflight']['ready_for_native_promotion']);
        $this->assertFalse($report['native_promotion_performed']);

        $asset = DB::table('legacy_fixed_asset_records')->where('source_key', '75')->first();
        $this->assertNotNull($asset);
        $this->assertSame('أصل تجريبي', $asset->name_ar);
        $this->assertSame('Sample asset 075', $asset->name_en);
        $this->assertSame(78, DB::table('legacy_fixed_asset_records')->count());
        $this->assertSame(78, DB::table('legacy_import_mappings')->count());
        $this->assertSame(1, DB::table('legacy_import_runs')->where('status', 'completed')->count());

        $secondExitCode = Artisan::call('foodex:legacy-finance-assets-one-shot', ['--apply' => true, '--json' => true]);
        $secondReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $secondExitCode);
        $this->assertTrue($secondReport['verification']['healthy']);
        $this->assertSame(78, DB::table('legacy_fixed_asset_records')->count());
        $this->assertSame(78, DB::table('legacy_import_mappings')->count());
        $this->assertSame(2, DB::table('legacy_import_runs')->where('status', 'completed')->count());
    }

    private function createLegacySourceTables(): void
    {
        $schema = Schema::connection('foodex_legacy');

        $schema->create('dbo.gl_AssetsGroups', function (Blueprint $table): void {
            $table->integer('ID')->primary();
            $table->string('Code')->nullable();
            $table->string('Name_AR')->nullable();
            $table->string('Name_En')->nullable();
            $table->integer('GlAccId')->nullable();
        });

        $schema->create('dbo.gl_AssetsSupGroups', function (Blueprint $table): void {
            $table->integer('ID')->primary();
            $table->string('Code')->nullable();
            $table->string('Name_AR')->nullable();
            $table->string('Name_En')->nullable();
            $table->decimal('DepreciationRate', 8, 3)->nullable();
            $table->integer('DepComplexAccId')->nullable();
            $table->integer('ExpenseComplexAccId')->nullable();
        });

        $schema->create('dbo.gl_Assets', function (Blueprint $table): void {
            $table->integer('ID')->primary();
            $table->integer('GroupId')->nullable();
            $table->integer('SupGroupId')->nullable();
            $table->decimal('AssetAge', 10, 3)->nullable();
            $table->decimal('AssetDocValue', 18, 3)->nullable();
            $table->decimal('DepComplexValue', 18, 3)->nullable();
            $table->decimal('ExpiredValue', 8, 3)->nullable();
            $table->string('Code')->nullable();
            $table->string('Name_AR')->nullable();
            $table->string('Name_En')->nullable();
            $table->string('AssetDate')->nullable();
            $table->integer('BranchID')->nullable();
            $table->integer('PlaceId')->nullable();
            $table->integer('CustodyEmpId')->nullable();
            $table->string('Status')->nullable();
        });

        $schema->create('dbo.gl_AssetsDetails', function (Blueprint $table): void {
            $table->integer('AssetID')->nullable();
        });

        $schema->create('dbo.gl_AssetsHeader', function (Blueprint $table): void {
            $table->integer('ID')->primary();
            $table->integer('glEntryHeaderId')->nullable();
        });

        $schema->create('dbo.gl_Accounts', function (Blueprint $table): void {
            $table->integer('ID')->primary();
            $table->integer('Depreciationid')->nullable();
        });
    }

    private function seedLegacyAssets(): void
    {
        $legacy = DB::connection('foodex_legacy');

        $legacy->table('dbo.gl_AssetsGroups')->insert([
            'ID' => 1,
            'Code' => 'GROUP-1',
            'Name_AR' => 'مجموعة الأصول',
            'Name_En' => 'Asset group',
            'GlAccId' => 101,
        ]);

        $legacy->table('dbo.gl_AssetsSupGroups')->insert([
            'ID' => 1,
            'Code' => 'SUBGROUP-1',
            'Name_AR' => 'مجموعة فرعية',
            'Name_En' => 'Asset subgroup',
            'DepreciationRate' => '25.000',
            'DepComplexAccId' => 202,
            'ExpenseComplexAccId' => 303,
        ]);

        $assets = [];
        for ($id = 1; $id <= 78; $id++) {
            $isPlaceholder = $id <= 74;
            $isCapitalized = $id === 75 || $id === 76;

            $assets[] = [
                'ID' => $id,
                'GroupId' => 1,
                'SupGroupId' => 1,
                'AssetAge' => $id === 78 ? null : ($isPlaceholder || $id === 77 ? '0.000' : '4.000'),
                'AssetDocValue' => $isCapitalized ? '125.000' : '0.000',
                'DepComplexValue' => '0.000',
                'ExpiredValue' => 0,
                'Code' => sprintf('ASSET-%03d', $id),
                'Name_AR' => $id === 75 ? 'أصل تجريبي' : 'اسم الأصل',
                'Name_En' => sprintf('Sample asset %03d', $id),
                'AssetDate' => '20190804',
                'BranchID' => null,
                'PlaceId' => null,
                'CustodyEmpId' => null,
                'Status' => 'ACTIVE',
            ];
        }

        $legacy->table('dbo.gl_Assets')->insert($assets);
        $legacy->table('dbo.gl_AssetsDetails')->insert(['AssetID' => 77]);
        $legacy->table('dbo.gl_AssetsHeader')->insert(['ID' => 1, 'glEntryHeaderId' => 0]);
    }
}
