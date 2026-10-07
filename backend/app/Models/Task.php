<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    /**
     * Task title the check-in form inserts for standby mode. Frontend and backend must use this
     * same value. Detection is wider than this exact title, see STANDBY_PATTERN.
     */
    public const STANDBY_TITLE = 'Standby';

    /**
     * A task whose title contains a standby-like word marks the check-in as standby and sends the
     * WhatsApp standby notification to the manager. Matches "standby", "stand by", "stand-by",
     * "standbye", "stanby" and the like anywhere in the title, but not "standard" or "standing".
     * Keep in sync with STANDBY_PATTERN in frontend/src/utils/standby.js.
     */
    public const STANDBY_PATTERN = '/\bstand?[\s_-]*(?:by|bye|bay)\b/iu';

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

    /**
     * Whether a task title puts the check-in in standby mode (see STANDBY_PATTERN).
     */
    public static function isStandbyTitle(?string $title): bool
    {
        return $title !== null && preg_match(self::STANDBY_PATTERN, $title) === 1;
    }

    /**
     * Key used to tell whether two titles are "the same task": case and surrounding or repeated
     * whitespace do not count.
     */
    public static function normalizeTitle(?string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $title)));
    }

    public function isStandby(): bool
    {
        return self::isStandbyTitle($this->title);
    }
}
