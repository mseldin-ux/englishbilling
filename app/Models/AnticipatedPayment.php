<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnticipatedPayment extends BillingModel
{
    protected $table = 'anticipatedpayment';

    protected function casts(): array
    {
        return ['paymentamount' => 'decimal:2', 'paymentduedate' => 'immutable_datetime',
            'paymentinvoicedate' => 'immutable_datetime', 'paymentpercent' => 'float'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudentPaymentPlan::class, 'studentpaymentplanid_id');
    }
}
