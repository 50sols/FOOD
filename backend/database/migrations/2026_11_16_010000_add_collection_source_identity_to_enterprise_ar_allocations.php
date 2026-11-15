<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable expansion: pre-existing manual AR postings retain their
        // accounting identity. Never backfill from editable receipt references.
        Schema::table('enterprise_ar_allocations', function (Blueprint $table): void {
            $table->foreignId('source_collection_allocation_id')
                ->nullable()
                ->constrained('collection_allocations')
                ->restrictOnDelete();
            $table->unique(
                'source_collection_allocation_id',
                'ear_alloc_collection_source_unique',
            );
        });
    }

    public function down(): void
    {
        // Only used for an unactivated upgrade; no live finance rollback assumed.
        Schema::table('enterprise_ar_allocations', function (Blueprint $table): void {
            $table->dropUnique('ear_alloc_collection_source_unique');
            $table->dropConstrainedForeignId('source_collection_allocation_id');
        });
    }
};
