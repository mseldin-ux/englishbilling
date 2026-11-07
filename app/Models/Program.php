<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends BillingModel
{
    protected $table = 'programs';

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
