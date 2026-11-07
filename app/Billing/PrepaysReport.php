<?php

namespace App\Billing;

use App\Models\Student;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrepaysReport
{
    public const HEADERS = ['cohort', 'current', 'loa length', 'discounts', 'first_name', 'grad', 'graddate',
        'graduated', 'id', 'inactive', 'last_name', 'length', 'name', 'owed', 'paid', 'prepaid',
        'prorataowed', 'prepaid_accrual', 'prorataowed_accrual', 'start', 'start_date', 'studentname'];

    public const NOTICE = 'Rebuilt from the surviving legacy experimental calculation. Requires accounting acceptance; not yet approved for reconciliation.';

    private const TYPES = [
        'owed' => ['fee adjustment', 'fee', 'declined fee'],
        'discounts' => ['adjustment', 'credit', 'correction', 'discount', 'waived'],
        'writeoffs' => ['write off'],
        'paid' => ['check', 'payment', 'reversal', 'payment/pago', 'pago', 'refund'],
    ];

    public static function run(array $filters): array
    {
        return DB::transaction(function () use ($filters) {
            $students = Student::where('start_date', '>=', $filters['startdate'])
                ->where('start_date', '<', $filters['lastpossiblestartdate'])
                ->where(fn ($q) => $q->where('graddate', '>=', $filters['startdate'])->orWhereNull('graddate'))
                ->with('loas')->orderBy('id')->get();
            $sums = [];
            foreach (array_chunk($students->modelKeys(), 300) as $ids) {
                $transactions = DB::table('studenttransactions')->whereIn('student_id', $ids)
                    ->where('transactiondate', '>=', $filters['transstart'])->where('transactiondate', '<', $filters['dateasof'])
                    ->select('id', 'student_id', 'transactiontype', 'transactionamount')->orderBy('id');
                foreach ($transactions->cursor() as $transaction) {
                    foreach (self::TYPES as $category => $types) {
                        if (! in_array(strtolower($transaction->transactiontype ?? ''), $types, true)) {
                            continue;
                        }
                        if ($transaction->transactionamount === null) {
                            throw ValidationException::withMessages(['report' => "Transaction {$transaction->id} has a missing amount. Correct it before generating Prepays."]);
                        }
                        $amount = BigDecimal::of((string) $transaction->transactionamount)->toScale(2);
                        if ($category === 'paid') {
                            // The surviving helper singles out the exact spelling "Refund".
                            $amount = $transaction->transactiontype === 'Refund' ? $amount->abs() : $amount->abs()->negated();
                        }
                        $sums[$transaction->student_id][$category] = Money::add($sums[$transaction->student_id][$category] ?? '0.00', (string) $amount);
                    }
                }
            }
            $rows = [];
            $totals = array_fill_keys(['Owed', 'Discounts', 'Paid', 'Prepaid', 'Prorata Owed', 'Prepaid_accrual', 'Prorata Owed Accrual'], BigRational::of(0));
            $asOf = CarbonImmutable::parse($filters['dateasof'], 'UTC')->startOfDay();
            foreach ($students as $student) {
                $start = CarbonImmutable::parse($student->getRawOriginal('start_date'), 'UTC');
                $grad = $student->graddate === null ? $asOf : CarbonImmutable::parse($student->getRawOriginal('graddate'), 'UTC');
                $length = $grad->getTimestamp() - $start->getTimestamp();
                if ($length < 0) {
                    throw ValidationException::withMessages(['report' => "Learner {$student->id} has graduation before enrollment. Correct it before generating Prepays."]);
                }
                $loaSeconds = 0;
                foreach ($student->loas as $loa) {
                    $begin = $loa->loa_start === null ? $start : CarbonImmutable::parse($loa->getRawOriginal('loa_start'), 'UTC');
                    $end = $loa->loa_end === null ? $asOf : min(CarbonImmutable::parse($loa->getRawOriginal('loa_end'), 'UTC'), $asOf);
                    // This older report adds all LOAs, including reversed/future intervals.
                    $loaSeconds += $end->getTimestamp() - $begin->getTimestamp();
                }
                $current = $asOf->getTimestamp() - $start->getTimestamp() - $loaSeconds;
                $served = max(0, min($current, $length));
                $length = $length === 0 ? 547 * 86400 : $length;
                $ratio = BigRational::ofFraction($served, $length);
                $amounts = [];
                foreach (array_keys(self::TYPES) as $category) {
                    $amounts[$category] = BigRational::of($sums[$student->id][$category] ?? '0.00')->abs();
                }
                $adjusted = $amounts['owed']->minus($amounts['discounts']);
                $accrual = $adjusted->multipliedBy($ratio)->abs();
                $unearned = $adjusted->abs()->minus($accrual);
                $completed = false;
                foreach (['graddate', 'sus_date', 'inactive_date', 'actualdategrad', 'wd_date'] as $field) {
                    $raw = $student->getRawOriginal($field);
                    if ($raw !== null && CarbonImmutable::parse($raw, 'UTC') <= $asOf) {
                        $completed = true;
                    }
                }
                $prorata = $completed ? $amounts['paid']->plus($amounts['writeoffs']) : $accrual;
                $prepaid = $amounts['paid']->plus($amounts['writeoffs'])->minus($prorata);
                if ($prepaid->isNegative()) {
                    $prepaid = BigRational::of(0);
                }
                if (($filters['skipzero'] ?? '') === 'yes' && ! $prepaid->isPositive()) {
                    continue;
                }
                foreach (['Owed' => $amounts['owed'], 'Discounts' => $amounts['discounts'], 'Paid' => $amounts['paid'],
                    'Prepaid' => $prepaid, 'Prorata Owed' => $prorata, 'Prepaid_accrual' => $unearned,
                    'Prorata Owed Accrual' => $accrual->minus($amounts['discounts'])] as $field => $value) {
                    $totals[$field] = $totals[$field]->plus($value);
                }
                $name = ($student->first_name ?? 'None').' '.($student->last_name ?? 'None');
                $values = ['cohort' => $student->cohort ?? '', 'current' => ReportFormatting::duration($current),
                    'loa length' => ReportFormatting::duration($loaSeconds), 'first_name' => $student->first_name ?? '',
                    'grad' => $grad->format('Y-m-d H:i:s'), 'graddate' => ReportFormatting::date($student->getRawOriginal('graddate')),
                    'graduated' => $student->getRawOriginal('graduated') === null ? '' : (string) $student->getRawOriginal('graduated'),
                    'id' => (string) $student->id, 'inactive' => $student->getRawOriginal('inactive') === null ? '' : (string) $student->getRawOriginal('inactive'),
                    'last_name' => $student->last_name ?? '', 'length' => ReportFormatting::duration($length), 'name' => $name,
                    'owed' => (string) $amounts['owed']->toScale(2), 'discounts' => (string) $amounts['discounts']->toScale(2),
                    'paid' => (string) $amounts['paid']->toScale(2), 'prepaid' => (string) $prepaid->toScale(6, RoundingMode::HalfUp),
                    'prorataowed' => (string) $prorata->toScale(6, RoundingMode::HalfUp),
                    'prepaid_accrual' => (string) $unearned->toScale(6, RoundingMode::HalfUp),
                    'prorataowed_accrual' => (string) $accrual->toScale(6, RoundingMode::HalfUp),
                    'start' => $start->format('Y-m-d H:i:s'), 'start_date' => ReportFormatting::date($student->getRawOriginal('start_date')), 'studentname' => $name];
                $rows[] = array_replace(array_fill_keys(self::HEADERS, ''), $values);
            }
            $sort = match ($filters['sort'] ?? '') {
                'Last Name' => 'last_name', 'Graduation Date' => 'graddate', default => 'id',
            };
            usort($rows, fn ($a, $b) => ($sort === 'id' ? (int) $a[$sort] <=> (int) $b[$sort] : strcmp($a[$sort], $b[$sort])) ?: (int) $a['id'] <=> (int) $b['id']);

            return ['filters' => $filters, 'rows' => $rows,
                'totals' => array_map(fn ($value) => (string) $value->toScale(2, RoundingMode::HalfUp), $totals)];
        });
    }
}
