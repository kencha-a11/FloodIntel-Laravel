<?php

namespace App\Console\Commands;

use App\Services\NominatimBudget;
use App\Services\ReverseGeocoder;
use App\Support\MaintenanceDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveReportAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:resolve-addresses {--limit=50 : Maximum reports to inspect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fill missing report addresses through the shared geocoding budget';

    /**
     * Execute the console command.
     */
    public function handle(ReverseGeocoder $geocoder, NominatimBudget $budget): int
    {
        $reports = MaintenanceDatabaseContext::run(fn () => DB::table('flood_reports')
            ->select('id', 'latitude', 'longitude', 'address')
            ->whereNull('address')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get());

        $resolved = 0;

        foreach ($reports as $report) {
            $wait = $budget->waitSeconds();

            if ($wait > 0) {
                usleep((int) ceil($wait * 1_000_000));
            }

            $address = $geocoder->lookup((float) $report->latitude, (float) $report->longitude);

            if ($address === null) {
                continue;
            }

            MaintenanceDatabaseContext::run(fn () => DB::table('flood_reports')
                ->where('id', $report->id)
                ->whereNull('address')
                ->update(['address' => $address]));

            $resolved++;
        }

        $this->info("Resolved {$resolved} of {$reports->count()} missing addresses.");

        return self::SUCCESS;
    }
}
