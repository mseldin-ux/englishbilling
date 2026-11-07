<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinalGrade extends Model
{
    protected $table = 'finalgrades';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['studentid' => 'integer', 'program_id' => 'integer', 'revision' => 'integer',
            'signed_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'studentid');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinalGradeItem::class, 'finalgradeid');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
