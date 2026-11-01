<?php

namespace App\Services;

use App\Enums\AnomalyFlag;
use App\Geo\ContainmentResult;
use App\Models\FloodReport;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Computes FR-26's anomaly flags once, at ingest.
 *
 * A flag is a review aid: it never changes a status, and an answer it cannot
 * confirm produces no flag. The duplicate query must run inside the insert
 * transaction so it sees the pre-insert state.
 */
final class ReportAnomalyDetector
{
    public function __construct(private readonly BarangayContainment $containment) {}

    /**
     * @return list<AnomalyFlag>
     */
    public function detect(
        User $user,
        float $latitude,
        float $longitude,
        CarbonInterface $observedAt,
    ): array {
        $flags = [];

        if ($this->outsideAssignedBarangay($user, $latitude, $longitude)) {
            $flags[] = AnomalyFlag::GpsOutsideBarangay;
        }

        if ($this->hasSubmissionInsideWindow($user, $observedAt)) {
            $flags[] = AnomalyFlag::DuplicateWindow;
        }

        if ($this->observationDeviatesFromReceipt($observedAt)) {
            $flags[] = AnomalyFlag::ObservationReceiptDeviation;
        }

        return $flags;
    }

    private function outsideAssignedBarangay(User $user, float $latitude, float $longitude): bool
    {
        $barangayCode = $user->barangay?->code;
        $result = $this->containment->locate($latitude, $longitude, $barangayCode);

        if ($result->status === ContainmentResult::OUTSIDE) {
            return true;
        }

        return $result->status === ContainmentResult::INSIDE
            && $barangayCode !== null
            && $result->code !== $barangayCode;
    }

    private function hasSubmissionInsideWindow(User $user, CarbonInterface $observedAt): bool
    {
        $window = (int) config('flood.anomalies.duplicate_window_minutes');

        return FloodReport::query()
            ->where('user_id', $user->id)
            ->where('barangay_id', $user->barangay_id)
            ->whereBetween('reported_at', [
                $observedAt->copy()->subMinutes($window),
                $observedAt->copy()->addMinutes($window),
            ])
            ->exists();
    }

    private function observationDeviatesFromReceipt(CarbonInterface $observedAt): bool
    {
        $threshold = (int) config('flood.anomalies.observation_deviation_minutes');

        return abs($observedAt->diffInMinutes(now())) > $threshold;
    }
}
