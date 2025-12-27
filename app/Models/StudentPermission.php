<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPermission extends Model
{
    /** @use HasFactory<\Database\Factories\StudentPermissionFactory> */
    use HasFactory;
 protected $fillable = [
        'student_id',
        'authorized_by',
        'reason',
        'details',
        'permission_type',
        'requested_time',
        'authorized_time',
        'return_time',
        'status',
        'notes',
    ];
     protected $casts = [
        'requested_time' => 'datetime',
        'authorized_time' => 'datetime',
        'return_time' => 'datetime',
    ];

    /**
     * Get the student that owns the StudentPermission
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the authorized that owns the StudentPermission
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function authorized(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'authorized_by');
    }

    /**
     * Scope a query to only include active permissions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include bathroom permissions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBathroomPermission($query)
    {
        return $query->where('permission_type', 'bathroom');
    }
}

