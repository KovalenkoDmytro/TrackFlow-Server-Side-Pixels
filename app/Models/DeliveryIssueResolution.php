<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery issue (platform + error code) the merchant marked as resolved.
 *
 * Failures of that code on that platform whose last attempt (platform_deliveries.updated_at)
 * is at or before resolved_at no longer appear on issue cards or count toward the health score.
 * Newer failures of the same code bring the card back.
 */
final class DeliveryIssueResolution extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'code',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
