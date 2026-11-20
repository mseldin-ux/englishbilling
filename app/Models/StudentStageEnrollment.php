<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentStageEnrollment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_on' => 'immutable_date', 'completed_on' => 'immutable_date', 'ended_on' => 'immutable_date'];
    }
}
