<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2B2bCheckoutOptionsMissingStoreLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_wholesale_checkout_store_is_bilingual_and_never_writes(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $this->getJson('/api/v1/b2b/checkout/options')->assertUnauthorized();

        $user = User::factory()->create([
            'is_active' => true,
            'locale' => 'ar',
        ]);
        Sanctum::actingAs($user);

        $before = [];
        foreach (['carts', 'orders', 'invoices', 'payments', 'stock_movements', 'audit_logs', 'settings'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach ([
            ['ar', 'en-US', 'يجب تحديد متجر للجملة B2B.'],
            ['en', 'ar-KW', 'A B2B store is required.'],
            ['fr', 'ar-KW', 'يجب تحديد متجر للجملة B2B.'],
            ['fr', 'en-US', 'A B2B store is required.'],
        ] as [$actorLocale, $headerLocale, $message]) {
            $user->forceFill(['locale' => $actorLocale])->save();

            foreach ([
                '/api/v1/b2b/checkout/options',
                '/api/v1/b2b/checkout/options?store_id=0',
            ] as $url) {
                $this->withHeader('Accept-Language', $headerLocale)
                    ->getJson($url)
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.store_id.0', $message);

                foreach ($before as $table => $count) {
                    $this->assertSame(
                        $count,
                        DB::table($table)->count(),
                        $table.' must remain unchanged after missing-store rejection',
                    );
                }
            }
        }
    }
}
