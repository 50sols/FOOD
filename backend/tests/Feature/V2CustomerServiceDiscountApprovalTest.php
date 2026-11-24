<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\OrderCustomerServiceDiscountApprovalService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2CustomerServiceDiscountApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_assigned_agent_request_does_not_mutate_prices_and_independent_accounting_review_applies_once(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $finance = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);
        $key = (string) Str::uuid();
        $submitUrl = route('admin.operations.orders.customer-service-discount', ['order' => $orderId]);

        $this->actingAs($agent)->post($submitUrl, [
            'percentage' => 12.5,
            'reason' => 'Service recovery adjustment',
            'idempotency_key' => $key,
        ])->assertRedirect();

        $request = DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame($agent->getKey(), (int) $request->requested_by_user_id);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'discount_total' => 1, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.requested', 'user_id' => $agent->getKey(),
        ]);

        $this->actingAs($agent)->post($submitUrl, [
            'percentage' => 12.5,
            'reason' => 'Service recovery adjustment',
            'idempotency_key' => $key,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->count());

        // Finance approvers are scoped by finance.view/manage rather than broad orders.view.

        $reviewUrl = $this->reviewUrl($orderId, (int) $request->id);
        $this->actingAs($finance)->post($reviewUrl, ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by_user_id' => $finance->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 7.875]);
        $this->assertDatabaseHas('order_customer_service_discounts', [
            'order_id' => $orderId, 'idempotency_key' => $key,
            'applied_by_user_id' => $finance->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.approved', 'user_id' => $finance->getKey(),
        ]);
        $this->actingAs($finance)->post($reviewUrl, ['decision' => 'approve'])->assertStatus(409);
        $this->assertSame(1, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_direct_service_review_rechecks_revoked_finance_entitlement(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $finance = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $this->actingAs($manager)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $orderId]),
            [
                'percentage' => 12.5,
                'reason' => 'Independently review requested price correction',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->value('id');

        $scope = app(OrganizationHierarchyService::class)->scopeForStore(
            app(WholesalePrincipal::class)->storeId(),
        );
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            false, null, $finance,
        );

        try {
            app(OrderCustomerServiceDiscountApprovalService::class)->review(
                Order::query()->findOrFail($orderId),
                $finance,
                $requestId,
                true,
                null,
                Request::create('/admin/operations/orders', 'POST'),
            );
            $this->fail('Direct service approval must reject revoked Finance scope.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')
            ->where('order_id', $orderId)->count());
    }

    public function test_direct_discount_request_service_rejects_unassigned_or_inactive_actor(): void
    {
        $assigned = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $unassigned = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $this->assignAgent($orderId, $assigned);
        $service = app(OrderCustomerServiceDiscountApprovalService::class);
        $order = Order::query()->findOrFail($orderId);
        $request = Request::create('/admin/operations/orders', 'POST');

        try {
            $service->request($order, $unassigned, 15, 'Direct service bypass attempt', (string) Str::uuid(), $request);
            $this->fail('An unassigned agent must never submit through the service.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $manager->forceFill(['is_active' => false])->save();
        try {
            $service->request($order, $manager, 15, 'Inactive actor bypass attempt', (string) Str::uuid(), $request);
            $this->fail('An inactive manager must never submit through the service.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseMissing('order_customer_service_discount_requests', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.requested',
            'user_id' => $unassigned->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
    }

    public function test_direct_discount_request_rejects_invalid_percentage_without_audit_or_queue(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $order = Order::query()->findOrFail($orderId);
        $service = app(OrderCustomerServiceDiscountApprovalService::class);
        $request = Request::create('/admin/operations/orders', 'POST');

        foreach ([-1.0, 101.0, INF, NAN] as $percentage) {
            try {
                $service->request($order, $manager, $percentage, 'Invalid direct service percentage', (string) Str::uuid(), $request);
                $this->fail('Invalid financial percentage must not enter the Finance queue.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('percentage', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('order_customer_service_discount_requests', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.requested',
            'user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
    }

    public function test_direct_discount_request_rejects_excess_fractional_precision_without_financial_side_effects(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $order = Order::query()->findOrFail($orderId);
        $service = app(OrderCustomerServiceDiscountApprovalService::class);
        $request = Request::create('/admin/operations/orders', 'POST');

        foreach ([12.3456, 0.0001, 99.9999] as $invalidPercentage) {
            try {
                $service->request(
                    $order, $manager, $invalidPercentage,
                    'Independent accounting approval', (string) Str::uuid(), $request,
                );
                $this->fail('Direct service must reject percentage precision beyond three decimal places.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('percentage', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('order_customer_service_discount_requests', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.requested',
            'user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'discount_total' => 1, 'grand_total' => 9]);

        $this->assertTrue($service->request(
            $order, $manager, 12.345,
            'Independent accounting approval', (string) Str::uuid(), $request,
        ));
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'order_id' => $orderId, 'percentage' => 12.345, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'discount_total' => 1, 'grand_total' => 9]);
    }

    public function test_direct_discount_request_normalizes_and_validates_finance_rationale(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $order = Order::query()->findOrFail($orderId);
        $service = app(OrderCustomerServiceDiscountApprovalService::class);
        $request = Request::create('/admin/operations/orders', 'POST');

        foreach (['', '   ', '  no  ', str_repeat('X', 501)] as $invalidReason) {
            try {
                $service->request($order, $manager, 15, $invalidReason, (string) Str::uuid(), $request);
                $this->fail('Direct service must reject an invalid Accounting rationale.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('order_customer_service_discount_requests', ['order_id' => $orderId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.requested',
            'user_id' => $manager->getKey(),
        ]);

        $this->assertTrue($service->request(
            $order, $manager, 15, '  Valid Finance recovery reason  ', (string) Str::uuid(), $request,
        ));
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'order_id' => $orderId,
            'status' => 'pending',
            'reason' => 'Valid Finance recovery reason',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'discount_total' => 1, 'grand_total' => 9]);
    }

    public function test_direct_discount_request_rejects_non_uuid_idempotency_keys_without_financial_side_effects(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $order = Order::query()->findOrFail($orderId);
        $service = app(OrderCustomerServiceDiscountApprovalService::class);
        $request = Request::create('/admin/operations/orders', 'POST');

        foreach (['', 'not-a-uuid', str_repeat('a', 120)] as $invalidKey) {
            try {
                $service->request($order, $manager, 15, 'Authorized Finance review', $invalidKey, $request);
                $this->fail('Direct service must reject invalid idempotency keys.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
        }

        $this->assertDatabaseMissing('order_customer_service_discount_requests', [
            'order_id' => $orderId,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.requested',
            'user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);

        $key = (string) Str::uuid();
        $this->assertTrue($service->request(
            $order, $manager, 15, 'Authorized Finance review', $key, $request,
        ));
        $this->assertFalse($service->request(
            $order, $manager, 15, 'Authorized Finance review', $key, $request,
        ));
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'order_id' => $orderId, 'idempotency_key' => $key, 'status' => 'pending',
        ]);
        $this->assertSame(1, DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->count());
    }

    public function test_request_idempotency_key_cannot_mask_changed_discount_or_requester(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $otherAgent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);
        $key = (string) Str::uuid();
        $url = route('admin.operations.orders.customer-service-discount', ['order' => $orderId]);

        $this->actingAs($agent)->post($url, [
            'percentage' => 12.5,
            'reason' => 'Original recovery request',
            'idempotency_key' => $key,
        ])->assertRedirect();

        foreach ([
            ['percentage' => 15, 'reason' => 'Original recovery request'],
            ['percentage' => 12.5, 'reason' => 'Altered financial rationale'],
        ] as $conflicting) {
            $this->actingAs($agent)->post($url, $conflicting + [
                'idempotency_key' => $key,
            ])->assertSessionHasErrors('idempotency_key');
        }

        // An unrelated agent remains denied by assignment-based HTTP scope.
        $this->actingAs($otherAgent)->post($url, [
            'percentage' => 12.5,
            'reason' => 'Original recovery request',
            'idempotency_key' => $key,
        ])->assertStatus(403);

        // A separately authorized CS manager CAN submit a fresh request for
        // this store, but cannot claim another actor's idempotency key.
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $this->actingAs($manager)->post($url, [
            'percentage' => 12.5,
            'reason' => 'Original recovery request',
            'idempotency_key' => $key,
        ])->assertSessionHasErrors('idempotency_key');

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'order_id' => $orderId,
            'requested_by_user_id' => $agent->getKey(),
            'idempotency_key' => $key,
            'percentage' => 12.5,
            'reason' => 'Original recovery request',
            'status' => 'pending',
        ]);
        $this->assertSame(1, DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'discount_total' => 1, 'grand_total' => 9]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'order.customer_service_discount.requested')
            ->where('user_id', $agent->getKey())->count());
    }

    public function test_discount_request_rejects_whitespace_padded_rationale_before_audit_or_finance_queue(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $url = route('admin.operations.orders.customer-service-discount', ['order' => $orderId]);

        foreach (['     ', '    no   '] as $invalidReason) {
            $this->actingAs($manager)->post($url, [
                'percentage' => 15,
                'reason' => $invalidReason,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasErrors('reason');
        }

        $this->assertDatabaseMissing('order_customer_service_discount_requests', [
            'order_id' => $orderId,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.requested',
            'user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);

        // A valid padded rationale is accepted only after normalization;
        // both the queue and the audit event must contain the same text.
        $this->actingAs($manager)->post($url, [
            'percentage' => 15,
            'reason' => '  Authorized customer recovery  ',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'order_id' => $orderId,
            'reason' => 'Authorized customer recovery',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);
    }

    public function test_unprivileged_reviewer_cannot_approve_and_self_approval_is_forbidden(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $finance = $this->roleUser('FINANCE');
        $otherAgent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);

        $this->actingAs($agent)->post(route('admin.operations.orders.customer-service-discount', ['order' => $orderId]), [
            'percentage' => 15,
            'reason' => 'Requires independent authorization',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->value('id');
        $url = $this->reviewUrl($orderId, $requestId);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $otherAgent->forceFill(['locale' => $locale])->save();
            $this->actingAs($otherAgent)->post($url, ['decision' => 'approve'])->assertNotFound();
        }

        // A Finance reviewer must not approve a request recorded under their own ID.
        // Customer Service managers receive a tenant-safe 404, not Finance approval data.
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $this->actingAs($manager)->post($url, ['decision' => 'approve'])->assertNotFound();
        DB::table('order_customer_service_discount_requests')->where('id', $requestId)
            ->update(['requested_by_user_id' => $finance->getKey()]);
        $this->actingAs($finance)->post($url, ['decision' => 'approve'])->assertForbidden();
        $this->assertDatabaseHas('order_customer_service_discount_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_rejection_is_audited_without_price_change_and_replay_is_denied(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $finance = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);

        $this->actingAs($agent)->post(route('admin.operations.orders.customer-service-discount', ['order' => $orderId]), [
            'percentage' => 25,
            'reason' => 'Unverified customer concession',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->value('id');
        $url = $this->reviewUrl($orderId, $requestId);
        foreach (['', '     ', '   no  '] as $invalidReason) {
            $this->actingAs($finance)->post($url, [
                'decision' => 'reject', 'review_reason' => $invalidReason,
            ])->assertSessionHasErrors('review_reason');
            $this->assertDatabaseHas('order_customer_service_discount_requests', [
                'id' => $requestId, 'status' => 'pending',
            ]);
            $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        }
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.rejected',
            'user_id' => $finance->getKey(),
        ]);
        $this->actingAs($finance)->post($url, [
            'decision' => 'reject', 'review_reason' => '  Pricing not justified  ',
        ])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'rejected',
            'reviewed_by_user_id' => $finance->getKey(),
            'review_reason' => 'Pricing not justified',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.rejected', 'user_id' => $finance->getKey(),
        ]);
        $this->actingAs($finance)->post($url, ['decision' => 'approve'])->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_direct_finance_rejection_requires_auditable_normalized_reason(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $finance = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $service = app(OrderCustomerServiceDiscountApprovalService::class);
        $order = Order::query()->findOrFail($orderId);
        $request = Request::create('/admin/operations/orders', 'POST');

        $this->assertTrue($service->request(
            $order, $manager, 20, 'Accounting review requested', (string) Str::uuid(), $request,
        ));
        $requestId = (int) DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->value('id');

        foreach ([null, '', '    ', '  no  ', str_repeat('A', 501)] as $invalidReason) {
            try {
                $service->review($order, $finance, $requestId, false, $invalidReason, $request);
                $this->fail('A direct Finance rejection must provide a valid reason.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('review_reason', $exception->errors());
            }
            $this->assertDatabaseHas('order_customer_service_discount_requests', [
                'id' => $requestId, 'status' => 'pending',
            ]);
        }
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.rejected',
            'user_id' => $finance->getKey(),
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);

        $service->review($order, $finance, $requestId, false, '  Insufficient accounting support  ', $request);
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId,
            'status' => 'rejected',
            'reviewed_by_user_id' => $finance->getKey(),
            'review_reason' => 'Insufficient accounting support',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.rejected',
            'user_id' => $finance->getKey(),
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);
    }

    public function test_stale_dispatched_or_completed_request_cannot_change_order_when_reviewed(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $finance = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);

        $this->actingAs($agent)->post(route('admin.operations.orders.customer-service-discount', ['order' => $orderId]), [
            'percentage' => 10,
            'reason' => 'Discount before fulfillment change',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->value('id');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'confirmed']);

        $this->actingAs($finance)->post($this->reviewUrl($orderId, $requestId), [
            'decision' => 'approve',
        ])->assertSessionHasErrors('percentage');
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_customer_service_manager_can_request_large_discount_but_cannot_apply_or_approve(): void
    {
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $accountant = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $key = (string) Str::uuid();
        $this->actingAs($manager)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $orderId]),
            ['percentage' => 80, 'reason' => 'Accounting decides the discretionary concession', 'idempotency_key' => $key],
        )->assertRedirect();

        $request = DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9, 'discount_total' => 1]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());

        $reviewUrl = $this->reviewUrl($orderId, (int) $request->id);
        $this->actingAs($manager)->post($reviewUrl, ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($this->roleUser('SUPER_ADMIN'))->post($reviewUrl, ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($accountant)->post($reviewUrl, ['decision' => 'approve'])->assertRedirect();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $request->id, 'status' => 'approved',
            'reviewed_by_user_id' => $accountant->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 1.8]);
    }

    public function test_accounting_approval_does_not_waive_protection_against_full_write_off(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $accountant = $this->roleUser('FINANCE');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);
        $this->actingAs($agent)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $orderId]),
            ['percentage' => 100, 'reason' => 'Requested complete write-off', 'idempotency_key' => (string) Str::uuid()],
        )->assertRedirect();

        $requestId = (int) DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->value('id');
        $this->actingAs($accountant)->post(
            $this->reviewUrl($orderId, $requestId), ['decision' => 'approve'],
        )->assertSessionHasErrors('percentage');

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    private function roleUser(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());
        if ($code === 'FINANCE') {
            $this->enableFinanceForStore($user, app(WholesalePrincipal::class)->storeId());
        }

        return $user->fresh();
    }

    private function enableFinanceForStore(User $actor, int $storeId): void
    {
        $store = Store::query()->findOrFail($storeId);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $modules = app(ModuleRegistry::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            $modules->manifest(FinanceFoundationService::MODULE)->version,
        );
        if (! $modules->isEnabled(FinanceFoundationService::MODULE)) {
            app(ModuleLifecycleManager::class)->transition(
                FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor,
            );
            app(ModuleLifecycleManager::class)->transition(
                FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor,
            );
        }
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($storeId);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read'], $actor,
        );
    }

    private function order(): int
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'T087R04 Discount Review Customer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'T087R04-'.Str::upper(Str::random(8)),
            'channel' => 'b2b', 'status' => 'pending',
            'currency' => 'KWD', 'subtotal' => 10,
            'discount_total' => 1, 'delivery_total' => 0,
            'tax_total' => 0, 'grand_total' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignAgent(int $orderId, User $agent): void
    {
        DB::table('order_customer_service_assignments')->insert([
            'order_id' => $orderId,
            'assigned_user_id' => $agent->getKey(),
            'assigned_at' => now(),
            'unassigned_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reviewUrl(int $orderId, int $requestId): string
    {
        return route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);
    }
}
