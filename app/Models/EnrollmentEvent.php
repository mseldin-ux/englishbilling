<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'effective_on' => 'immutable_date', 'voided_at' => 'immutable_datetime'];
    }
}
