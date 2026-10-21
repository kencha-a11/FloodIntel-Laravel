<?php

namespace App\Enums;

enum FloodReportStatus: string
{
    case Submitted = 'submitted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Rejected => 'Rejected',
        };
    }
}
