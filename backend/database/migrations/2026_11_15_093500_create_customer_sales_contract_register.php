<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_sales_contracts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('quotation_id')->unique()->constrained('sales_quotations')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('channel', 3);
            $table->unsignedBigInteger('customer_id');
            $table->string('customer_name_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('currency', 3);
            $table->decimal('grand_total', 14, 3);
            $table->json('pricing_snapshot');
            $table->string('evidence_path', 255);
            $table->char('evidence_sha256', 64);
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status', 'created_at'], 'customer_contracts_store_state');
            $table->index(['channel', 'customer_id'], 'customer_contracts_customer_channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sales_contracts');
    }
};
