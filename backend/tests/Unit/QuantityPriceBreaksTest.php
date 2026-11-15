<?php

namespace Tests\Unit;

use App\Domain\Pricing\QuantityPriceBreaks;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class QuantityPriceBreaksTest extends TestCase
{
    public function test_volume_price_applies_to_all_units_at_highest_eligible_threshold(): void
    {
        $breaks = QuantityPriceBreaks::normalize([
            ['minimum_quantity' => 10, 'unit_price' => 8.500],
            ['minimum_quantity' => 20, 'unit_price' => 7.250],
        ], 5, 5, 10.000);

        $pricing = ['price' => 10.0, 'quantity_price_breaks' => $breaks];
        $this->assertSame(10.0, QuantityPriceBreaks::priceForQuantity($pricing, 5));
        $this->assertSame(8.5, QuantityPriceBreaks::priceForQuantity($pricing, 10));
        $this->assertSame(8.5, QuantityPriceBreaks::priceForQuantity($pricing, 15));
        $this->assertSame(7.25, QuantityPriceBreaks::priceForQuantity($pricing, 20));
        $this->assertSame(7.25, QuantityPriceBreaks::priceForQuantity($pricing, 25));
        $this->assertSame(10.0, QuantityPriceBreaks::priceForQuantity(['price' => 10.0], 25));
    }

    public function test_breakpoints_reject_duplicate_unsorted_increment_incompatible_and_price_increase(): void
    {
        foreach ([
            [['minimum_quantity' => 10, 'unit_price' => 9], ['minimum_quantity' => 10, 'unit_price' => 8]],
            [['minimum_quantity' => 20, 'unit_price' => 8], ['minimum_quantity' => 10, 'unit_price' => 7]],
            [['minimum_quantity' => 11, 'unit_price' => 8]],
            [['minimum_quantity' => 5, 'unit_price' => 8]],
            [['minimum_quantity' => 10, 'unit_price' => 11]],
            [['minimum_quantity' => 10, 'unit_price' => -1]],
            [['minimum_quantity' => 10.0004, 'unit_price' => 8]],
        ] as $invalid) {
            try {
                QuantityPriceBreaks::normalize($invalid, 5, 5, 10.0);
                $this->fail('Invalid break must fail without a partial write.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('quantity_price_breaks', $exception->errors());
            }
        }
    }
}
