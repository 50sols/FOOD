<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional and data-preserving: existing rules retain their exact base price.
        Schema::table('b2b_price_rules', function (Blueprint $table): void {
            $table->json('quantity_price_breaks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('b2b_price_rules', function (Blueprint $table): void {
            $table->dropColumn('quantity_price_breaks');
        });
    }
};
