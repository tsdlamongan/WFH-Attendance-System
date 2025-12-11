<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Team extends Model
{
    public const DEFAULT_REQUIRED_WORK_HOURS = 7.0;
    public const DEFAULT_LEAVE_QUOTA_DAYS = 12;
    public const DEFAULT_MAX_LEAVE_DAYS_PER_MONTH = 5;
    public const DEFAULT_CHECK_IN_WINDOW_START = '09:00:00';
    public const DEFAULT_CHECK_IN_WINDOW_END = '10:00:00';

    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'required_work_hours',
        'default_leave_quota_days',
        'max_leave_days_per_month',
        'check_in_window_start',
        'check_in_window_end',
        'whatsapp_api_secret',
        'whatsapp_account_unique_id',
        'whatsapp_account_phone',
        'whatsapp_account_name',
        'whatsapp_token',
        'whatsapp_connected',
        'whatsapp_connected_at',
        'whatsapp_recipient_phone',
        'whatsapp_recap_time',
        'whatsapp_recap_enabled',
        'whatsapp_last_sent_at',
        'whatsapp_last_error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'required_work_hours' => 'decimal:2',
            'default_leave_quota_days' => 'integer',
            'max_leave_days_per_month' => 'integer',
            'whatsapp_connected' => 'boolean',
            'whatsapp_recap_enabled' => 'boolean',
            'whatsapp_connected_at' => 'datetime',
            'whatsapp_last_sent_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($team) {
            if (empty($team->slug)) {
                $team->slug = Str::slug($team->name);
                
                // Ensure slug is unique
                $originalSlug = $team->slug;
                $count = 1;
                while (static::where('slug', $team->slug)->exists()) {
                    $team->slug = $originalSlug . '-' . $count;
                    $count++;
                }
            }
        });
    }

    /**
     * Get all users for the team.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all managers for the team.
     */
    public function managers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'manager');
    }

    /**
     * Get all employees for the team.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'employee');
    }

    /**
     * Check if team is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Get the required work hours for this team.
     */
    public function getRequiredWorkHours(): float
    {
        return $this->required_work_hours;
    }

    /**
     * Get the default leave quota days for this team.
     */
    public function getDefaultLeaveQuotaDays(): int
    {
        return $this->default_leave_quota_days;
    }

    /**
     * Get the max leave days per month for this team.
     */
    public function getMaxLeaveDaysPerMonth(): int
    {
        return $this->max_leave_days_per_month;
    }

    /**
     * Get the check-in window start time for this team.
     */
    public function getCheckInWindowStart(): string
    {
        return $this->check_in_window_start ?? self::DEFAULT_CHECK_IN_WINDOW_START;
    }

    /**
     * Get the check-in window end time for this team.
     */
    public function getCheckInWindowEnd(): string
    {
        return $this->check_in_window_end ?? self::DEFAULT_CHECK_IN_WINDOW_END;
    }

    /**
     * Get/Set the encrypted WhatsApp API secret.
     */
    protected function whatsappApiSecret(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? decrypt($value) : null,
            set: fn ($value) => $value ? encrypt($value) : null,
        );
    }

    /**
     * Check if WhatsApp is configured.
     */
    public function isWhatsappConfigured(): bool
    {
        return !is_null($this->whatsapp_api_secret) && !is_null($this->whatsapp_account_unique_id);
    }

    /**
     * Check if WhatsApp is connected.
     */
    public function isWhatsappConnected(): bool
    {
        return $this->whatsapp_connected && $this->isWhatsappConfigured();
    }

    /**
     * Check if team can send WhatsApp recap.
     */
    public function canSendWhatsappRecap(): bool
    {
        return $this->whatsapp_recap_enabled
            && $this->isWhatsappConnected()
            && !is_null($this->whatsapp_recipient_phone);
    }
}
