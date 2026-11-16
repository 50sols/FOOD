<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQL-enforced P110 protection: after legal-title evidence exists, an
     * inventory's canonical stock movements cannot be rewritten or erased.
     * A read-side COUNT/MAX watermark alone is susceptible to ABA deletion.
     *
     * This does not grant title or replace a future immutable per-owner ledger.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')
            || ! Schema::hasTable('enterprise_inventory_owner_positions')
            || ! Schema::hasTable('enterprise_inventory_title_attestations')) {
            throw new RuntimeException('Canonical movement and legal-title evidence tables are required.');
        }

        $driver = DB::connection()->getDriverName();

        // Drop only these deterministic P110 triggers, allowing a safe retry
        // after one DDL statement succeeds and the other fails on MySQL.
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_update');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM enterprise_inventory_owner_positions AS owner_position
        INNER JOIN enterprise_inventory_title_attestations AS evidence
            ON evidence.owner_position_id = owner_position.id
        WHERE owner_position.inventory_id = OLD.inventory_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot delete stock movement with attested legal title';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM enterprise_inventory_owner_positions AS owner_position
        INNER JOIN enterprise_inventory_title_attestations AS evidence
            ON evidence.owner_position_id = owner_position.id
        WHERE owner_position.inventory_id IN (OLD.inventory_id, NEW.inventory_id)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot rewrite stock movement with attested legal title';
    END IF;
END
SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM enterprise_inventory_owner_positions AS owner_position
    INNER JOIN enterprise_inventory_title_attestations AS evidence
        ON evidence.owner_position_id = owner_position.id
    WHERE owner_position.inventory_id = OLD.inventory_id
)
BEGIN
    SELECT RAISE(ABORT, 'Cannot delete stock movement with attested legal title');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM enterprise_inventory_owner_positions AS owner_position
    INNER JOIN enterprise_inventory_title_attestations AS evidence
        ON evidence.owner_position_id = owner_position.id
    WHERE owner_position.inventory_id IN (OLD.inventory_id, NEW.inventory_id)
)
BEGIN
    SELECT RAISE(ABORT, 'Cannot rewrite stock movement with attested legal title');
END
SQL);

            return;
        }

        throw new RuntimeException('Attested stock-movement history requires supported MySQL/MariaDB or SQLite.');
    }

    public function down(): void
    {
        // A downgrade must not remove the only DB-level protection of live
        // signed title evidence; stop instead of claiming reversible history.
        if (Schema::hasTable('enterprise_inventory_title_attestations')
            && DB::table('enterprise_inventory_title_attestations')->exists()) {
            throw new RuntimeException('Retain attested stock-movement guards until all title evidence has a verified recovery plan.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_delete');
    }
};
