<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery issue (platform + error code) a merchant sent to TrackFlow support.
 *
 * Open while closed_at is null. Marking the issue resolved closes it; a later
 * recurrence can then be reported again.
 */
final class SupportReport extends Model
{
    protected $fillable = [
        'user_id',
        'platform_integration_id',
        'platform',
        'code',
        'note',
        'failure_count',
        'details',
        'reported_at',
        'emailed_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'failure_count' => 'integer',
            'reported_at' => 'immutable_datetime',
            'emailed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<SupportReport>  $query
     * @return Builder<SupportReport>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PlatformIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(PlatformIntegration::class, 'platform_integration_id');
    }
}
