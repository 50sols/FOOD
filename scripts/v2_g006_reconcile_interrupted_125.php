<?php

/**
 * G006 isolated MySQL acceptance only.
 *
 * This is NOT a production update or an automatic migration-repair command.
 * It may reconcile the exact interrupted v1.0.125 CI fixture after verifying
 * the complete table, keys, FK actions, preserved audit row, and missing ledger.
 * Never ship or invoke it from the Laravel Admin updater.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Laravel's registered CLI exception reporter can log a failure and return
// exit status 0 for an uncaught standalone-script exception. A fail-closed
// recovery guard must also fail the invoking shell's exit-status contract.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'G006 CI fixture validation rejected: '.$error->getMessage().PHP_EOL);
    exit(2);
});

$mode = $argv[1] ?? '--verify-only';
if (! in_array($mode, ['--verify-only', '--reconcile-ci-fixture'], true) || count($argv) > 2) {
    throw new RuntimeException('Usage: php scripts/v2_g006_reconcile_interrupted_125.php [--verify-only|--reconcile-ci-fixture]');
}

$connection = DB::connection();
if (config('app.env') !== 'testing'
    || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== 'foodex_recovery_125'
    || $connection->getConfig('host') !== '127.0.0.1') {
    throw new RuntimeException('G006 fixture reconciliation is restricted to isolated testing MySQL.');
}

$table = 'order_customer_service_discounts';
$migration = '2026_11_08_070000_create_order_customer_service_discounts';
$marker = '6b676f27-84ec-4aba-8507-5e62d7b44f23';
if (! Schema::hasTable($table) || ! Schema::hasTable('migrations')) {
    throw new RuntimeException('G006 interrupted table or migration ledger is missing.');
}

$expectedTypes = [
    'id' => ['bigint unsigned', 'NO'],
    'order_id' => ['bigint unsigned', 'NO'],
    'applied_by_user_id' => ['bigint unsigned', 'NO'],
    'idempotency_key' => ['char(36)', 'NO'],
    'revision' => ['int unsigned', 'NO'],
    'percentage' => ['decimal(6,3)', 'NO'],
    'reason' => ['text', 'NO'],
    'prior_customer_service_discount' => ['decimal(14,3)', 'NO'],
    'customer_service_discount' => ['decimal(14,3)', 'NO'],
    'discount_total_before' => ['decimal(14,3)', 'NO'],
    'discount_total_after' => ['decimal(14,3)', 'NO'],
    'grand_total_before' => ['decimal(14,3)', 'NO'],
    'grand_total_after' => ['decimal(14,3)', 'NO'],
    'order_status' => ['varchar(32)', 'NO'],
    'created_at' => ['timestamp', 'YES'],
    'updated_at' => ['timestamp', 'YES'],
];

$columnRows = $connection->select(
    'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    [$table]
);
$columns = [];
foreach ($columnRows as $column) {
    $columns[$column->COLUMN_NAME] = $column;
}
if (array_diff_key($expectedTypes, $columns) !== []
    || array_diff_key($columns, $expectedTypes) !== []) {
    throw new RuntimeException('G006 existing discount table has unexpected or missing columns.');
}
foreach ($expectedTypes as $name => [$type, $nullable]) {
    $column = $columns[$name];
    if (strtolower((string) $column->COLUMN_TYPE) !== $type
        || strtoupper((string) $column->IS_NULLABLE) !== $nullable) {
        throw new RuntimeException('G006 existing discount table type/nullability mismatch: '.$name);
    }
}
if (strtoupper((string) $columns['id']->COLUMN_KEY) !== 'PRI'
    || stripos((string) $columns['id']->EXTRA, 'auto_increment') === false
    || ! in_array((string) $columns['prior_customer_service_discount']->COLUMN_DEFAULT, ['0.000', '0'], true)) {
    throw new RuntimeException('G006 existing discount table has invalid primary key or default.');
}

$indexRows = $connection->select(
    'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
     ORDER BY INDEX_NAME, SEQ_IN_INDEX',
    [$table]
);
$indexes = [];
foreach ($indexRows as $row) {
    $indexes[(string) $row->INDEX_NAME][] = $row;
}
foreach ([
    'PRIMARY' => ['id'],
    'order_cs_discount_idempotency' => ['order_id', 'idempotency_key'],
    'order_cs_discount_revision' => ['order_id', 'revision'],
] as $name => $expectedColumns) {
    $rows = $indexes[$name] ?? [];
    if (count($rows) !== count($expectedColumns)
        || array_map(static fn (object $row): string => (string) $row->COLUMN_NAME, $rows) !== $expectedColumns
        || array_filter($rows, static fn (object $row): bool => (int) $row->NON_UNIQUE !== 0) !== []) {
        throw new RuntimeException('G006 existing discount table unique index mismatch: '.$name);
    }
}

$fkRows = $connection->select(
    'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
     FROM information_schema.KEY_COLUMN_USAGE k
     INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
       ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
       AND r.TABLE_NAME = k.TABLE_NAME
       AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
     WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ?
       AND k.REFERENCED_TABLE_NAME IS NOT NULL',
    [$table]
);
$foreignKeys = [];
foreach ($fkRows as $fk) {
    $foreignKeys[(string) $fk->COLUMN_NAME] = [
        (string) $fk->REFERENCED_TABLE_NAME,
        (string) $fk->REFERENCED_COLUMN_NAME,
        strtoupper((string) $fk->DELETE_RULE),
    ];
}
if ($foreignKeys !== [
    'order_id' => ['orders', 'id', 'CASCADE'],
    'applied_by_user_id' => ['users', 'id', 'RESTRICT'],
] && count($foreignKeys) === 2) {
    // Compare without depending on MySQL's returned foreign-key row order.
    if (($foreignKeys['order_id'] ?? null) !== ['orders', 'id', 'CASCADE']
        || ($foreignKeys['applied_by_user_id'] ?? null) !== ['users', 'id', 'RESTRICT']) {
        throw new RuntimeException('G006 existing discount table foreign-key mismatch.');
    }
}
if (count($foreignKeys) !== 2) {
    throw new RuntimeException('G006 unexpected discount table foreign keys.');
}

$rows = $connection->table($table)->where('idempotency_key', $marker)->get();
if ($rows->count() !== 1
    || (string) $rows->first()->reason !== 'preserve discount audit across interrupted deployment'
    || (string) $rows->first()->percentage !== '5.000') {
    throw new RuntimeException('G006 expected existing discount audit data is missing or modified.');
}
$beforeCount = $connection->table($table)->count();
$ledgerCount = $connection->table('migrations')->where('migration', $migration)->count();
if ($ledgerCount > 1) {
    throw new RuntimeException('G006 duplicated migration ledger entry.');
}

if ($mode === '--reconcile-ci-fixture' && $ledgerCount === 0) {
    // This one fixture's historical migration performs only this one CREATE TABLE.
    // MySQL DDL is not rollback-safe; structural verification is required first.
    $connection->transaction(static function () use ($connection, $migration): void {
        if ($connection->table('migrations')->where('migration', $migration)->exists()) {
            throw new RuntimeException('G006 migration ledger changed concurrently.');
        }
        $lastBatch = (int) $connection->table('migrations')->max('batch');
        if ($lastBatch < 1) {
            throw new RuntimeException('G006 baseline Laravel migrations are missing.');
        }
        $connection->table('migrations')->insert([
            'migration' => $migration,
            'batch' => $lastBatch + 1,
        ]);
    });
}

if ($connection->table($table)->count() !== $beforeCount
    || ($mode === '--reconcile-ci-fixture'
        && $connection->table('migrations')->where('migration', $migration)->count() !== 1)) {
    throw new RuntimeException('G006 reconciliation must preserve audit rows and exactly one ledger record.');
}

echo 'G006: exact historical v125 CI-only table, constraints and preserved audit verified; mode='.$mode.PHP_EOL;
