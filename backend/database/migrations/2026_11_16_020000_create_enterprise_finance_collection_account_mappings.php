<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enterprise_finance_collection_account_mappings')) {
            return;
        }

        Schema::create('enterprise_finance_collection_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_account_id')->constrained('collection_accounts')->restrictOnDelete();
            $table->foreignId('finance_account_id')->constrained('enterprise_finance_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->unsignedInteger('revision');
            $table->string('status', 20);
            $table->uuid('request_id')->unique('efcam_request_uq');
            $table->char('content_sha256', 64);
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['collection_account_id', 'revision'], 'efcam_revision_uq');
            $table->index(['legal_entity_id', 'store_id', 'status'], 'efcam_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_collection_account_mappings');
    }
};
