<?php

namespace App\Models;

use App\Enums\OvertimeStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Overtime extends Model
{
    use HasFactory;

    public const SOURCE_EMPLOYEE = 'employee';

    public const SOURCE_MANAGER = 'manager';

    public const MIN_MINUTES = 15;

    public const MAX_MINUTES = 480;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'date',
        'planned_minutes',
        'approved_minutes',
        'reason',
        'status',
        'source',
        'approved_by',
        'approved_at',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'planned_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'approved_at' => 'datetime',
            'status' => OvertimeStatus::class,
        ];
    }

    /**
     * Get the user that owns the overtime.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved/rejected the overtime.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the attendance sessions worked under this overtime.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function isPending(): bool
    {
        return $this->status === OvertimeStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === OvertimeStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === OvertimeStatus::REJECTED;
    }

    /**
     * Hard cap (in minutes) for the overtime sessions linked to this record.
     * A rejected record allows nothing; otherwise the manager's approved
     * minutes win over what the employee asked for.
     */
    public function allowedMinutes(): int
    {
        if ($this->isRejected()) {
            return 0;
        }

        return (int) ($this->approved_minutes ?? $this->planned_minutes);
    }

    /**
     * Minutes already worked under this record: finished sessions plus the running one.
     */
    public function usedMinutes(): float
    {
        return (float) $this->attendances()
            ->get(['id', 'check_in', 'check_out', 'total_hours'])
            ->sum(fn (Attendance $attendance) => $attendance->check_out
                ? (float) $attendance->total_hours * 60
                : $attendance->check_in->diffInMinutes(Carbon::now()));
    }
}
