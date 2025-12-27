<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IdCardPrintLog extends Model
{
    /** @use HasFactory<\Database\Factories\IdCardPrintLogFactory> */
    use HasFactory;
    protected $fillable = [
        'id_card_id',
        'generated_by',
        'rendered_card_path',
        'printed_at',
        'printer_name',
        'reason',
        'notes',
    ];

    /**
     * Get the IdCard that owns the IdCardPrintLog
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function IdCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class);
    }


    public function generatedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
