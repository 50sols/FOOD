<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

final class FulfillmentActorPolicy
{
    public const VAN = 'van';

    public const DRIVER = 'driver';

    public function actorForChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'b2b' => self::VAN,
            'b2c' => self::DRIVER,
            default => throw ValidationException::withMessages([
                'channel' => [__('fulfillment_actor_guard.channel_invalid')],
            ]),
        };
    }

    public function assertOrderActor(Order $order, string $actor): void
    {
        $expected = $this->actorForChannel((string) $order->channel);
        $actual = strtolower(trim($actor));

        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'assignee_type' => [
                    __($expected === self::VAN
                        ? 'fulfillment_actor_guard.b2b_van_only'
                        : 'fulfillment_actor_guard.b2c_driver_only'),
                ],
            ]);
        }
    }

    public function assertManualDispatchActor(Order $order, string $actor): void
    {
        // No manual exceptions to the canonical order-channel executor.
        $this->assertOrderActor($order, $actor);
    }
}
