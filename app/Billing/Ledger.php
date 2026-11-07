<?php

namespace App\Billing;

use App\Models\Student;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class Ledger
{
    public static function balance(int $studentId): string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return Money::cents(DB::table('studenttransactions')->where('student_id', $studentId)
                ->selectRaw('COALESCE(SUM(transactionamount), 0) AS total')->first()->total);
        }

        $balance = '0.00';
        foreach (DB::table('studenttransactions')->where('student_id', $studentId)->select('transactionamount')->cursor() as $row) {
            $balance = Money::add($balance, Money::cents($row->transactionamount ?? '0'));
        }

        return $balance;
    }

    public static function recalculate(Student $lockedStudent): void
    {
        $lockedStudent->balance = self::balance($lockedStudent->id);
        $lockedStudent->save();
    }

    public static function summary(iterable $transactions): array
    {
        $fees = $paid = '0.00';
        foreach ($transactions as $transaction) {
            $amount = (string) BigDecimal::of($transaction->transactionamount ?? '0')->abs()->toScale(2);
            $type = strtolower($transaction->transactiontype ?? '');
            if (in_array($type, ['payment', 'credit'], true)) {
                $paid = Money::add($paid, $amount);
            } elseif ($type === 'fee adjustment') {
                $fees = Money::add($fees, '-'.$amount);
            } elseif ($type === 'pmt adjustment') {
                $paid = Money::add($paid, '-'.$amount);
            } else {
                $fees = Money::add($fees, $amount);
            }
        }

        return ['fees' => $fees, 'paid' => $paid];
    }
}
