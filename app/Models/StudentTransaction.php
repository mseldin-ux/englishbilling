<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTransaction extends BillingModel
{
    protected $table = 'studenttransactions';

    protected function casts(): array
    {
        return ['transactionamount' => 'decimal:2',
            'transactiondate' => 'immutable_datetime', 'processeddate' => 'immutable_datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
