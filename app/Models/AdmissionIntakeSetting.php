<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Singleton: school-wide new-admission intake policy.
 */
class AdmissionIntakeSetting extends Model
{
    protected $fillable = [
        'singleton_key',
        'score_mode',
        'exam_weight',
        'average_weight',
        'balance_load',
        'balance_scores',
        'separate_same_school',
        'separate_siblings',
        'sibling_detection',
        'allow_convert_without_complete_docs',
        'allow_convert_without_complete_data',
        'allow_convert_without_payment',
        'require_exam_before_convert',
        'require_score_before_placement',
        'allow_manual_group_change',
        'late_intake_enabled',
        'late_requires_manual_group',
        'late_suggest_group',
        'late_lock_batch_rebalance',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'exam_weight' => 'float',
            'average_weight' => 'float',
            'balance_load' => 'boolean',
            'balance_scores' => 'boolean',
            'allow_convert_without_complete_docs' => 'boolean',
            'allow_convert_without_complete_data' => 'boolean',
            'allow_convert_without_payment' => 'boolean',
            'require_exam_before_convert' => 'boolean',
            'require_score_before_placement' => 'boolean',
            'allow_manual_group_change' => 'boolean',
            'late_intake_enabled' => 'boolean',
            'late_requires_manual_group' => 'boolean',
            'late_suggest_group' => 'boolean',
            'late_lock_batch_rebalance' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return DB::transaction(function () {
            $row = static::query()
                ->where('singleton_key', 1)
                ->lockForUpdate()
                ->first();

            if ($row) {
                return $row;
            }

            return static::query()->create(['singleton_key' => 1]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'score_mode' => $this->score_mode,
            'exam_weight' => $this->exam_weight,
            'average_weight' => $this->average_weight,
            'balance_load' => $this->balance_load,
            'balance_scores' => $this->balance_scores,
            'separate_same_school' => $this->separate_same_school,
            'separate_siblings' => $this->separate_siblings,
            'sibling_detection' => $this->sibling_detection,
            'allow_convert_without_complete_docs' => $this->allow_convert_without_complete_docs,
            'allow_convert_without_complete_data' => $this->allow_convert_without_complete_data,
            'allow_convert_without_payment' => $this->allow_convert_without_payment,
            'require_exam_before_convert' => $this->require_exam_before_convert,
            'require_score_before_placement' => $this->require_score_before_placement,
            'allow_manual_group_change' => $this->allow_manual_group_change,
            'late_intake_enabled' => $this->late_intake_enabled,
            'late_requires_manual_group' => $this->late_requires_manual_group,
            'late_suggest_group' => $this->late_suggest_group,
            'late_lock_batch_rebalance' => $this->late_lock_batch_rebalance,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
