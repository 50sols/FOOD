<?php

namespace App\Domain\Pricing;

use Illuminate\Validation\ValidationException;

/**
 * Quantity discounts use a whole-line (all units) price, not marginal pricing.
 * Strict validation prevents gaps, unsorted breakpoints and silent price increases.
 */
final class QuantityPriceBreaks
{
    /**
     * @param array<mixed> $breaks
     * @return list<array{minimum_quantity:float,unit_price:float}>
     */
    public static function normalize(array $breaks, float $minimum, float $increment, float $basePrice): array
    {
        if (count($breaks) > 12 || ! is_finite($minimum) || $minimum <= 0
            || ! is_finite($increment) || $increment <= 0 || ! is_finite($basePrice) || $basePrice < 0) {
            self::invalid();
        }

        $lastQuantity = $minimum;
        $lastPrice = $basePrice;
        $normalized = [];

        foreach ($breaks as $break) {
            if (! is_array($break) || ! isset($break['minimum_quantity'], $break['unit_price'])
                || ! is_numeric($break['minimum_quantity']) || ! is_numeric($break['unit_price'])) {
                self::invalid();
            }

            $quantity = (float) $break['minimum_quantity'];
            $price = (float) $break['unit_price'];
            $steps = ($quantity - $minimum) / $increment;

            if (! is_finite($quantity) || ! is_finite($price)
                || $quantity <= $minimum + 0.0001 || $quantity <= $lastQuantity + 0.0001
                || $quantity > 99999999999 || $price < 0 || $price > 99999999999
                || $price > $lastPrice + 0.0001
                || abs($steps - round($steps)) > 0.0001
                || abs($quantity - round($quantity, 3)) > 0.000001
                || abs($price - round($price, 3)) > 0.000001) {
                self::invalid();
            }

            $normalized[] = [
                'minimum_quantity' => round($quantity, 3),
                'unit_price' => round($price, 3),
            ];
            $lastQuantity = $quantity;
            $lastPrice = $price;
        }

        return $normalized;
    }

    /**
     * @param array{price:float,quantity_price_breaks?:list<array{minimum_quantity:float,unit_price:float}>} $pricing
     */
    public static function priceForQuantity(array $pricing, float $quantity): float
    {
        if (! is_finite($quantity) || $quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be finite and positive.');
        }

        $price = (float) $pricing['price'];
        foreach ($pricing['quantity_price_breaks'] ?? [] as $break) {
            if ($quantity + 0.0001 < (float) $break['minimum_quantity']) {
                break;
            }
            $price = (float) $break['unit_price'];
        }

        return round($price, 3);
    }

    /** @return list<array{minimum_quantity:float,unit_price:float}> */
    public static function readBreaks(mixed $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        $value = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \DomainException('Stored quantity price breaks are invalid.');
        }

        $result = [];
        foreach ($value as $break) {
            if (! is_array($break) || ! isset($break['minimum_quantity'], $break['unit_price'])) {
                throw new \DomainException('Stored quantity price breaks are invalid.');
            }
            $result[] = [
                'minimum_quantity' => (float) $break['minimum_quantity'],
                'unit_price' => (float) $break['unit_price'],
            ];
        }

        return $result;
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages([
            'quantity_price_breaks' => [__('commercial.quantity_tiers.invalid_breaks')],
        ]);
    }
}
