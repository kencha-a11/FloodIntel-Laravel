<?php

namespace App\Enums;

enum AccessInterface: string
{
    case CommandCenter = 'command_center';
    case FieldReporter = 'field_reporter';

    public function label(): string
    {
        return match ($this) {
            self::CommandCenter => 'Command Center Web Hub',
            self::FieldReporter => 'Field Reporter PWA',
        };
    }
}
