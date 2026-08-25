<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionConversionBatch extends Model
{
    protected $fillable = [
        'user_id',
        'academic_year_id',
        'channel',
        'status',
        'dry_run',
        'expected_count',
        'requested_count',
        'converted_count',
        'skipped_count',
        'failed_count',
        'policy_snapshot',
        'idempotency_key',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'policy_snapshot' => 'array',
            'expected_count' => 'integer',
            'requested_count' => 'integer',
            'converted_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdmissionConversionBatchItem::class, 'batch_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withItems = true): array
    {
        $data = [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'channel' => $this->channel,
            'status' => $this->status,
            'dry_run' => $this->dry_run,
            'expected_count' => $this->expected_count,
            'requested' => $this->requested_count,
            'converted' => $this->converted_count,
            'skipped' => $this->skipped_count,
            'failed' => $this->failed_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($withItems) {
            $data['results'] = $this->items
                ->map(fn (AdmissionConversionBatchItem $item) => $item->toApiArray())
                ->values()
                ->all();
        }

        return $data;
    }
}
