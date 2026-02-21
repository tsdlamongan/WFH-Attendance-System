<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveQuota extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'quota_days',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quota_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
