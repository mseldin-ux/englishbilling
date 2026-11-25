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

    public function isHistorical(): bool
    {
        $date = $this->processeddate ?? $this->transactiondate;

        return $date === null || $date->toDateString() < today()->subDays(30)->toDateString();
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
