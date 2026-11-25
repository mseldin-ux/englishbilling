<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StageBillingAllocations
{
    /**
     * @param  array<int, array{stage_enrollment_id: int, amount: string}>  $allocations
     */
    public static function replace(Student $student, Invoice|StudentTransaction $record, array $allocations): void
    {
        if ((int) $record->student_id !== (int) $student->id || ! $record->exists) {
            throw ValidationException::withMessages(['allocations' => 'The billing record must belong to this learner.']);
        }
        DB::transaction(function () use ($student, $record, $allocations) {
            $locked = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            self::replaceLocked($student, $locked, $allocations);
        });
    }

    private static function replaceLocked(Student $student, Invoice|StudentTransaction $record, array $allocations): void
    {
        if ((int) $record->student_id !== (int) $student->id) {
            throw ValidationException::withMessages(['allocations' => 'The billing record must belong to this learner.']);
        }
        $amount = $record instanceof Invoice ? $record->amount : $record->transactionamount;
        if ($amount === null || $allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Enter the billing amount and at least one stage allocation.']);
        }
        $total = BigDecimal::zero();
        $rows = [];
        $seen = [];
        foreach ($allocations as $allocation) {
            $stageId = $allocation['stage_enrollment_id'] ?? null;
            $value = $allocation['amount'] ?? null;
            if (! is_numeric($stageId) || (int) $stageId <= 0 || ! is_string($value)
                || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
                throw ValidationException::withMessages(['allocations' => 'Each allocation needs a stage and a non-negative amount in dollars and cents.']);
            }
            $stageId = (int) $stageId;
            if (isset($seen[$stageId])) {
                throw ValidationException::withMessages(['allocations' => 'A stage can be allocated only once per billing record.']);
            }
            $seen[$stageId] = true;
            $total = $total->plus($value);
            $rows[] = ['stage_enrollment_id' => $stageId, 'amount' => (string) BigDecimal::of($value)->toScale(2)];
        }
        $valid = DB::table('student_stage_enrollments as stage')
            ->join('student_enrollments as enrollment', 'enrollment.id', '=', 'stage.enrollment_id')
            ->where('enrollment.student_id', $student->id)->whereIn('stage.id', array_keys($seen))->count();
        if ($valid !== count($seen)) {
            throw ValidationException::withMessages(['allocations' => 'Every allocation must refer to a stage belonging to this learner.']);
        }
        if (! $total->isEqualTo(BigDecimal::of((string) $amount)->abs())) {
            throw ValidationException::withMessages(['allocations' => 'Stage amounts must add up exactly to the billing amount.']);
        }
        $invoice = $record instanceof Invoice;
        $table = $invoice ? 'invoice_stage_allocations' : 'transaction_stage_allocations';
        $key = $invoice ? 'invoice_id' : 'transaction_id';
        DB::table($table)->where($key, $record->id)->delete();
        DB::table($table)->insert(array_map(fn ($row) => [$key => $record->id, ...$row], $rows));
    }
}
