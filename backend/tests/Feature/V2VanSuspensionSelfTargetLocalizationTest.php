<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2VanSuspensionSelfTargetLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_transfer_target_is_bilingual_422_without_van_assignment_or_audit_mutation(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        foreach ([
            'ar' => 'لا يمكن اختيار الفان نفسه كوجهة لنقل الحمولة أو استرجاعها.',
            'en' => 'Transfer target must be a different Van.',
        ] as $locale => $expectedMessage) {
            $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
            $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

            $van = Van::query()->create([
                'public_id' => (string) Str::uuid(),
                'code' => 'V2-3711-SELF-'.strtoupper($locale),
                'status' => 'active',
            ]);

            $assignmentCount = VanAssignment::query()->count();
            $auditCount = DB::table('audit_logs')->count();
            Sanctum::actingAs($actor);

            $this->postJson('/api/v1/admin/field-operations/vans/'.$van->id.'/suspend', [
                'transfer_target_van_id' => $van->id,
                'reason' => 'self-target regression',
            ])->assertStatus(422)->assertJsonPath('message', $expectedMessage);

            $this->assertDatabaseHas('vans', ['id' => $van->id, 'status' => 'active']);
            $this->assertSame($assignmentCount, VanAssignment::query()->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
        }
    }

    public function test_non_privileged_actor_is_denied_before_self_target_validation(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'V2-3711-DENIED',
            'status' => 'active',
        ]);
        $unauthorized = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $auditCount = DB::table('audit_logs')->count();

        Sanctum::actingAs($unauthorized);
        $this->postJson('/api/v1/admin/field-operations/vans/'.$van->id.'/suspend', [
            'transfer_target_van_id' => $van->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('vans', ['id' => $van->id, 'status' => 'active']);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }
}
