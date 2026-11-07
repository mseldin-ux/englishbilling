<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class PaymentSchedule
{
    public static function months(string $list): array
    {
        if (! preg_match('/^[1-9]\d*(?:\s*,\s*[1-9]\d*)*$/', $list)) {
            throw ValidationException::withMessages(['monthslist' => 'Use positive month numbers separated by commas, for example 1,7,13.']);
        }
        $months = array_map('intval', explode(',', $list));
        if (count($months) > 120 || max($months) > 120 || $months !== array_values(array_unique($months))) {
            throw ValidationException::withMessages(['monthslist' => 'Use at most 120 distinct month numbers, each from 1 to 120.']);
        }
        $sorted = $months;
        sort($sorted);
        if ($sorted !== $months || $months[0] !== 1) {
            throw ValidationException::withMessages(['monthslist' => 'Month numbers must start at 1 and increase.']);
        }

        return $months;
    }

    public static function generate(string $total, string $start, string $monthsList): array
    {
        $months = self::months($monthsList);
        $remaining = BigDecimal::of($total)->toScale(2);
        if ($remaining->isNegativeOrZero()) {
            throw ValidationException::withMessages(['total' => 'The amount to schedule must be greater than zero.']);
        }
        $installment = $remaining->dividedBy(count($months), 2, RoundingMode::HalfUp);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        $rows = [];
        foreach ($months as $index => $month) {
            $amount = $index === count($months) - 1 || $remaining->isLessThan($installment) ? $remaining : $installment;
            $remaining = $remaining->minus($amount);
            // Preserve the existing Date.setMonth overflow behavior at month ends.
            $rows[] = [
                'paymentduedate' => $date->addMonthsWithOverflow($month - 1)->format('Y-m-d'),
                'paymentamount' => (string) $amount,
                'paymentnotes' => 'remaining balance: '.$remaining,
            ];
        }

        return $rows;
    }
}
