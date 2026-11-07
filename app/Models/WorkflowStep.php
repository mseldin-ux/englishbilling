<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    public const STATUSES = ['upcoming' => 'Not reached', 'pending' => 'Starting', 'sending' => 'In progress',
        'waiting' => 'Waiting', 'scheduled' => 'Scheduled', 'done' => 'Done', 'failed' => 'Failed',
        'skipped' => 'Skipped', 'cancelled' => 'Cancelled'];

    /** Statuses that let the run move past the stage. */
    public const FINISHED = ['done', 'skipped', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'data' => 'array', 'attempts' => 'integer', 'position' => 'integer',
            'available_at' => 'immutable_datetime', 'reached_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
