<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends BillingModel
{
    public const SHARED_FIELDS = ['type', 'shortname', 'director_team_email',
        'course_completion_survey_url', 'employer_survey_url', 'employment_form_url'];

    protected $table = 'programs';

    public function certificateProgram(): BelongsTo
    {
        return $this->belongsTo(self::class, 'certificate_program_id');
    }

    private function sharedValue(string $field, mixed $value): mixed
    {
        if (! $this->certificate_program_id) {
            return $value;
        }
        $certificate = $this->getRelationValue('certificateProgram') ?? $this->certificateProgram()->firstOrFail();

        return $certificate->getRawOriginal($field);
    }

    protected function type(): Attribute
    {
        return Attribute::get(fn ($value) => $this->sharedValue('type', $value));
    }

    protected function shortname(): Attribute
    {
        return Attribute::get(fn ($value) => $this->sharedValue('shortname', $value));
    }

    protected function directorTeamEmail(): Attribute
    {
        return Attribute::get(fn ($value) => $this->sharedValue('director_team_email', $value));
    }

    protected function courseCompletionSurveyUrl(): Attribute
    {
        return Attribute::get(fn ($value) => $this->sharedValue('course_completion_survey_url', $value));
    }

    protected function employerSurveyUrl(): Attribute
    {
        return Attribute::get(fn ($value) => $this->sharedValue('employer_survey_url', $value));
    }

    protected function employmentFormUrl(): Attribute
    {
        return Attribute::get(fn ($value) => $this->sharedValue('employment_form_url', $value));
    }

    protected function casts(): array
    {
        return ['staged' => 'boolean', 'certleading' => 'boolean', 'nextprog' => 'integer',
            'certificate_program_id' => 'integer', 'servicedays' => 'integer'];
    }

    public function isCertificateLeading(): bool
    {
        return $this->certleading ?? $this->type === 'cert';
    }

    public function scopeCertificatePrograms(Builder $query): void
    {
        $query->where(fn ($q) => $q->where('certleading', true)
            ->orWhere(fn ($q) => $q->whereNull('certleading')->where('type', 'cert')))
            ->where(fn ($q) => $q->whereNull('staged')->orWhere('staged', false));
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
