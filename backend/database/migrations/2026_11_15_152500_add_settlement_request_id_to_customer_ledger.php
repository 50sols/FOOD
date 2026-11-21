<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing append-only ledger entries remain unchanged (nullable).
        // Composite uniqueness lets different customers use the same UUID
        // without allowing duplicate settlements for the same customer.
        Schema::table('customer_account_ledger_entries', function (Blueprint $table): void {
            $table->uuid('settlement_request_id')->nullable();
            $table->unique(['b2b_customer_id', 'settlement_request_id'], 'customer_ledger_settlement_request_unique');
        });
    }

    public function down(): void
    {
        // DDL rollback drops captured identities; NEVER rely on this after
        // new financial settlements, use verified backup/recovery instead.
        Schema::table('customer_account_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('customer_ledger_settlement_request_unique');
            $table->dropColumn('settlement_request_id');
        });
    }
};
