<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecentReading extends Model
{
    protected $fillable = [
        'student_id',
        'read_at',
        'event',
        'message',
        'credential_id',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
