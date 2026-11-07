<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormData extends Model
{
    protected $table = 'formsdata';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'submitted_at' => 'immutable_datetime', 'revision' => 'integer',
            'student_id' => 'integer', 'program_id' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
