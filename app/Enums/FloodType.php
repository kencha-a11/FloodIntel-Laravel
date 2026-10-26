<?php

namespace App\Enums;

enum FloodType: string
{
    case RiverOverflow = 'river_overflow';
    case Ponding = 'ponding';

    public function label(): string
    {
        return match ($this) {
            self::RiverOverflow => 'River overflow',
            self::Ponding => 'Ponding',
        };
    }
}