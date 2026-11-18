<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Deactivation retains credentials/history but differs from a
            // deliberately archived management identity.
            $table->timestamp('archived_at')->nullable()->after('deactivated_at');
            $table->foreignId('archived_by')->nullable()->after('archived_at')
                ->constrained('users')->restrictOnDelete();
            $table->index('archived_at', 'users_archived_at_idx');
        });
    }

    public function down(): void
    {
        // An update rollback must never erase a committed archive decision or
        // its actor provenance. Stop for an explicit recovery plan instead.
        if ((Schema::hasColumn('users', 'archived_at')
                && DB::table('users')->whereNotNull('archived_at')->exists())
            || (Schema::hasColumn('users', 'archived_by')
                && DB::table('users')->whereNotNull('archived_by')->exists())) {
            throw new \RuntimeException('Cannot roll back user archival metadata while retained archival history exists.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['archived_by']);
            $table->dropIndex('users_archived_at_idx');
            $table->dropColumn(['archived_by', 'archived_at']);
        });
    }
};
