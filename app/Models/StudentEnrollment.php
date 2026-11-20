<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentEnrollment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['legacy_context' => 'array', 'started_on' => 'immutable_date', 'ended_on' => 'immutable_date'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(StudentStageEnrollment::class, 'enrollment_id')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EnrollmentEvent::class, 'enrollment_id')->orderBy('id');
    }

    public function label(): string
    {
        return ($this->program?->program ?? 'Legacy program').' — '.($this->started_on?->format('Y-m-d') ?? 'unknown start').' ('.$this->mode.')';
    }
}
