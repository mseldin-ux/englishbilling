<?php

namespace Tests\Unit;

use App\Billing\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_cents_use_explicit_half_up_rounding_without_float_arithmetic(): void
    {
        $this->assertSame('1.01', Money::cents('1.005'));
        $this->assertSame('-1.01', Money::cents('-1.005'));
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('71900.00', Money::cents('71900'));
    }
}
