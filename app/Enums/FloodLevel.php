<?php

namespace App\Enums;

enum FloodLevel: string
{
    case Ankle = 'ankle';
    case Knee = 'knee';
    case Waist = 'waist';
    case Chest = 'chest';
    case Neck = 'neck';

    public function label(): string
    {
        return match ($this) {
            self::Ankle => 'Ankle-deep',
            self::Knee => 'Knee-deep',
            self::Waist => 'Waist-deep',
            self::Chest => 'Chest-deep',
            self::Neck => 'Neck-deep or above',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Ankle => 1,
            self::Knee => 2,
            self::Waist => 3,
            self::Chest => 4,
            self::Neck => 5,
        };
    }
}
