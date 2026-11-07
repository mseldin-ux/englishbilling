<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCompletion extends Model
{
    public const EMPLOYMENT = [
        'montessori' => 'Employed in the Montessori Field',
        'education' => 'Employed in the Education Field',
        'other' => 'Employed in another field',
        'not_employed' => 'Not employed',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'replaces_previous' => 'boolean', 'address_in_us' => 'boolean',
            'survey_completed' => 'boolean', 'student_id' => 'integer', 'invitation_id' => 'integer'];
    }

    /** Older submissions were made before the question existed. */
    public function employmentLabel(): string
    {
        return self::EMPLOYMENT[$this->employment_status] ?? 'Not asked';
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CourseCompletionInvitation::class, 'invitation_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
