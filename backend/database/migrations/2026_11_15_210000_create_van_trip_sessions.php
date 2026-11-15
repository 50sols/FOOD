<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('van_trip_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('van_assignment_id')->constrained('van_assignments')->restrictOnDelete();
            $table->foreignId('van_id')->constrained('vans')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Remains reserved after a trip ends; only a later, proven
            // custody/settlement authority may release it. UNIQUE permits
            // multiple NULLs but never two reserved trips for one Van.
            $table->foreignId('reserved_van_id')->nullable()->unique()->constrained('vans')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->string('start_key', 120);
            $table->string('end_key', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['van_id', 'start_key'], 'van_trip_start_idempotency');
            $table->index(['store_id', 'status', 'started_at'], 'van_trip_scoped_status');
        });

        Schema::create('van_trip_session_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('van_trip_session_id')->constrained('van_trip_sessions')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 24);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['van_trip_session_id', 'sequence'], 'van_trip_event_sequence');
            $table->unique(['van_trip_session_id', 'idempotency_key'], 'van_trip_event_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_trip_session_events');
        Schema::dropIfExists('van_trip_sessions');
    }
};
