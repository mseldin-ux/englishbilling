<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradedItem extends Model
{
    protected $table = 'gradeditems';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['program_id' => 'integer', 'itemorder' => 'integer', 'clockhours' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
