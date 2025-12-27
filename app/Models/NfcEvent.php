<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NfcEvent extends Model
{
    /** @use HasFactory<\Database\Factories\NfcEventFactory> */
    use HasFactory;

    protected $fillable = [
        'status',
        'reader',
        'uid',
        'message',
    ];
}
