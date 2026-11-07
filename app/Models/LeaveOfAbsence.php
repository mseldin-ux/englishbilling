<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveOfAbsence extends BillingModel
{
    protected $table = 'loas';

    protected function casts(): array
    {
        return ['loa_start' => 'immutable_datetime', 'loa_end' => 'immutable_datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
