<?php

namespace App\Jobs;

use App\Services\ReverseGeocoder;
use App\Support\MaintenanceDatabaseContext;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

final class ResolveReportAddress
{
    use Dispatchable;

    public function __construct(private readonly int $reportId) {}

    /**
     * Fill a report's derived address after the submission has been accepted.
     *
     * A failure or a spent provider budget leaves the column null; the repair
     * command owns the follow-up, so nothing here retries or substitutes.
     * The row is read and written as app_maintenance (D-98), which holds only
     * the columns named here; the provider call stays outside both transactions.
     */
    public function handle(ReverseGeocoder $geocoder): void
    {
        $report = MaintenanceDatabaseContext::run(fn () => DB::table('flood_reports')
            ->select('id', 'latitude', 'longitude', 'address')
            ->find($this->reportId));

        if ($report === null || $report->address !== null) {
            return;
        }

        $address = $geocoder->lookup((float) $report->latitude, (float) $report->longitude);

        if ($address === null) {
            return;
        }

        // A row that gained an address in the meantime no longer matches the
        // maintenance policy's USING clause, so this updates nothing.
        MaintenanceDatabaseContext::run(fn () => DB::table('flood_reports')
            ->where('id', $report->id)
            ->whereNull('address')
            ->update(['address' => $address]));
    }
}
