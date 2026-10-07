<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case ON_PROGRESS = 'on_progress';
    case COMPLETED = 'completed';
    case HOLD = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::ON_PROGRESS => 'On-Progress',
            self::COMPLETED => 'Selesai',
            self::HOLD => 'Hold',
        };
    }
}
