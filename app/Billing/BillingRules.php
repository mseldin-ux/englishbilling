<?php

namespace App\Billing;

final class BillingRules
{
    public const TYPES = ['Credit', 'Declined Fee', 'Discount', 'Fee', 'Fee Adjustment',
        'Note', 'Payment', 'Pmt Adjustment', 'Refund', 'Write off'];

    public static function money(): array
    {
        return ['regex:/^-?\d{1,13}(?:\.\d{1,2})?$/'];
    }
}
