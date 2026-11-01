<?php

namespace App\Enums;

enum FloodImpactStatus: string
{
    case Submitted = 'submitted';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Superseded => 'Superseded',
        };
    }
}
