<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectionsReport
{
    public const HEADERS = ['student id', 'active?', 'name', 'enrollment date', 'due date', 'amount due', 'remaining balance'];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('students')->where(fn ($q) => $q->whereNull('graduated')->orWhere('graduated', false))
                ->select('id', 'inactive', 'first_name', 'middle_name', 'last_name', 'start_date',
                    'next_due_date', 'payment_amount_due', 'balance')->orderBy('start_date')->orderBy('id');
            foreach (['enrolldate' => ['start_date', '>='], 'enrolledbeforedate' => ['start_date', '<'],
                'next_due_date' => ['next_due_date', '<'], 'collectionsmin' => ['payment_amount_due', '>='],
                'collectionsmax' => ['payment_amount_due', '<=']] as $filter => [$column, $operator]) {
                if (isset($filters[$filter])) {
                    $query->where($column, $operator, $filters[$filter]);
                }
            }
            if (! empty($filters['hideinactive'])) {
                $query->where(fn ($q) => $q->whereNull('inactive')->orWhere('inactive', false));
            }
            $rows = [];
            $displayNames = [];
            $total = '0.00';
            foreach ($query->cursor() as $student) {
                if ($student->balance === null) {
                    throw ValidationException::withMessages(['report' => "Learner {$student->id} has no computed balance. Recalculate balances before running Collections."]);
                }
                $balance = Money::cents((string) $student->balance);
                $total = Money::add($total, $balance);
                // Preserve the legacy CSV name concatenation, including its missing separator.
                $name = ($student->first_name ?? '').($student->middle_name && strtolower($student->middle_name) !== 'none' ? $student->middle_name.' ' : '').($student->last_name ?? '');
                $displayNames[$student->id] = ReportFormatting::name($student->first_name, $student->middle_name, $student->last_name);
                $rows[] = ['id' => $student->id, 'active' => $student->inactive ? 'False' : 'True',
                    'name' => $name, 'start' => ReportFormatting::date($student->start_date),
                    'due' => ReportFormatting::date($student->next_due_date),
                    'amount' => $student->payment_amount_due === null ? '' : Money::cents((string) $student->payment_amount_due),
                    'balance' => $balance];
            }

            return ['filters' => $filters, 'rows' => $rows, 'total' => $total, 'displayNames' => $displayNames];
        });
    }
}
