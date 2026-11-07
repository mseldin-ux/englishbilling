<?php

namespace App\Billing;

use App\Models\Student;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class AttributableIncome
{
    public const SIMPLE_FIELDS = ['id', 'name', 'fee', 'declined fee', 'refund', 'discounts', 'paid',
        'adjustments', 'credits', 'adjustedowed', 'adjustedpaid', 'ar', 'invoiced', 'writeoffs',
        'currenttimeinprogram', 'lengthofprogram', 'pct', 'earnedincome'];

    public const SUMMARY_FIELDS = ['invoiced', 'adjustedowed', 'adjustedpaid', 'ar', 'totalearned'];

    private const TYPE_ORDER = ['fee', 'note', 'payment', 'discount', 'credit', 'write off',
        'refund', 'declined fee', 'fee adjustment'];

    private const CATEGORIES = [
        'paid' => ['check', 'payment', 'payment/pago', 'pago'],
        'invoiced' => ['fee', 'declined fee'],
        'adjustments' => ['adjustment', 'adjustments', 'fee adjustment'],
        'discounts' => ['correction', 'waived', 'discount'],
        'writeoffs' => ['write off', 'nonexistant'],
        'credits' => ['credit', 'credits'],
        'pmtadj' => ['pmt adjustment', 'nonexistant'],
    ];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $query = DB::table('studenttransactions as t')->join('students as s', 's.id', '=', 't.student_id')
                ->where('t.transactiondate', '>=', $filters['startdate'])
                ->where('t.transactiondate', '<', $filters['enddate'])
                ->whereNotNull('t.transactionamount')
                ->select('t.id', 't.student_id', 't.transactiontype', 't.transactionamount', 's.first_name', 's.last_name')
                ->orderBy('t.id');
            if (! empty($filters['enrolldate'])) {
                $query->where('s.start_date', '>=', $filters['enrolldate']);
            }
            $types = $rows = [];
            foreach ($query->cursor() as $transaction) {
                $id = (string) $transaction->student_id;
                $type = strtolower($transaction->transactiontype ?? 'None');
                $amount = (string) BigDecimal::of((string) $transaction->transactionamount)->abs()->toScale(2);
                $types[$type] = Money::add($types[$type] ?? '0.00', $amount);
                $rows[$id] ??= ['id' => $id, 'name' => ($transaction->first_name ?? 'None').' '.($transaction->last_name ?? 'None'), 'types' => []];
                $rows[$id]['types'][$type] = Money::add($rows[$id]['types'][$type] ?? '0.00', $amount);
            }
            // Keep the owner's legacy sample layout independent of the database scan order.
            $types = array_replace(array_intersect_key(array_fill_keys(self::TYPE_ORDER, '0.00'), $types), $types);
            $calculated = self::totals($types, true);
            $totalEarned = BigRational::of(0);
            foreach (array_chunk(array_keys($rows), 300) as $ids) {
                $students = Student::whereIn('id', $ids)->with(['program', 'loas'])->orderBy('id')->get();
                foreach ($students as $student) {
                    $id = (string) $student->id;
                    $raw = $rows[$id];
                    $calc = self::totals($raw['types']);
                    $progress = ProgramProgress::calculate($student, $filters['enddate']);
                    $earned = BigRational::of($calc['adjustedowed'])->multipliedBy($progress['ratio']);
                    $totalEarned = $totalEarned->plus($earned);
                    $rows[$id] = ['id' => $id, 'name' => $raw['name'],
                        ...array_replace(array_fill_keys(array_keys($types), '0.00'), $raw['types']),
                        ...$calc, ...$progress['fields'],
                        'earnedincome' => (string) $earned->toScale(6, RoundingMode::HalfUp)];
                }
            }
            ksort($rows, SORT_STRING);
            // Round the aggregate once, rather than accumulating rounded per-learner amounts.
            $calculated['totalearned'] = (string) $totalEarned->toScale(2, RoundingMode::HalfUp);
            $extra = [...ProgramProgress::FIELDS, 'earnedincome', 'currenttimeinprogram'];
            $simple = ! empty($filters['usesimplefields']);

            return ['title' => 'CGMS Attributable Income Report', 'filters' => $filters, 'types' => $types,
                'calculated' => $calculated, 'rows' => array_values($rows),
                'fields' => $simple ? self::SIMPLE_FIELDS : ['id', 'name', ...array_keys($types), ...array_keys($calculated), ...$extra],
                'csvFields' => $simple ? self::SIMPLE_FIELDS : ['id', 'name', ...array_keys($types), ...self::SUMMARY_FIELDS, ...$extra]];
        });
    }

    private static function totals(array $types, bool $overall = false): array
    {
        $sums = [];
        foreach (self::CATEGORIES as $name => $categories) {
            $sum = '0.00';
            foreach ($categories as $category) {
                $sum = Money::add($sum, $types[$category] ?? '0.00');
            }
            $sums[$name] = BigDecimal::of($sum);
        }
        $owed = $sums['invoiced']->minus($sums['discounts'])->minus($sums['adjustments']);
        $paid = $sums['paid']->plus($sums['credits'])->minus($sums['pmtadj']->abs());
        // The legacy aggregate takes abs(owed); individual learner rows deliberately do not.
        $ar = ($overall ? $owed->abs() : $owed)->minus($paid->abs())->minus($sums['writeoffs']->abs());
        unset($sums['pmtadj']);
        $result = [];
        foreach (['paid' => $sums['paid'], 'invoiced' => $sums['invoiced'], 'adjustments' => $sums['adjustments'],
            'ar' => $ar, 'discounts' => $sums['discounts'], 'writeoffs' => $sums['writeoffs'],
            'credits' => $sums['credits'], 'adjustedowed' => $owed, 'adjustedpaid' => $paid] as $field => $value) {
            $result[$field] = (string) $value->toScale(2);
        }

        return $result;
    }
}
