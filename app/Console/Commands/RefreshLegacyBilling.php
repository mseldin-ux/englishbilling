<?php

namespace App\Console\Commands;

use App\Billing\LegacyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RefreshLegacyBilling extends Command
{
    protected $signature = 'billing:refresh
        {--apply : Apply the refresh; without this option only preview changes}
        {--source= : Confirm the configured legacy source database name}
        {--target= : Confirm the destination database name}';

    protected $description = 'Preview or apply a local billing refresh, discarding test enrollment history';

    public function handle(): int
    {
        $source = DB::connection('legacy');
        $target = DB::connection();
        $apply = (bool) $this->option('apply');
        if (! app()->environment('local')) {
            throw new RuntimeException('Billing refresh is restricted to APP_ENV=local.');
        }
        foreach ([$source, $target] as $connection) {
            if ($connection->getDriverName() !== 'mysql'
                || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('Billing refresh requires local MySQL source and destination connections.');
            }
        }
        if ($apply) {
            if ($this->option('source') !== $source->getDatabaseName()
                || $this->option('target') !== $target->getDatabaseName()) {
                throw new RuntimeException('Supply --source and --target matching the configured database names.');
            }
            if (! app()->isDownForMaintenance()) {
                throw new RuntimeException('Stop workers and scheduled jobs, then run php artisan down before applying a refresh.');
            }
        }

        $this->info(($apply ? 'Applying' : 'Previewing').' refresh: '.$source->getDatabaseName().' -> '.$target->getDatabaseName());
        $report = (new LegacyImporter($source, $target))->refresh($apply, fn (string $message) => $this->info($message));
        $plan = $apply ? $report['refresh_plan'] : $report['tables'];
        $this->table(['Table', 'Source rows', 'Insert', 'Overwrite', 'Remove', 'Keep local'], array_map(
            fn (string $table, array $counts) => [$table, $counts['source_rows'], $counts['inserted_rows'],
                $counts['overwritten_rows'], count($counts['removed_ids']), count($counts['preserved_local_ids'])],
            array_keys($plan), array_values($plan),
        ));
        $this->warn($apply
            ? 'Test enrollment history and billing attribution were reset. Stage settings and logins were retained.'
            : 'Applying this refresh will discard test enrollment history and reset billing attribution. Stage settings and logins will be retained.');
        $this->table([$apply ? 'Enrollment history discarded' : 'Enrollment history to discard', 'Rows'], array_map(
            fn (string $table, int $count) => [$table, $count],
            array_keys($report['enrollment_reset']), array_values($report['enrollment_reset']),
        ));
        $path = 'imports/refresh-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException($apply
                ? 'Refresh committed and verified, but reconciliation could not be saved. Check storage permissions; do not assume the refresh rolled back.'
                : 'Preview could not be saved. Check storage permissions.');
        }
        $this->info(($apply ? 'Refresh committed and verified.' : 'Preview only; no database rows changed.').' Reconciliation: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }
}
