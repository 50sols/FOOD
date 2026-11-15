<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'enterprise_ar_allocations';

    private const SOURCE = 'source_collection_allocation_id';

    // MySQL/MariaDB identifiers cannot exceed 64 characters. Laravel's
    // derived name for this long table+column exceeds that limit.
    private const FOREIGN_KEY = 'ear_alloc_coll_src_fk';

    private const UNIQUE_KEY = 'ear_alloc_collection_source_unique';

    public function up(): void
    {
        // MySQL DDL is not transactional: an earlier failed FK creation may
        // already have left the nullable column in place. Retry only the
        // missing schema pieces; never backfill from editable receipt text.
        if (! Schema::hasColumn(self::TABLE, self::SOURCE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId(self::SOURCE)->nullable();
            });
        }

        $hasForeignKey = false;
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === self::FOREIGN_KEY) {
                $hasForeignKey = true;
                break;
            }
        }

        if (! $hasForeignKey) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(self::SOURCE, self::FOREIGN_KEY)
                    ->references('id')
                    ->on('collection_allocations')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(self::SOURCE, self::UNIQUE_KEY);
            });
        }
    }

    public function down(): void
    {
        // Only for unactivated upgrades. Never assume that live financial
        // postings or a MySQL DDL sequence can be rolled back atomically.
        if (! Schema::hasColumn(self::TABLE, self::SOURCE)) {
            return;
        }

        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === self::FOREIGN_KEY) {
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->dropForeign(self::FOREIGN_KEY);
                });
                break;
            }
        }

        if (Schema::hasIndex(self::TABLE, self::UNIQUE_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_KEY);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::SOURCE);
        });
    }
};
