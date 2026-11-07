<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function cents(string|int|float $amount): string
    {
        return (string) BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);
    }

    public static function add(string $left, string $right): string
    {
        return (string) BigDecimal::of($left)->plus($right)->toScale(2);
    }
}
