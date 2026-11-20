<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final class LegacyImporter
{
    private const ENROLLMENT_HISTORY = ['enrollment_events', 'student_stage_enrollments', 'student_enrollments'];

    public function __construct(private Connection $source, private Connection $target) {}

    public function run(bool $verifyOnly = false, ?Closure $progress = null): array
    {
        return $this->execute($verifyOnly, $progress);
    }

    public function refresh(bool $apply = false, ?Closure $progress = null): array
    {
        return $this->execute(false, $progress, true, ! $apply);
    }

    private function execute(bool $verifyOnly, ?Closure $progress, bool $refresh = false, bool $preview = false): array
    {
        $this->assertSeparateDatabases();
        $columns = [];
        foreach (BillingTables::MONEY as $table => $money) {
            $sourceColumns = $this->source->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = $this->target->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = array_values(array_diff($targetColumns, BillingTables::destinationOnly()[$table] ?? []));
            sort($sourceColumns);
            sort($targetColumns);
            if ($sourceColumns !== $targetColumns || $sourceColumns === []) {
                throw new RuntimeException("Schema mismatch for {$table}; import refused.");
            }
            $columns[$table] = $targetColumns;
        }

        if ($this->source->getDriverName() === 'mysql') {
            $this->source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->source->statement('SET TRANSACTION READ ONLY');
        }

        return $this->source->transaction(function () use ($verifyOnly, $progress, $columns, $refresh, $preview) {
            return $this->target->transaction(function () use ($verifyOnly, $progress, $columns, $refresh, $preview) {
                $plan = $refresh ? $this->refreshPlan() : null;
                $enrollmentReset = [];
                if ($refresh) {
                    foreach (self::ENROLLMENT_HISTORY as $table) {
                        $enrollmentReset[$table] = $this->target->table($table)->count();
                    }
                }
                if ($preview) {
                    return ['mode' => 'refresh_preview', 'tables' => $plan,
                        'enrollment_reset' => $enrollmentReset,
                        'source_database' => $this->source->getDatabaseName(),
                        'target_database' => $this->target->getDatabaseName()];
                }
                if ($refresh) {
                    if ($this->target->table('student_edit_locks')->exists()) {
                        throw new RuntimeException('Student editing locks exist; end editing sessions before refreshing.');
                    }
                    $this->resetEnrollments();
                    $this->target->table('invoices')->whereNotNull('emailed_at')->update(['emailed_at' => null]);
                    $progress?->__invoke('Reset test enrollment history and billing attribution.');
                    foreach ($columns as $table => $fields) {
                        $this->rows($this->source, $table, $fields, function (array $rows) use ($table, $fields) {
                            if ($table === 'programs') {
                                $existing = $this->target->table($table)->whereIn('id', array_column($rows, 'id'))->pluck('id')->all();
                                $rows = array_values(array_filter($rows, fn (array $row) => ! in_array($row['id'], $existing)));
                                if ($rows !== []) {
                                    $this->target->table($table)->insert($rows);
                                }
                            } else {
                                $this->target->table($table)->upsert($rows, ['id'], array_values(array_diff($fields, ['id'])));
                            }
                        });
                        $progress?->__invoke("Refreshed {$table}");
                    }
                    foreach (array_reverse(array_keys($columns)) as $table) {
                        foreach (array_chunk($plan[$table]['removed_ids'], 250) as $ids) {
                            $this->target->table($table)->whereIn('id', $ids)->delete();
                        }
                    }
                    $this->rebuildBalances();
                } elseif (! $verifyOnly) {
                    foreach (array_keys(BillingTables::MONEY) as $table) {
                        if ($this->target->table($table)->exists()) {
                            throw new RuntimeException("Destination {$table} is populated; import refused. Use --verify-only or a new database.");
                        }
                    }
                    foreach ($columns as $table => $fields) {
                        $this->rows($this->source, $table, $fields, function (array $rows) use ($table) {
                            $this->target->table($table)->insert($rows);
                        });
                        $progress?->__invoke("Imported {$table}");
                    }
                    $this->rebuildBalances();
                }

                $report = ['verified_at' => now()->toIso8601String(), 'tables' => [],
                    'balance_changes' => [], 'pdfs' => ['count' => 0, 'bytes' => 0]];
                if ($refresh) {
                    $report['mode'] = 'refresh_applied';
                    $report['refresh_plan'] = $plan;
                    $report['enrollment_reset'] = $enrollmentReset;
                    $report['source_database'] = $this->source->getDatabaseName();
                    $report['target_database'] = $this->target->getDatabaseName();
                }
                $report['destination_only_student_fields'] = BillingTables::destinationOnly()['students'];
                $report['destination_only_program_fields'] = BillingTables::PROGRAM_ADDITIONS;
                foreach ($columns as $table => $fields) {
                    $report['tables'][$table] = $table === 'programs'
                        ? $this->verifyPrograms($fields, $refresh) : $this->verifyTable($table, $fields);
                    $progress?->__invoke("Verified {$table}");
                }
                $programs = $report['tables']['programs'];
                if ($programs['edited'] !== [] || $programs['added_ids'] !== []) {
                    $progress?->__invoke(sprintf('Programs maintained in Laravel: %d legacy program(s) renamed, %d added (allowed).',
                        count($programs['edited']), count($programs['added_ids'])));
                }
                $this->verifyBalances($report);
                $report['exceptions'] = $this->exceptions();
                $report['peak_memory_bytes'] = memory_get_peak_usage(true);

                return $report;
            });
        });
    }

    private function refreshPlan(): array
    {
        if (! $this->source->table('students')->exists()) {
            throw new RuntimeException('Source students table is empty; refresh refused.');
        }
        $plan = [];
        foreach (array_keys(BillingTables::MONEY) as $table) {
            $source = $this->source->table($table)->orderBy('id')->pluck('id')->all();
            $target = $this->target->table($table)->orderBy('id')->pluck('id')->all();
            $localOnly = array_values(array_diff($target, $source));
            $plan[$table] = [
                'source_rows' => count($source), 'target_rows' => count($target),
                'inserted_rows' => count(array_diff($source, $target)),
                'overwritten_rows' => $table === 'programs' ? 0 : count(array_intersect($source, $target)),
                'removed_ids' => $table === 'programs' ? [] : $localOnly,
                'preserved_local_ids' => $table === 'programs' ? $localOnly : [],
            ];
        }
        // Enrollment history is explicitly discarded; other Laravel-only records must survive intact.
        foreach ($this->target->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            if (array_key_exists($table, $plan) || in_array($table, self::ENROLLMENT_HISTORY, true)) {
                continue;
            }
            foreach ($this->target->getSchemaBuilder()->getForeignKeys($table) as $foreign) {
                $parent = $foreign['foreign_table'];
                if (! isset($plan[$parent]) || $foreign['foreign_columns'] !== ['id']) {
                    continue;
                }
                foreach (array_chunk($plan[$parent]['removed_ids'], 250) as $ids) {
                    if ($this->target->table($table)->whereIn($foreign['columns'][0], $ids)->exists()) {
                        throw new RuntimeException("Refresh refused: {$table} references local-only {$parent} records. Resolve these records before refreshing.");
                    }
                }
            }
        }

        return $plan;
    }

    private function resetEnrollments(): void
    {
        $this->target->table('students')->whereNotNull('current_enrollment_id')->orWhere('enrollment_revision', '!=', 0)
            ->update(['current_enrollment_id' => null, 'enrollment_revision' => 0]);
        foreach (['studenttransactions', 'invoices', 'studentpaymentplans', 'anticipatedpayment', 'loas'] as $table) {
            $values = ['enrollment_id' => null, 'stage_enrollment_id' => null];
            $query = $this->target->table($table)->whereNotNull('enrollment_id')->orWhereNotNull('stage_enrollment_id');
            if ($table === 'studenttransactions') {
                $values['recognition'] = null;
                $query->orWhereNotNull('recognition');
            }
            $query->update($values);
        }
        foreach (['formsdata', 'finalgrades'] as $table) {
            $this->target->table($table)->whereNotNull('enrollment_id')->orderBy('id')->eachById(function ($row) use ($table) {
                $this->target->table($table)->where('id', $row->id)
                    ->update(['enrollment_id' => null, 'enrollment_key' => 'refresh:'.$row->enrollment_id]);
            });
        }
        foreach (self::ENROLLMENT_HISTORY as $table) {
            $this->target->table($table)->delete();
        }
    }

    private function assertSeparateDatabases(): void
    {
        foreach ([$this->source, $this->target] as $connection) {
            if (! in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
                throw new RuntimeException('The importer supports MySQL and SQLite only.');
            }
            $connection->disableQueryLog();
        }
        if ($this->source->getDriverName() === $this->target->getDriverName()) {
            if ($this->source->getDriverName() === 'mysql') {
                $identity = fn (Connection $db) => (array) $db->selectOne('SELECT @@server_uuid AS server, DATABASE() AS db');
                $same = $identity($this->source) === $identity($this->target);
            } else {
                $source = $this->source->getDatabaseName();
                $target = $this->target->getDatabaseName();
                $same = $this->source === $this->target || ($source !== ':memory:' && realpath($source) === realpath($target));
            }
            if ($same) {
                throw new RuntimeException('Source and destination are the same database; import refused.');
            }
        }
    }

    private function query(Connection $db, string $table, array $fields): Builder
    {
        $query = $db->table($table)->select($fields);
        if ($db === $this->source && $db->getDriverName() === 'mysql') {
            $query->select([]);
            foreach ($fields as $field) {
                if (in_array($field, BillingTables::MONEY[$table], true)) {
                    $query->selectRaw("CAST(`{$field}` AS DECIMAL(15,2)) AS `{$field}`");
                } else {
                    $query->addSelect($field);
                }
            }
        }

        return $query;
    }

    private function rows(Connection $db, string $table, array $fields, Closure $consume): void
    {
        $this->query($db, $table, $fields)->chunkById($table === 'invoices' ? 25 : 250,
            function ($chunk) use ($table, $consume) {
                $rows = [];
                foreach ($chunk as $record) {
                    $row = (array) $record;
                    foreach (BillingTables::MONEY[$table] as $field) {
                        if (array_key_exists($field, $row) && $row[$field] !== null) {
                            $row[$field] = Money::cents($row[$field]);
                        }
                    }
                    $rows[] = $row;
                }
                $consume($rows);
            });
    }

    private function rebuildBalances(): void
    {
        $this->target->table('students')->select('id')->chunkById(250, function ($students) {
            foreach ($students as $student) {
                $this->target->table('students')->where('id', $student->id)->update([
                    'balance' => $this->ledgerBalance($this->target, $student->id),
                ]);
            }
        });
    }

    private function ledgerBalance(Connection $db, int $studentId): string
    {
        if ($db->getDriverName() === 'mysql') {
            return Money::cents($db->table('studenttransactions')->where('student_id', $studentId)
                ->selectRaw('COALESCE(SUM(CAST(transactionamount AS DECIMAL(15,2))), 0) AS total')->first()->total);
        }
        $balance = '0.00';
        foreach ($db->table('studenttransactions')->where('student_id', $studentId)->select('transactionamount')->cursor() as $row) {
            if ($row->transactionamount !== null) {
                $balance = Money::add($balance, Money::cents($row->transactionamount));
            }
        }

        return $balance;
    }

    private function verifyTable(string $table, array $fields): array
    {
        $fields = array_values(array_diff($fields, $table === 'students' ? ['balance'] : []));
        $summarize = function (Connection $db) use ($table, $fields) {
            $hash = hash_init('sha256');
            $count = 0;
            $totals = array_fill_keys(array_intersect($fields, BillingTables::MONEY[$table]), '0.00');
            $pdfCount = 0;
            $pdfBytes = 0;
            $this->rows($db, $table, $fields, function (array $rows) use (&$count, &$totals, &$pdfCount, &$pdfBytes, $hash) {
                foreach ($rows as $row) {
                    foreach ($row as $value) {
                        // Length framing distinguishes nulls, empty strings, and arbitrary binary PDF bytes.
                        hash_update($hash, $value === null ? 'N;' : strlen((string) $value).':'.(string) $value.';');
                    }
                    foreach ($totals as $field => $total) {
                        $totals[$field] = Money::add($total, $row[$field] ?? '0.00');
                    }
                    if (isset($row['invoice_file'])) {
                        $pdfCount++;
                        $pdfBytes += strlen($row['invoice_file']);
                    }
                    $count++;
                }
            });

            return ['rows' => $count, 'sha256' => hash_final($hash), 'money_totals' => $totals,
                'pdf_count' => $pdfCount, 'pdf_bytes' => $pdfBytes];
        };
        $source = $summarize($this->source);
        $target = $summarize($this->target);
        if ($source !== $target) {
            throw new RuntimeException("Verification failed for {$table}: counts, values, or PDF bytes differ.");
        }

        return $target;
    }

    /**
     * Programs are reference data maintained in Laravel after import: codes and descriptions
     * may be edited and programs added. Every legacy id must remain, with unchanged servicedays,
     * because students reference those ids and income reports depend on servicedays.
     */
    private function verifyPrograms(array $fields, bool $allowServiceDaysChanges = false): array
    {
        $source = $this->source->table('programs')->orderBy('id')->get($fields)->keyBy('id');
        $target = $this->target->table('programs')->orderBy('id')->get($fields)->keyBy('id');
        $edited = [];
        foreach ($source as $id => $legacy) {
            $current = $target->get($id);
            if ($current === null) {
                throw new RuntimeException("Verification failed for programs: legacy program {$id} is missing.");
            }
            if (! $allowServiceDaysChanges && (string) $legacy->servicedays !== (string) $current->servicedays) {
                throw new RuntimeException("Verification failed for programs: servicedays changed for legacy program {$id}.");
            }
            $changes = [];
            foreach (array_diff($fields, $allowServiceDaysChanges ? ['id'] : ['id', 'servicedays']) as $field) {
                if ($legacy->{$field} !== $current->{$field}) {
                    $changes[$field] = ['legacy' => $legacy->{$field}, 'current' => $current->{$field}];
                }
            }
            if ($changes !== []) {
                $edited[$id] = $changes;
            }
        }

        return ['legacy_rows' => $source->count(), 'rows' => $target->count(), 'edited' => $edited,
            'added_ids' => $target->keys()->diff($source->keys())->values()->all()];
    }

    private function verifyBalances(array &$report): void
    {
        $total = '0.00';
        $this->source->table('students')->select(['id', 'balance'])->chunkById(250, function ($students) use (&$report, &$total) {
            foreach ($students as $student) {
                $expected = $this->ledgerBalance($this->source, $student->id);
                $actual = $this->target->table('students')->where('id', $student->id)->value('balance');
                if ($actual === null || Money::cents($actual) !== $expected) {
                    throw new RuntimeException("Balance verification failed for student {$student->id}.");
                }
                $total = Money::add($total, $expected);
                if ($student->balance === null || ! BigDecimal::of((string) $student->balance)->isEqualTo($expected)) {
                    $report['balance_changes'][] = ['student_id' => $student->id,
                        'original' => $student->balance === null ? null : (string) $student->balance,
                        'rounded_original' => $student->balance === null ? null : Money::cents($student->balance),
                        'ledger_balance' => $expected];
                }
            }
        });
        $report['balance_total'] = $total;
        $report['pdfs'] = ['count' => $report['tables']['invoices']['pdf_count'],
            'bytes' => $report['tables']['invoices']['pdf_bytes']];
    }

    private function exceptions(): array
    {
        return [
            'unassigned_transactions' => $this->target->table('studenttransactions')->whereNull('student_id')->count(),
            'null_transaction_types' => $this->target->table('studenttransactions')->whereNull('transactiontype')->count(),
            'cross_student_invoice_links' => $this->target->table('studenttransactions as t')
                ->join('invoices as i', 'i.id', '=', 't.invoice_id')
                ->where(function (Builder $query) {
                    $query->whereColumn('t.student_id', '<>', 'i.student_id')
                        ->orWhere(function (Builder $query) {
                            $query->whereNull('t.student_id')->whereNotNull('i.student_id');
                        })->orWhere(function (Builder $query) {
                            $query->whereNotNull('t.student_id')->whereNull('i.student_id');
                        });
                })->count(),
            'loas_missing_start' => $this->target->table('loas')->whereNull('loa_start')->count(),
            'loas_open_ended' => $this->target->table('loas')->whereNull('loa_end')->count(),
            'loas_end_before_start' => $this->target->table('loas')->whereColumn('loa_end', '<', 'loa_start')->count(),
        ];
    }
}
