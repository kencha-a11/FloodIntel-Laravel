<?php

namespace App\Enums;

enum FloodReportStatus: string
{
    case Submitted = 'submitted';
    case Validated = 'validated';
    case Returned = 'returned';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Validated => 'Validated',
            self::Returned => 'Returned',
            self::Rejected => 'Rejected',
        };
    }
}
