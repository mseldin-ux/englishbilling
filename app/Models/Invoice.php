<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends BillingModel
{
    public const METADATA_COLUMNS = ['id', 'student_id', 'datesent', 'nextdate', 'amount', 'notes',
        'invoicereference', 'batchid', 'user_sent', 'urgent_message'];

    protected $table = 'invoices';

    protected $hidden = ['invoice_file'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'datesent' => 'immutable_datetime', 'nextdate' => 'immutable_datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(StudentTransaction::class);
    }
}
