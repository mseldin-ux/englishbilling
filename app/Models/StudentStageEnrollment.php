<?php

namespace App\Models;

use App\Billing\Enrollments;
use Illuminate\Database\Eloquent\Model;

class StudentStageEnrollment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_on' => 'immutable_date', 'completed_on' => 'immutable_date', 'ended_on' => 'immutable_date',
            'program_id' => 'integer', 'next_program_id' => 'integer'];
    }

    public function isFinal(): bool
    {
        return $this->program_id !== null ? ! $this->next_program_id : $this->stage === 3;
    }

    public function label(): string
    {
        return $this->program_name ?? Enrollments::STAGES[$this->stage];
    }
}
