<?php

namespace App\Realtime;

use App\Models\FloodReport;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The published side of the two realtime events.
 *
 * A message is sent only after the write it describes has committed: a caller
 * inside a transaction is deferred with DB::afterCommit, and a caller outside
 * one is past its commit point already (FR-67, D-64).
 */
class ReportBroadcast
{
    public function __construct(private readonly SupabaseBroadcaster $broadcaster) {}

    public function created(FloodReport $report): void
    {
        $this->publish(fn (): bool => $this->broadcaster->publish(ReportMessage::created($report)));
    }

    /**
     * @param  list<string>  $changed
     */
    public function statusChanged(FloodReport $report, array $changed = ['status']): void
    {
        $this->publish(fn (): bool => $this->broadcaster->publish(
            ReportMessage::statusChanged($report, $changed),
        ));
    }

    private function publish(Closure $send): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);

            return;
        }

        $send();
    }
}
