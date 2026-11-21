<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VanNoOrderReason;
use App\Models\VanVisit;
use App\Services\VanVisitLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class V2VanVisitValidationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_visit_transitions_are_localized_and_never_mutate_visits(): void
    {
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);

                $actor = User::factory()->create(['locale' => $locale]);
                $inactiveReason = VanNoOrderReason::query()->create([
                    'code' => 'v2_inactive_'.$locale,
                    'label_ar' => 'سبب غير نشط',
                    'label_en' => 'Inactive reason',
                    'is_active' => false,
                ]);

                $cases = [
                    ['start' => 'closed', 'target' => 'started', 'field' => 'status', 'key' => 'transition_not_allowed', 'reason' => null],
                    ['start' => 'started', 'target' => 'completed_with_order', 'field' => 'order_id', 'key' => 'authoritative_order_required', 'reason' => null],
                    ['start' => 'started', 'target' => 'completed_no_order', 'field' => 'no_order_reason_id', 'key' => 'no_order_reason_required', 'reason' => null],
                    ['start' => 'started', 'target' => 'completed_no_order', 'field' => 'no_order_reason_id', 'key' => 'no_order_reason_inactive', 'reason' => (int) $inactiveReason->id],
                ];

                foreach ($cases as $index => $case) {
                    $visit = VanVisit::query()->create([
                        'actor_user_id' => $actor->id,
                        'customer_type' => 'b2b',
                        'customer_id' => 4100 + $index,
                        'status' => $case['start'],
                        'started_at' => $case['start'] === 'started' ? now() : null,
                        'closed_at' => $case['start'] === 'closed' ? now() : null,
                    ]);
                    $before = $visit->fresh()->toArray();

                    try {
                        app(VanVisitLifecycleService::class)->transition(
                            $visit,
                            $case['target'],
                            $actor,
                            noOrderReasonId: $case['reason'],
                        );
                        $this->fail('A rejected visit transition unexpectedly succeeded.');
                    } catch (ValidationException $exception) {
                        $this->assertSame(
                            [__('van_visit_guard.'.$case['key'])],
                            $exception->errors()[$case['field']] ?? null,
                        );
                    }

                    $this->assertSame($before, $visit->fresh()->toArray());
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_active_no_order_reason_still_allows_the_existing_positive_visit_transition(): void
    {
        $actor = User::factory()->create();
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 4200,
            'status' => 'started',
            'started_at' => now(),
        ]);
        $reason = VanNoOrderReason::query()->create([
            'code' => 'v2_valid_no_order_reason',
            'label_ar' => 'رفض العميل',
            'label_en' => 'Customer declined',
            'is_active' => true,
        ]);

        $completed = app(VanVisitLifecycleService::class)->transition(
            $visit,
            'completed_no_order',
            $actor,
            noOrderReasonId: (int) $reason->id,
        );

        $this->assertSame('completed_no_order', $completed->status);
        $this->assertSame((int) $reason->id, (int) $completed->no_order_reason_id);
        $this->assertNotNull($completed->completed_at);
    }
}
