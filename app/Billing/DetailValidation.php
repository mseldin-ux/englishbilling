<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DetailValidation
{
    public static function validate(Student $student, array $changes): void
    {
        if (! $student->graduated && ! empty($changes['graduated'])
            && ! ($changes['hasenrolled'] ?? $student->hasenrolled)) {
            throw ValidationException::withMessages(['graduated' => 'A learner must be enrolled before being marked graduated.']);
        }
        $fields = ['hasenrolled', 'program_id', 'start_date', 'graddate', 'actualdategrad',
            'inactive', 'inactive_date', 'loa', 'loa_date', 'wd', 'wd_date', 'sus', 'sus_date',
            'onextension', 'extension_start_date', 'in_group', 'group_name', 'sponsored', 'sponsor_contact', 'sponsor_email'];
        $values = [];
        foreach ($fields as $field) {
            $raw = $student->getRawOriginal($field);
            $values[$field] = array_key_exists($field, $changes) ? $changes[$field] : $raw;
            if (in_array($field, ['start_date', 'graddate', 'actualdategrad', 'inactive_date', 'loa_date', 'wd_date', 'sus_date', 'extension_start_date'], true)
                && $values[$field] !== null && $values[$field] !== '') {
                $values[$field] = substr((string) $values[$field], 0, 10);
            }
        }
        Validator::make($values, [
            'program_id' => [Rule::requiredIf((bool) $values['hasenrolled'])],
            'start_date' => [Rule::requiredIf((bool) $values['hasenrolled']), 'required_with:graddate,actualdategrad,inactive_date,loa_date,wd_date,sus_date,extension_start_date', 'nullable', 'date_format:Y-m-d'],
            'graddate' => [Rule::requiredIf((bool) $values['hasenrolled']), 'nullable', 'date_format:Y-m-d', 'after:start_date'],
            'actualdategrad' => ['nullable', 'date_format:Y-m-d', 'after:start_date'],
            'inactive_date' => [Rule::requiredIf((bool) $values['inactive']), 'nullable', 'date_format:Y-m-d', 'after:start_date'],
            'loa_date' => [Rule::requiredIf((bool) $values['loa']), 'nullable', 'date_format:Y-m-d', 'after:start_date'],
            'wd_date' => [Rule::requiredIf((bool) $values['wd']), 'nullable', 'date_format:Y-m-d', 'after:start_date'],
            'sus_date' => [Rule::requiredIf((bool) $values['sus']), 'nullable', 'date_format:Y-m-d', 'after:start_date'],
            'extension_start_date' => [Rule::requiredIf((bool) $values['onextension']), 'nullable', 'date_format:Y-m-d', 'after:start_date'],
            'group_name' => [Rule::requiredIf((bool) $values['in_group'])],
            'sponsor_contact' => [Rule::requiredIf((bool) $values['sponsored'])],
            'sponsor_email' => [Rule::requiredIf((bool) $values['sponsored'])],
        ], [], [
            'program_id' => 'Program', 'start_date' => 'Start Date', 'graddate' => 'Expected Grad Date',
            'actualdategrad' => 'Actual Grad Date', 'loa_date' => 'Leave of absence date',
            'wd_date' => 'Withdrawal date', 'sus_date' => 'Suspension date',
            'extension_start_date' => 'Extension date', 'group_name' => 'Group name',
            'sponsor_contact' => 'Sponsor contact', 'sponsor_email' => 'Sponsor email',
        ])->validate();
    }
}
