<?php

namespace Tests\Feature;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\EnterpriseActionCenter;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArchivedActionCenterQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_active_global_role_cannot_reveal_pending_enterprise_approval(): void
    {
        $maker = User::factory()->create(['is_active' => true]);
        $approver = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $approver->roles()->attach($role);
        $request = $this->workflow($maker, 'Global confidential approval', 'organization');
        $center = app(EnterpriseActionCenter::class);
        $auditBefore = DB::table('audit_logs')->count();

        $this->assertSame([$request->id], $this->visibleIds($center, $approver));

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));
        $this->assertSame([], $this->visibleIds($center, $approver));
        $this->assertSame([], $this->visibleIds($center, $approver->fresh()));
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('enterprise_workflow_requests', [
            'id' => $request->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_archived_active_retail_role_cannot_reveal_scoped_store_approvals(): void
    {
        $maker = User::factory()->create(['is_active' => true]);
        $approver = User::factory()->create(['is_active' => true]);
        $storeA = $this->retailStore('ACTION-ARCHIVE-A');
        $storeB = $this->retailStore('ACTION-ARCHIVE-B');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $approver->id,
            'store_id' => $storeA->id,
            'role_id' => $role->id,
        ]);
        $own = $this->workflow($maker, 'Assigned confidential approval', 'store', $storeA->id);
        $foreign = $this->workflow($maker, 'Foreign confidential approval', 'store', $storeB->id);
        $center = app(EnterpriseActionCenter::class);

        $this->assertSame([$own->id], $this->visibleIds($center, $approver));
        $this->assertDatabaseHas('enterprise_workflow_requests', ['id' => $foreign->id, 'status' => 'PENDING']);

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertSame([], $this->visibleIds($center, $approver));
        $this->assertSame([], $this->visibleIds($center, $approver->fresh()));
        $this->assertDatabaseHas('enterprise_workflow_requests', ['id' => $own->id, 'status' => 'PENDING']);
    }

    public function test_separate_active_global_approval_role_remains_visible_after_archiving_other_role(): void
    {
        $maker = User::factory()->create(['is_active' => true]);
        $approver = User::factory()->create(['is_active' => true]);
        $activeRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $historicalRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $approver->roles()->attach([$activeRole->id, $historicalRole->id]);
        $request = $this->workflow($maker, 'Retained authorized approval', 'organization');
        $center = app(EnterpriseActionCenter::class);

        $this->assertSame([$request->id], $this->visibleIds($center, $approver));
        DB::table('roles')->where('id', $historicalRole->id)->update(['archived_at' => now()]);
        $this->assertSame([$request->id], $this->visibleIds($center, $approver));
    }

    /** @return list<int> */
    private function visibleIds(EnterpriseActionCenter $center, User $user): array
    {
        return array_map(
            static fn (array $item): int => (int) $item['workflow_request_id'],
            $center->page($user, '', 'all', 1, '/admin/action-center')['actions']->items(),
        );
    }

    private function workflow(User $maker, string $label, string $scopeType, ?int $scopeId = null): EnterpriseWorkflowRequest
    {
        return app(WorkflowEngine::class)->request(
            new WorkflowDefinition('order_exception', 1, [
                new WorkflowStepDefinition('operations_approval', 'orders.approve'),
            ]),
            'order',
            'ARCHIVED-QUEUE-'.str_replace(' ', '-', strtoupper($label)),
            $maker,
            $scopeType,
            $scopeId,
            'archived-queue-'.str_replace(' ', '-', strtolower($label)),
            ['action_title' => $label, 'record_label' => 'Sensitive record'],
        );
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
