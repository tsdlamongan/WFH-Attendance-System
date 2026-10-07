<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    /**
     * Task title used to indicate standby mode (check-in). When this task is present, a WhatsApp
     * notification is sent to the manager. Frontend and backend must use this same value.
     */
    public const STANDBY_TITLE = 'Standby';

    /**
     * Blocker reason written by the auto checkout on tasks the employee left open.
     */
    public const AUTO_CHECKOUT_BLOCKER = 'belum selesai';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'attendance_id',
        'project_id',
        'title',
        'is_completed',
        'blocker_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
        ];
    }

    /**
     * Get the attendance that owns the task.
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * Get the project the task was logged for (null when the employee picked none).
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
