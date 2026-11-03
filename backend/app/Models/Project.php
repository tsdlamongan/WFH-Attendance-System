<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class Project extends Model
{
    use HasFactory;

    /** Progress is reported in steps of this many percent (the report has one column per step). */
    public const PROGRESS_STEP = 10;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'pic',
        'progress',
        'status',
        'note',
        'is_active',
    ];

    /**
     * Default values, so a freshly created model matches the column defaults without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'progress' => 0,
        'status' => ProjectStatus::ON_PROGRESS->value,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'status' => ProjectStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Validation rule for a project id an employee may put on a task:
     * an active project of the employee's own team.
     */
    public static function selectableBy(User $user): Exists
    {
        return Rule::exists('projects', 'id')
            ->where(fn ($query) => $query->where('team_id', $user->team_id)->where('is_active', true));
    }

    /**
     * Get the team that owns the project.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the tasks employees logged for the project.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
