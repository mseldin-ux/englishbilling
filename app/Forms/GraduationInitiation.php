<?php

namespace App\Forms;

use App\Models\FormData;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class GraduationInitiation
{
    public const TYPE = 'graduation-initiation';

    public const TITLE = 'Adult Learner Graduation Initiation';

    public const NOTICE = 'Certificates will be ordered on the 10th of each month. Please fill out this form, and the Final Grade form by the 10th of the month for the certificate to be ordered.';

    public const QUESTIONS = [
        'program_complete' => 'Is the program complete?',
        'coursework_complete' => 'Has the coursework been completed and the grade form received?',
        'residency_complete' => 'Has the Adult Learner completed a residency?',
    ];

    public const LABELS = [
        'student_id' => 'Adult Learner',
        'program_id' => 'Program',
        ...self::QUESTIONS,
        'residency_location' => 'Residency location',
        'residency_date' => 'Residency date',
        'practicum_passed_date' => 'Date the practicum evaluations were complete and the practicum was passed',
        'graduation_date' => 'Adult Learner’s graduation date',
        'instructor_email' => 'Instructor email (copied on the graduate’s final congratulations email)',
        'director_signature' => 'Level director signature (type your name)',
    ];

    public static function programs(?int $current = null)
    {
        return Program::where('type', 'cert')->when($current, fn ($query) => $query->orWhere('id', $current))
            ->orderBy('programdesc')->get(['id', 'program', 'programdesc', 'shortname']);
    }

    /**
     * Drafts need only a learner; submission requires every answer, all Yes, and ordered dates.
     */
    public static function validate(array $input, bool $submitting, ?FormData $form = null): array
    {
        foreach (array_keys(self::QUESTIONS) as $question) {
            $input[$question] = filter_var($input[$question] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $required = $submitting ? 'required' : 'nullable';
        // A program later removed from the certificate list stays valid on forms that already use it.
        $program = Rule::exists('programs', 'id')->where(function ($query) use ($form) {
            $query->where('type', 'cert');
            if ($form?->program_id) {
                $query->orWhere('id', $form->program_id);
            }
        });
        $date = [$required, 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2200-12-31'];
        $rules = [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'program_id' => [$required, 'integer', $program],
            'residency_location' => [$required, 'string', 'max:255'],
            'residency_date' => $date,
            'practicum_passed_date' => $date,
            'graduation_date' => $date,
            'director_signature' => [$required, 'string', 'max:255'],
            'instructor_email' => ['nullable', 'string', 'max:255', 'email:rfc'],
        ];
        foreach (array_keys(self::QUESTIONS) as $question) {
            $rules[$question] = $submitting ? ['accepted'] : ['boolean'];
        }
        if ($submitting) {
            $rules['residency_date'][] = 'before:graduation_date';
            $rules['practicum_passed_date'][] = 'before:graduation_date';
        }
        $messages = ['residency_date.before' => 'The residency must occur before the graduation date.',
            'practicum_passed_date.before' => 'The practicum must be passed before the graduation date.',
            'program_id.exists' => 'Choose a certificate program.'];
        foreach (self::QUESTIONS as $question => $label) {
            $messages[$question.'.accepted'] = "“{$label}” must be answered Yes before the form can be submitted.";
        }

        $data = Validator::make($input, $rules, $messages, self::LABELS)->validate();
        $result = [];
        foreach (array_keys(self::LABELS) as $field) {
            $value = $data[$field] ?? null;
            $result[$field] = match (true) {
                array_key_exists($field, self::QUESTIONS) => (bool) $value,
                in_array($field, ['student_id', 'program_id'], true) => $value === null ? null : (int) $value,
                is_string($value) => trim($value) === '' ? null : trim($value),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * The JSON document stored in formsdata.data. Learner and program names are captured as saved.
     */
    public static function document(array $data, ?array $previous, bool $submitted, User $user): array
    {
        $student = Student::findOrFail($data['student_id']);
        $program = $data['program_id'] ? Program::find($data['program_id']) : null;
        $document = [
            'form_type' => self::TYPE,
            'form_title' => self::TITLE,
            'student_id' => $student->id,
            'student_name' => $student->displayName(),
            'student_cohort' => $student->cohort,
            'program_id' => $program?->id,
            'program_name' => $program?->programdesc,
            ...array_diff_key($data, ['student_id' => 1, 'program_id' => 1]),
            'signed_by' => $previous['signed_by'] ?? null,
            'signed_at' => $previous['signed_at'] ?? null,
        ];
        $resigned = ($previous['director_signature'] ?? null) !== $data['director_signature'];
        if ($submitted && ($document['signed_at'] === null || $resigned)) {
            $document['signed_by'] = ['user_id' => $user->id, 'username' => $user->username, 'name' => $user->name];
            $document['signed_at'] = now()->toIso8601String();
        }

        return $document;
    }
}
