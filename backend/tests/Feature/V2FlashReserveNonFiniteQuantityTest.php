<?php

namespace Tests\Feature;

use App\Services\FlashOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class V2FlashReserveNonFiniteQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_finite_and_non_positive_quantities_fail_before_reservation_or_stock_writes(): void
    {
        $service = app(FlashOfferService::class);
        $before = $this->writeSnapshot();

        foreach ([NAN, INF, -INF, 0.0, -1.0] as $quantity) {
            try {
                $service->reserve(1, 999999, $quantity, 'customer', 'invalid-quantity');
                $this->fail('Invalid selling quantity must fail before idempotency lookup.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
                $this->assertSame('Selling quantity must be greater than zero.', $exception->getMessage());
            }

            $this->assertSame($before, $this->writeSnapshot(), 'Invalid quantity must not write reservations or financial/stock records.');
        }

        // A finite positive amount must pass the quantity guard and retain the
        // existing product-not-found behavior (without creating any records).
        try {
            $service->reserve(1, 999999, 1.0, 'customer', 'valid-finite-missing-product');
            $this->fail('An unknown offer product must remain a 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame('Flash offer product was not found.', $exception->getMessage());
        }

        $this->assertSame($before, $this->writeSnapshot());
    }

    /** @return array<string, int|float> */
    private function writeSnapshot(): array
    {
        return [
            'reservations' => DB::table('flash_reservations')->count(),
            'events' => DB::table('flash_offer_events')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'reserved_stock' => (float) DB::table('inventories')->sum('reserved_quantity'),
        ];
    }
}
