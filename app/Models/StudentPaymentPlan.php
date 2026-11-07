<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentPaymentPlan extends BillingModel
{
    protected $table = 'studentpaymentplans';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AnticipatedPayment::class, 'studentpaymentplanid_id');
    }
}
