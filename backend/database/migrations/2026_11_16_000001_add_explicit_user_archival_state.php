<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['archived_by']);
            $table->dropIndex('users_archived_at_idx');
            $table->dropColumn(['archived_by', 'archived_at']);
        });
    }
};
