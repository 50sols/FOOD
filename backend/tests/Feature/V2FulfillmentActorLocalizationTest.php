<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\FulfillmentActorPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class V2FulfillmentActorLocalizationTest extends TestCase
{
    public function test_canonical_fulfillment_actor_denials_are_localized_in_arabic_and_english(): void
    {
        $previousLocale = app()->getLocale();

        try {
            $policy = app(FulfillmentActorPolicy::class);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);

                foreach (['invalid', '', 'b2x'] as $channel) {
                    try {
                        $policy->actorForChannel($channel);
                        $this->fail('An unsupported channel was accepted.');
                    } catch (ValidationException $exception) {
                        $this->assertSame(
                            [__('fulfillment_actor_guard.channel_invalid')],
                            $exception->errors()['channel'] ?? null,
                        );
                    }
                }

                foreach ([
                    ['channel' => 'b2b', 'actor' => 'driver', 'key' => 'b2b_van_only'],
                    ['channel' => 'b2b', 'actor' => '', 'key' => 'b2b_van_only'],
                    ['channel' => 'b2c', 'actor' => 'van', 'key' => 'b2c_driver_only'],
                    ['channel' => 'b2c', 'actor' => 'unknown', 'key' => 'b2c_driver_only'],
                ] as $scenario) {
                    $order = new Order;
                    $order->channel = $scenario['channel'];

                    foreach (['order', 'manual'] as $path) {
                        try {
                            if ($path === 'manual') {
                                $policy->assertManualDispatchActor($order, $scenario['actor']);
                            } else {
                                $policy->assertOrderActor($order, $scenario['actor']);
                            }

                            $this->fail('A cross-channel executor was accepted.');
                        } catch (ValidationException $exception) {
                            $this->assertSame(
                                [__('fulfillment_actor_guard.'.$scenario['key'])],
                                $exception->errors()['assignee_type'] ?? null,
                            );
                        }
                    }
                }
            }
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function test_valid_van_and_driver_actor_contract_and_manual_path_are_preserved(): void
    {
        $policy = app(FulfillmentActorPolicy::class);
        $this->assertSame(FulfillmentActorPolicy::VAN, $policy->actorForChannel(' B2B '));
        $this->assertSame(FulfillmentActorPolicy::DRIVER, $policy->actorForChannel(' B2C '));

        foreach ([
            ['channel' => 'b2b', 'actor' => 'VAN'],
            ['channel' => 'b2c', 'actor' => 'driver'],
        ] as $scenario) {
            $order = new Order;
            $order->channel = $scenario['channel'];
            $policy->assertOrderActor($order, $scenario['actor']);
            $policy->assertManualDispatchActor($order, $scenario['actor']);
        }

        $this->addToAssertionCount(4);
    }
}
