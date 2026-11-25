<?php

namespace App\Billing;

use App\Models\Program;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class StudentEdits
{
    public static function update(Student $student, array $data, string $version, bool $validateDetail = false): Student
    {
        return DB::transaction(function () use ($student, $data, $version, $validateDetail) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            RecordVersion::check($locked, $version);
            if ($validateDetail) {
                DetailValidation::validate($locked, $data);
            }
            foreach ($data as $field => $value) {
                $current = $locked->getAttribute($field);
                if ($current instanceof \DateTimeInterface && $current->format('Y-m-d') === $value) {
                    unset($data[$field]);
                } elseif ($field === 'access_change_date' && $current instanceof \DateTimeInterface
                    && $current->format('Y-m-d\TH:i:s') === (strlen($value ?? '') === 16 ? $value.':00' : $value)) {
                    unset($data[$field]);
                } elseif ($value === null && $current === '') {
                    unset($data[$field]);
                }
            }
            $initialize = ! $locked->current_enrollment_id && ! empty($data['program_id'])
                && (string) $locked->program_id !== (string) $data['program_id']
                && Program::find($data['program_id'])?->staged;
            if ($initialize && ($locked->transactions()->exists() || $locked->invoices()->exists())) {
                Enrollments::error('Use Start new program enrollment below to preserve this learner\'s existing billing history when changing to a staged program.');
            }
            $before = $locked->getRawOriginal();
            $locked->forceFill($data)->save();
            if ($initialize) {
                Enrollments::initialize($locked);
            } else {
                Enrollments::syncStatus($locked, $before);
            }

            return $locked->fresh();
        });
    }
}
