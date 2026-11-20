<?php

namespace App\Forms;

use App\Models\FinalGrade;
use App\Models\FormData;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Validation\ValidationException;

final class ProgramEnrollment
{
    public static function current(?Student $student, ?int $program): ?int
    {
        $enrollment = $student?->currentEnrollment;

        return $enrollment && $enrollment->mode !== 'legacy' && (int) $enrollment->program_id === $program
            ? $enrollment->id : null;
    }

    public static function scope(array $input, int $student, ?int $program, FinalGrade|FormData|null $saved = null): array
    {
        $savedStudent = $saved instanceof FinalGrade ? $saved->studentid : $saved?->student_id;
        if ($saved?->exists && (int) $savedStudent === $student && (int) $saved->program_id === (int) $program) {
            return ['enrollment_id' => $saved->enrollment_id, 'enrollment_key' => $saved->enrollment_key];
        }
        $id = array_key_exists('enrollment_id', $input) ? $input['enrollment_id']
            : self::current(Student::find($student), $program);
        if ($id === null || $id === '') {
            return ['enrollment_id' => null, 'enrollment_key' => 'legacy'];
        }
        if (! is_scalar($id) || ! ctype_digit((string) $id)
            || ! StudentEnrollment::whereKey($id)->where('student_id', $student)->where('program_id', $program)
                ->where('mode', '!=', 'legacy')->exists()) {
            throw ValidationException::withMessages(['enrollment_id' => 'Choose an enrollment belonging to this learner and program.']);
        }

        $id = (int) $id;

        return ['enrollment_id' => $id, 'enrollment_key' => 'enrollment:'.$id];
    }

    public static function options(?Student $student): array
    {
        return $student?->enrollments->where('mode', '!=', 'legacy')->map(fn ($enrollment) => [
            'id' => $enrollment->id, 'program_id' => $enrollment->program_id,
            'label' => $enrollment->label(), 'current' => $student->current_enrollment_id === $enrollment->id,
        ])->values()->all() ?? [];
    }
}
