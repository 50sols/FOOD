<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * P071: conservatively permit permanent removal of an inactive, unreferenced
 * identity only. Any uncertain relationship or retention record fails closed.
 *
 * No related row is ever detached, updated, cascaded, anonymized or deleted.
 */
final class AdminUserDeletionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{eligible: bool, reasons: list<string>}
     */
    public function inspect(User $actor, User $target): array
    {
        $this->assertActorCanDelete($actor);
        $reasons = [];

        if ($actor->is($target)) {
            $reasons[] = 'self';
        }

        if ($target->is_active) {
            $reasons[] = 'active';
        }

        // Consumer identity erasure follows its separate self-service privacy
        // workflow; an administrator cannot bypass that retention lifecycle.
        if ((bool) $target->is_platform_customer) {
            $reasons[] = 'consumer';
        }

        // Do not infer Platform Owner status only from isPlatformOwner(), which
        // intentionally returns false for deactivated identities.
        if ($target->roles()->whereIn('roles.code', ['SUPER_ADMIN', 'PLATFORM_OWNER'])->exists()
            || (Schema::hasTable('platform_owner_registry')
                && DB::table('platform_owner_registry')->where('user_id', $target->getKey())->exists())) {
            $reasons[] = 'protected';
        }

        try {
            if ($this->hasAnyRetainedReference($target)) {
                $reasons[] = 'references';
            }
        } catch (Throwable) {
            // A partial schema inventory, failed introspection, missing table,
            // or denied database metadata access can never authorize deletion.
            $reasons[] = 'unverified';
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    public function permanentlyDelete(User $actor, User $candidate, string $reason, Request $request): void
    {
        $this->assertActorCanDelete($actor);

        DB::transaction(function () use ($actor, $candidate, $reason, $request): void {
            // Parent locking makes concurrent FK child inserts and reactivation
            // wait until this complete preflight + mutation commits on InnoDB.
            $target = User::query()->lockForUpdate()->findOrFail($candidate->getKey());

            if (! hash_equals((string) $target->email, (string) $request->input('confirm_email', ''))) {
                throw ValidationException::withMessages([
                    'confirm_email' => [__('v2_user_deletion.confirmation_mismatch')],
                ]);
            }

            $inspection = $this->inspect($actor, $target);
            if (! $inspection['eligible']) {
                throw ValidationException::withMessages([
                    'user' => [__('v2_user_deletion.blocked')],
                ]);
            }

            // Audit before removal within the SAME transaction. Audit targets
            // the immutable former ID/hash, not a soon-to-be-deleted FK; a
            // failed audit insert rolls back the entire operation.
            $this->audit->record(
                'user.permanently_deleted',
                $actor,
                null,
                ['target_user_id' => (int) $target->getKey(), 'email_sha256' => hash('sha256', strtolower((string) $target->email))],
                ['reason' => trim($reason), 'impact_reviewed' => true, 'retained_references' => 0],
                $request,
            );

            // Never delete dependent tables explicitly or rely on ON DELETE
            // CASCADE: every discoverable direct user reference must be empty.
            $target->delete();
        }, 3);
    }

    private function assertActorCanDelete(User $actor): void
    {
        // Service-level enforcement protects against future callers bypassing
        // the Dashboard controller's Gate; no store-scoped or dormant actor.
        abort_unless($actor->is_active && $actor->hasRole('SUPER_ADMIN')
            && $actor->hasPermission('users.delete'), 403);
    }

    private function hasAnyRetainedReference(User $target): bool
    {
        $tables = Schema::getTables();
        if ($tables === [] || count($tables) > 1000) {
            throw new RuntimeException('Unable to obtain a bounded identity relationship inventory.');
        }

        $userId = (int) $target->getKey();

        foreach ($tables as $metadata) {
            $table = (string) ($metadata['name'] ?? '');
            if ($table === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                throw new RuntimeException('Unverifiable table identifier in schema inventory.');
            }
            if ($table === 'users' || $table === 'sqlite_sequence') {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $columnSet = array_fill_keys($columns, true);
            $referencingColumns = [];

            foreach ($columns as $column) {
                // Include audit, assignment, maker/checker and polymorphic
                // actor references even when the database FK is absent.
                if ($column === 'user_id' || str_ends_with($column, '_user_id')
                    || in_array($column, [
                        'created_by', 'updated_by', 'deleted_by', 'approved_by',
                        'reviewed_by', 'requested_by', 'assigned_by', 'processed_by',
                        'submitted_by', 'posted_by', 'cancelled_by', 'actor_id',
                        'principal_id', 'assignee_id', 'initiated_by',
                    ], true)) {
                    $referencingColumns[] = $column;
                }
            }

            // Database-declared references take precedence over naming
            // conventions, including unusual foreign key column names.
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (strtolower((string) ($foreignKey['foreign_table'] ?? '')) === 'users') {
                    foreach ((array) ($foreignKey['columns'] ?? []) as $column) {
                        $referencingColumns[] = (string) $column;
                    }
                }
            }

            foreach (array_unique($referencingColumns) as $column) {
                if (! isset($columnSet[$column])) {
                    throw new RuntimeException('Foreign key metadata conflicts with column inventory.');
                }
                if (DB::table($table)->where($column, $userId)->exists()) {
                    return true;
                }
            }

            // Discover every conventional morph pair, not just a fixed
            // list: Sanctum tokenable, notifications notifiable, audit
            // auditable and future module-specific *_type / *_id pairs.
            foreach ($columns as $typeColumn) {
                if (! str_ends_with($typeColumn, '_type')) {
                    continue;
                }
                $prefix = substr($typeColumn, 0, -5);
                if (! isset($columnSet[$prefix.'_id'])) {
                    continue;
                }
                if (DB::table($table)
                    ->whereIn($typeColumn, [User::class, 'App\\User', 'user', 'users'])
                    ->where($prefix.'_id', $userId)
                    ->exists()) {
                    return true;
                }
            }

            // Password reset records must not become reusable after an email
            // address is released by physical deletion.
            if (in_array($table, ['password_reset_tokens', 'password_resets'], true)
                && isset($columnSet['email'])
                && DB::table($table)->where('email', (string) $target->email)->exists()) {
                return true;
            }
        }

        return false;
    }
}
