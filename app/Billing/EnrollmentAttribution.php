<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EnrollmentAttribution
{
    public static function rules(bool $transactions = false): array
    {
        return ['billing_period' => ['sometimes', 'nullable', 'regex:/^\d+:\d*$/'],
            ...($transactions ? ['recognition' => ['sometimes', 'nullable', 'in:service,application']] : [])];
    }

    public static function current(Student $student): array
    {
        $enrollment = $student->currentEnrollment;

        return ['enrollment_id' => $enrollment?->id,
            'stage_enrollment_id' => $enrollment?->mode === 'staged' ? $enrollment->stages->last()?->id : null];
    }

    public static function options(Student $student): array
    {
        $options = [];
        foreach ($student->loadMissing('enrollments.program', 'enrollments.stages')->enrollments as $enrollment) {
            if ($enrollment->mode === 'staged') {
                foreach ($enrollment->stages as $stage) {
                    $options[$enrollment->id.':'.$stage->id] = $enrollment->label().' / Stage '.$stage->stage.' — '.$stage->started_on->format('Y-m-d');
                }
            } else {
                $options[$enrollment->id.':'] = $enrollment->label();
            }
        }

        return $options;
    }

    public static function data(Student $student, array $data, ?Model $record = null): array
    {
        if ($record && ! array_key_exists('billing_period', $data)) {
            return $data;
        }
        $explicit = array_key_exists('billing_period', $data);
        $period = $data['billing_period'] ?? null;
        unset($data['billing_period']);
        if ($period) {
            [$id, $stageId] = explode(':', $period);
            $enrollment = $student->enrollments()->find($id);
            if (! $enrollment || ($enrollment->mode === 'staged'
                ? ! $enrollment->stages()->whereKey($stageId)->exists() : $stageId !== '')) {
                throw ValidationException::withMessages(['billing_period' => 'Choose an enrollment/stage belonging to this learner.']);
            }

            return [...$data, 'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stageId === '' ? null : (int) $stageId];
        }
        if ($student->current_enrollment_id && $explicit) {
            throw ValidationException::withMessages(['billing_period' => 'Choose the program/stage for this entry.']);
        }

        return [...$data, ...self::current($student)];
    }

    public static function key(?Model $record): string
    {
        return $record?->enrollment_id ? $record->enrollment_id.':'.$record->stage_enrollment_id : '';
    }
}
