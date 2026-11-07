<?php

namespace App\Console\Commands;

use App\Billing\LegacyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportLegacyBilling extends Command
{
    protected $signature = 'billing:import {--verify-only : Verify an already imported, unchanged snapshot}';

    protected $description = 'Import and verify the nine billing tables from the configured read-only legacy source';

    public function handle(): int
    {
        $source = DB::connection('legacy');
        $target = DB::connection();
        $report = (new LegacyImporter($source, $target))->run(
            (bool) $this->option('verify-only'), fn (string $message) => $this->info($message),
        );
        $path = 'imports/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Data verification completed, but reconciliation could not be saved. Check storage permissions and run --verify-only.');
        }
        $this->info('Billing data verified. Reconciliation: '.Storage::disk('local')->path($path));
        $this->info('Peak PHP memory: '.round($report['peak_memory_bytes'] / 1048576, 1).' MiB');

        return self::SUCCESS;
    }
}
