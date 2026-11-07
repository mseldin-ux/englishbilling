<?php

namespace Tests\Unit;

use App\Billing\PaymentSchedule;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentScheduleTest extends TestCase
{
    public function test_small_total_never_allocates_more_than_available(): void
    {
        $rows = PaymentSchedule::generate('0.02', '2026-01-01', '1,2,3,4');
        $this->assertSame(['0.01', '0.01', '0.00', '0.00'], array_column($rows, 'paymentamount'));
    }

    public function test_invalid_or_excessive_month_sequence_is_explicit(): void
    {
        foreach (['0', '1,1', '1,3,2', '2,3', '1,121', '1,,2', '1,2,bad'] as $list) {
            try {
                PaymentSchedule::months($list);
                $this->fail('Accepted '.$list);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('monthslist', $exception->errors());
            }
        }
    }
}
