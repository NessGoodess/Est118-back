<?php

namespace App\Models;

use App\Enums\SessionStep;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramSession extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'step',
        'grade',
        'group',
        'curp',
        'guardian_id',
    ];

    protected $casts = [
        'step' => SessionStep::class,
    ];
}
