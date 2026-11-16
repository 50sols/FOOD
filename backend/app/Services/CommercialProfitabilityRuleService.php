<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommercialProfitabilityRuleService
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly CommercialFeatureFlags $flags,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, \stdClass> */
    public function revisions(int $storeId, int $productId): Collection
    {
        return DB::table('commercial_profitability_rule_revisions')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->orderByDesc('revision')
            ->get();
    }

    public function canPropose(User $actor, int $storeId): bool
    {
        return $this->flags->commercialRulesEnabled()
            && in_array($storeId, $this->scope->allowedStoreIds($actor, 'catalog.manage', 'b2b'), true);
    }

    public function canReview(User $actor, int $storeId): bool
    {
        return $this->canPropose($actor, $storeId)
            && in_array($storeId, $this->scope->allowedStoreIds($actor, 'orders.approve', 'b2b'), true)
            && in_array($storeId, $this->scope->allowedStoreIds($actor, 'finance.approve', 'b2b'), true);
    }

    public function propose(User $actor, int $storeId, int $productId, Request $request): void
    {
        abort_unless($this->canPropose($actor, $storeId), 403);
        $this->assertProduct($storeId, $productId);
        $this->assertAllowedFields($request, [
            'profitability_action', 'request_id', 'minimum_margin_percent', 'effective_from', 'effective_until',
        ]);

        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'minimum_margin_percent' => ['required', 'regex:/^(?:100(?:\\.0{1,2})?|(?:[0-9]|[1-9][0-9])(?:\\.[0-9]{1,2})?)$/'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['required', 'date_format:Y-m-d', 'after:effective_from'],
        ]);

        // Integer hundredths of a percent; never use float conversion for money policy.
        [$whole, $fraction] = array_pad(explode('.', (string) $data['minimum_margin_percent'], 2), 2, '');
        $basisPoints = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        $canonical = [
            'store_id' => $storeId,
            'product_id' => $productId,
            'minimum_margin_basis_points' => $basisPoints,
            'effective_from' => $data['effective_from'],
            'effective_until' => $data['effective_until'],
        ];
        $digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($actor, $request, $storeId, $productId, $data, $canonical, $digest): void {
            // Lock a stable parent row before reading revisions. A first revision has
            // no child row to lock, so concurrent proposals must serialize here.
            // This also serializes request-id collisions across products in a store.
            abort_if(DB::table('stores')->where('id', $storeId)
                ->lockForUpdate()->first(['id']) === null, 404);

            $existing = DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $storeId)
                ->where('request_id', $data['request_id'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((int) $existing->proposed_by !== (int) $actor->getKey()
                    || (int) $existing->product_id !== $productId
                    || ! hash_equals((string) $existing->content_sha256, $digest)) {
                    throw ValidationException::withMessages([
                        'request_id' => [__('commercial.profitability.idempotency_conflict')],
                    ]);
                }

                return; // Same request, same immutable revision: no duplicate audit/write.
            }

            $latest = DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $storeId)
                ->where('product_id', $productId)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();

            $revision = ($latest === null ? 0 : (int) $latest->revision) + 1;
            $id = DB::table('commercial_profitability_rule_revisions')->insertGetId([
                ...$canonical,
                'revision' => $revision,
                'status' => 'DRAFT',
                'request_id' => $data['request_id'],
                'content_sha256' => $digest,
                'proposed_by' => $actor->getKey(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('commercial.profitability_rule.proposed', $actor, null, null, [
                ...$canonical,
                'id' => $id,
                'revision' => $revision,
                'status' => 'DRAFT',
                'content_sha256' => $digest,
            ], $request);
        }, 3);
    }

    public function review(User $actor, int $storeId, int $productId, Request $request): void
    {
        abort_unless($this->canReview($actor, $storeId), 403);
        $this->assertProduct($storeId, $productId);
        $this->assertAllowedFields($request, [
            'profitability_action', 'revision_id', 'decision',
        ]);

        $data = $request->validate([
            'revision_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:review,reject'],
        ]);

        $status = $data['decision'] === 'review' ? 'REVIEWED_INACTIVE' : 'REJECTED';

        DB::transaction(function () use ($actor, $storeId, $productId, $request, $data, $status): void {
            // Two separate revision rows can otherwise be reviewed
            // concurrently into incompatible floor policies for the same
            // product and dates. Serialize on the stable parent store row,
            // in the same lock order already used by propose().
            abort_if(DB::table('stores')->where('id', $storeId)
                ->lockForUpdate()->first(['id']) === null, 404);

            $query = DB::table('commercial_profitability_rule_revisions')
                ->where('id', (int) $data['revision_id'])
                ->where('store_id', $storeId)
                ->where('product_id', $productId);
            $rule = $query->lockForUpdate()->first();
            abort_if($rule === null, 404);
            abort_if((int) $rule->proposed_by === (int) $actor->getKey(), 403);

            if ($rule->status === $status && (int) $rule->reviewed_by === (int) $actor->getKey()) {
                return; // Idempotent replay of the original reviewer decision.
            }

            if ($rule->status !== 'DRAFT') {
                throw ValidationException::withMessages([
                    'revision_id' => [__('commercial.profitability.already_reviewed')],
                ]);
            }

            if ($status === 'REVIEWED_INACTIVE') {
                // Date ranges are inclusive and stored as canonical SQL DATE.
                // No independent reviewer may sign conflicting future rule
                // windows. Reject drafts only at review; a conflicting draft
                // remains available for explicit rejection/audited correction.
                $overlaps = DB::table('commercial_profitability_rule_revisions')
                    ->where('store_id', $storeId)
                    ->where('product_id', $productId)
                    ->where('id', '!=', (int) $rule->id)
                    ->whereIn('status', ['REVIEWED_INACTIVE', 'ACTIVE'])
                    ->where('effective_from', '<=', $rule->effective_until)
                    ->where('effective_until', '>=', $rule->effective_from)
                    ->exists();
                if ($overlaps) {
                    throw ValidationException::withMessages([
                        'revision_id' => [__('commercial.profitability.overlapping_window')],
                    ]);
                }
            }

            // A commercial reviewer verifies only the human rule revision.
            // REVIEWED_INACTIVE is deliberately NOT ACTIVE: P110 legal-title
            // cost and P129 independent below-floor credit/HOLD are unfinished.
            $query->update([
                'status' => $status,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('commercial.profitability_rule.reviewed', $actor, null, [
                'store_id' => $storeId,
                'id' => (int) $rule->id,
                'status' => 'DRAFT',
            ], [
                'store_id' => $storeId,
                'id' => (int) $rule->id,
                'status' => $status,
                'revision' => (int) $rule->revision,
                'content_sha256' => (string) $rule->content_sha256,
            ], $request);
        }, 3);
    }

    private function assertProduct(int $storeId, int $productId): void
    {
        abort_unless(DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('store_products.store_id', $storeId)
            ->where('store_products.product_id', $productId)
            ->where('catalogs.channel', 'b2b')
            ->exists(), 404);
    }

    /** @param list<string> $fields */
    private function assertAllowedFields(Request $request, array $fields): void
    {
        $allowed = [...$fields, '_token', '_method', 'store_id', 'product', 'support_access'];
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, [
                __('commercial.profitability.unsupported_field'),
            ]));
        }
    }
}
