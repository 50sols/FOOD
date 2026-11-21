<?php

namespace Tests\Feature;

use App\Models\GeographyNode;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\VanRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class V2VanAssignmentValidationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_and_overlap_validation_use_actor_ar_en_without_writes(): void
    {
        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'v2-3702-country',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'country_code' => 'EGT',
            'is_active' => true,
        ]);

        $messages = [
            'ar' => [
                'date' => 'يجب أن يكون تاريخ انتهاء الإسناد بعد تاريخ بدايته.',
                'type' => 'يجب أن يكون نوع الإسناد أساسيًا أو احتياطيًا.',
                'status' => 'يجب أن تكون حالة الإسناد نشطة أو منتهية.',
                'van' => 'يوجد إسناد أساسي نشط آخر للفان يتداخل مع هذه الفترة.',
                'territory' => 'توجد منطقة خدمة مرتبطة بإسناد أساسي نشط آخر يتداخل مع هذه الفترة.',
            ],
            'en' => [
                'date' => 'Effective-until must be after effective-from.',
                'type' => 'Assignment type must be primary or backup.',
                'status' => 'Assignment status must be active or ended.',
                'van' => 'Van already has an overlapping active primary assignment.',
                'territory' => 'Territory already has an overlapping active primary Van assignment.',
            ],
        ];
        $service = app(VanRegistryService::class);

        foreach ($messages as $locale => $text) {
            $actor = User::factory()->create(['locale' => $locale]);
            $van = $service->createVan(['code' => 'V2-3702-'.strtoupper($locale)]);
            $otherVan = $service->createVan(['code' => 'V2-3702-OTHER-'.strtoupper($locale)]);
            $territoryKey = 'v2-3702-territory-'.$locale;
            ServiceTerritory::query()->create([
                'code' => $territoryKey,
                'name_ar' => 'منطقة اختبار',
                'name_en' => 'Test territory',
                'country_node_id' => $country->id,
                'status' => 'active',
                'effective_from' => '2025-01-01 00:00:00',
            ]);

            $this->assertRejectedWithoutWrites(
                fn () => $service->assign($actor, $van, [
                    'effective_from' => '2026-02-01T00:00:00Z',
                    'effective_until' => '2026-01-01T00:00:00Z',
                ]),
                'effective_until',
                $text['date']
            );
            $this->assertRejectedWithoutWrites(
                fn () => $service->assign($actor, $van, [
                    'effective_from' => '2026-01-01T00:00:00Z',
                    'assignment_type' => 'unknown',
                ]),
                'assignment_type',
                $text['type']
            );

            $existing = $service->assign($actor, $van, [
                'assignment_type' => 'primary',
                'territory_key' => $territoryKey,
                'effective_from' => '2026-01-01T00:00:00Z',
                'effective_until' => '2026-12-01T00:00:00Z',
            ]);

            $this->assertRejectedWithoutWrites(
                fn () => $service->assign($actor, $van, [
                    'assignment_type' => 'primary',
                    'effective_from' => '2026-02-01T00:00:00Z',
                ]),
                'assignment',
                $text['van']
            );
            $this->assertRejectedWithoutWrites(
                fn () => $service->assign($actor, $otherVan, [
                    'assignment_type' => 'primary',
                    'territory_key' => $territoryKey,
                    'effective_from' => '2026-02-01T00:00:00Z',
                ]),
                'territory_key',
                $text['territory']
            );
            $this->assertRejectedWithoutWrites(
                fn () => $service->updateAssignment($actor, $existing, [
                    'effective_from' => '2026-02-01T00:00:00Z',
                    'effective_until' => '2026-01-01T00:00:00Z',
                ]),
                'effective_until',
                $text['date']
            );
            $this->assertRejectedWithoutWrites(
                fn () => $service->updateAssignment($actor, $existing, [
                    'effective_from' => '2026-01-01T00:00:00Z',
                    'assignment_type' => 'invalid',
                ]),
                'assignment_type',
                $text['type']
            );
            $this->assertRejectedWithoutWrites(
                fn () => $service->updateAssignment($actor, $existing, [
                    'effective_from' => '2026-01-01T00:00:00Z',
                    'status' => 'paused',
                ]),
                'status',
                $text['status']
            );
            $this->assertDatabaseHas('van_assignments', [
                'id' => $existing->id,
                'assignment_type' => 'primary',
                'status' => 'active',
                'territory_key' => $territoryKey,
            ]);
        }
    }

    public function test_unsupported_actor_locale_falls_back_to_arabic_without_writes(): void
    {
        $actor = new User(['locale' => 'unsupported-locale']);
        $van = app(VanRegistryService::class)->createVan(['code' => 'V2-3702-FALLBACK']);

        $this->assertRejectedWithoutWrites(
            fn () => app(VanRegistryService::class)->assign($actor, $van, [
                'effective_from' => '2026-02-01T00:00:00Z',
                'effective_until' => '2026-01-01T00:00:00Z',
            ]),
            'effective_until',
            'يجب أن يكون تاريخ انتهاء الإسناد بعد تاريخ بدايته.'
        );
    }

    private function assertRejectedWithoutWrites(callable $action, string $field, string $expected): void
    {
        $assignments = VanAssignment::query()->count();
        $audits = DB::table('audit_logs')->count();

        try {
            $action();
            $this->fail('An invalid Van assignment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([$expected], $exception->errors()[$field] ?? []);
        }

        $this->assertSame($assignments, VanAssignment::query()->count());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }
}
