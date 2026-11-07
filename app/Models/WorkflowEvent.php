<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['started' => 'Workflow started', 'reached' => 'Stage reached', 'completed' => 'Stage completed',
        'sent' => 'Email sent', 'resent' => 'Email sent again', 'skipped' => 'Stage skipped', 'failed' => 'Stage failed',
        'scheduled' => 'Scheduled', 'answered-no' => 'Answered No', 'paused' => 'Paused', 'resumed' => 'Resumed',
        'sent-back' => 'Sent back', 'cancelled' => 'Workflow cancelled', 'finished' => 'Workflow complete',
        'uploaded' => 'File uploaded', 'updated' => 'Updated', 'retry' => 'Retry scheduled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
