<?php

namespace App\Enums;

enum AnomalyFlag: string
{
    case GpsOutsideBarangay = 'gps_outside_barangay';
    case DuplicateWindow = 'duplicate_window';
    case ObservationReceiptDeviation = 'observation_receipt_deviation';
    case MissingPhoto = 'missing_photo';

    public function label(): string
    {
        return match ($this) {
            self::GpsOutsideBarangay => 'GPS outside the assigned barangay',
            self::DuplicateWindow => 'Duplicate submission inside the window',
            self::ObservationReceiptDeviation => 'Observed time differs from receipt time',
            self::MissingPhoto => 'No photo attached',
        };
    }
}
