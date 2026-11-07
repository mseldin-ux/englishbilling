<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalGradeItem extends Model
{
    protected $table = 'finalgradesitems';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['finalgradeid' => 'integer', 'studentid' => 'integer', 'gradeditemid' => 'integer'];
    }

    public function gradedItem(): BelongsTo
    {
        return $this->belongsTo(GradedItem::class, 'gradeditemid');
    }
}
