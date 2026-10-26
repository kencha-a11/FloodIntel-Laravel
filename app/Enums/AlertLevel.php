<?php

namespace App\Enums;

enum AlertLevel: string
{
    case Alert = 'alert';
    case GetReady = 'get_ready';
    case RedAlert = 'red_alert';

    public function label(): string
    {
        return match ($this) {
            self::Alert => 'Level 1 — Alert',
            self::GetReady => 'Level 2 — Get Ready',
            self::RedAlert => 'Level 3 — Red Alert',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Alert => 1,
            self::GetReady => 2,
            self::RedAlert => 3,
        };
    }
}