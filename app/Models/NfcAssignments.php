<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NfcAssignments extends Model
{
    public const ACTION_ASSIGN = 'assign';

    public const ACTION_VERIFY = 'verify';

    public const ACTION_REWRITE = 'rewrite';

    public const STATUS_PENDING = 'pending';

    public const STATUS_WAITING_CARD = 'waiting_card';

    public const STATUS_CARD_DETECTED = 'card_detected';

    public const STATUS_WRITING = 'writing';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ERROR = 'error';

    public const FAILURE_REMOVED_EARLY = 'removed_early';

    public const FAILURE_VERIFY_MISMATCH = 'verify_mismatch';

    public const FAILURE_WRITE_ERROR = 'write_error';

    public const FAILURE_TAG_OCCUPIED = 'tag_occupied';

    public const FAILURE_READER_OFFLINE = 'reader_offline';

    public const FAILURE_TIMEOUT = 'timeout';

    public const FAILURE_EMPTY_CREDENTIAL = 'empty_credential';

    public const FAILURE_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_WAITING_CARD,
        self::STATUS_CARD_DETECTED,
        self::STATUS_WRITING,
        self::STATUS_VERIFYING,
    ];

    /** @var list<string> */
    public const PROGRESS_STATUSES = [
        self::STATUS_WAITING_CARD,
        self::STATUS_CARD_DETECTED,
        self::STATUS_WRITING,
        self::STATUS_VERIFYING,
    ];

    /** @var list<string> */
    public const FAILURE_CODES = [
        self::FAILURE_REMOVED_EARLY,
        self::FAILURE_VERIFY_MISMATCH,
        self::FAILURE_WRITE_ERROR,
        self::FAILURE_TAG_OCCUPIED,
        self::FAILURE_READER_OFFLINE,
        self::FAILURE_TIMEOUT,
        self::FAILURE_EMPTY_CREDENTIAL,
        self::FAILURE_CANCELLED,
    ];

    protected $table = 'nfc_assignments';

    protected $fillable = [
        'uuid',
        'student_id',
        'academic_year_id',
        'device_id',
        'action',
        'status',
        'status_message',
        'failure_code',
        'nfc_uid',
        'expected_credential_id',
        'read_back',
        'assignment_data',
        'claimed_by',
        'claimed_at',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assignment_data' => 'array',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NfcAssignments $job): void {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
};
