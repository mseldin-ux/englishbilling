<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class StudentEdits
{
    public static function update(Student $student, array $data, string $version): Student
    {
        return DB::transaction(function () use ($student, $data, $version) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            RecordVersion::check($locked, $version);
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
            $locked->forceFill($data)->save();

            return $locked->fresh();
        });
    }
}
