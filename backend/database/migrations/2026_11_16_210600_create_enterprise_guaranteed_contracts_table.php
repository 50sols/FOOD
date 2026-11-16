<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_guaranteed_contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('store_id');
            $table->string('instrument_type', 32);
            $table->string('direction', 16)->nullable();
            $table->string('reference', 120);
            $table->string('underlying_reference', 120);
            $table->string('issuer_name', 190)->nullable();
            $table->string('applicant_name', 190);
            $table->string('beneficiary_name', 190);
            $table->string('security_description', 500)->nullable();
            $table->string('currency_code', 3);
            $table->decimal('face_amount', 18, 3);
            $table->date('effective_date');
            $table->date('expiry_date');
            $table->string('status', 24)->default('draft');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['legal_entity_id', 'idempotency_key'], 'egc_legal_idempotent');
            $table->unique(['legal_entity_id', 'instrument_type', 'reference'], 'egc_legal_instrument_ref');
            $table->index(['organization_id', 'company_id', 'legal_entity_id', 'store_id', 'status'], 'egc_scoped_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_guaranteed_contracts');
    }
};
