<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    public const STATUSES = ['active' => 'In progress', 'paused' => 'Paused', 'completed' => 'Complete', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subject_id' => 'integer', 'student_id' => 'integer', 'program_id' => 'integer',
            'paused_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowEvent::class)->orderBy('id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pauser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CourseCompletionInvitation::class)->orderBy('id');
    }

    public function step(string $key): ?WorkflowStep
    {
        return WorkflowStep::where('workflow_run_id', $this->id)->where('step', $key)->first();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
