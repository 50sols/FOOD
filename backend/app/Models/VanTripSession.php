<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Source-backed identity for one B2B Van trip, NOT evidence of loaded stock
 * or financial settlement. Its reserved_van_id is intentionally retained
 * after the operational end until a separate verified closeout exists.
 *
 * @property int $id
 * @property string $public_id
 * @property int $van_assignment_id
 * @property int $van_id
 * @property int $warehouse_id
 * @property int $store_id
 * @property int $operator_user_id
 * @property int $started_by_user_id
 * @property int|null $ended_by_user_id
 * @property int|null $reserved_van_id
 * @property string $status
 * @property string $start_key
 * @property string|null $end_key
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 */
final class VanTripSession extends Model
{
    protected $guarded = [];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VanAssignment::class, 'van_assignment_id');
    }

    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
