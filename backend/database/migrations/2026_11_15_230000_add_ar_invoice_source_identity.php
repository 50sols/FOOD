<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand only: existing manual AR documents remain unbound and retain
        // their historical journal links. Never infer provenance by number/text.
        Schema::table('enterprise_ar_documents', function (Blueprint $table): void {
            $table->foreignId('source_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->restrictOnDelete();
            $table->unique(
                ['legal_entity_id', 'source_invoice_id'],
                'ear_doc_legal_invoice_source_uq',
            );
        });
    }

    public function down(): void
    {
        // Rollback only before enabling a verified settlement posting contract.
        Schema::table('enterprise_ar_documents', function (Blueprint $table): void {
            $table->dropUnique('ear_doc_legal_invoice_source_uq');
            $table->dropConstrainedForeignId('source_invoice_id');
        });
    }
};
