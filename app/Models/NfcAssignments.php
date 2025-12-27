<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NfcAssignments extends Model
{
    /** @use HasFactory<\Database\Factories\NfcAssignmentsFactory> */
    use HasFactory;
    protected $fillable = [
        'student_id',
        'device_id',
        'status',
        'status_message',
        'nfc_uid',
        'assignment_data'
    ];

    protected $casts = [
        'assignment_data' => 'array'
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_READY = 'ready_to_write';
    const STATUS_WAITING_CARD = 'waiting_card';
    const STATUS_WRITING = 'writing_data';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ERROR = 'error';
}
