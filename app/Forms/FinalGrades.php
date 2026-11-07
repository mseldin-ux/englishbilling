<?php

namespace App\Forms;

use App\Billing\PdfRenderer;
use App\Models\FinalGrade;
use App\Models\GradedItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FinalGrades
{
    public const TYPE = 'final-grades';

    public const TITLE = 'Final Grades';

    public const LABELS = [
        'studentid' => 'Adult Learner',
        'program_id' => 'Program',
        'gradenotes' => 'Notes',
        'gradername' => 'Level director signature (type your name)',
        'gradedate' => 'Date',
    ];

    /**
     * Programs that have graded items, plus the sheet's own program.
     */
    public static function programs(?int $current = null): Collection
    {
        return Program::query()->whereIn('id', GradedItem::query()->select('program_id'))
            ->when($current, fn ($query) => $query->orWhere('id', $current))
            ->orderBy('programdesc')->get(['id', 'program', 'programdesc', 'shortname']);
    }

    /**
     * Graded items in display order for each program, keyed by program id. Items already graded
     * on a sheet stay on that sheet even if they are later moved to another program.
     */
    public static function items(array $programs, ?FinalGrade $sheet = null): Collection
    {
        $saved = $sheet?->exists ? $sheet->items()->pluck('gradeditemid')->all() : [];

        return GradedItem::query()->whereIn('program_id', $programs)->when($saved !== [], fn ($query) => $query->orWhereIn('id', $saved))
            ->orderByRaw('itemorder IS NULL')->orderBy('itemorder')->orderBy('id')->get()
            ->groupBy(fn (GradedItem $item) => $sheet?->exists ? $sheet->program_id : $item->program_id);
    }

    /**
     * @return array{studentid: int, program_id: int, gradername: ?string, gradedate: ?string, gradenotes: ?string, grades: array<int, ?string>}
     */
    public static function validate(array $input, bool $submitting, ?FinalGrade $sheet = null): array
    {
        if ($sheet?->exists) {
            $input['studentid'] = $sheet->studentid;
            $input['program_id'] = $sheet->program_id;
        }
        foreach (['gradername', 'gradedate', 'gradenotes'] as $field) {
            $input[$field] = is_string($input[$field] ?? null) && trim($input[$field]) !== '' ? trim($input[$field]) : null;
        }
        $grades = is_array($input['grades'] ?? null) ? $input['grades'] : [];
        $required = $submitting ? 'required' : 'nullable';
        $values = [];
        $validator = Validator::make($input, [
            'studentid' => ['required', 'integer', 'exists:students,id'],
            'program_id' => ['required', 'integer', Rule::in(self::programs($sheet?->program_id)->pluck('id')->all())],
            'gradername' => [$required, 'string', 'max:255'],
            'gradedate' => [$required, 'date_format:Y-m-d', 'after:1900-01-01', 'before:2200-01-01'],
            'gradenotes' => ['nullable', 'string', 'max:65000'],
        ], [
            'studentid.required' => 'Select the Adult Learner.',
            'program_id.required' => 'Select the program.',
            'program_id.in' => 'Select a program that has graded items.',
            'gradername.required' => 'Type your name to sign the grade sheet.',
            'gradedate.required' => 'Enter the date.',
        ], self::LABELS);
        // Grade errors are reported alongside header errors so directors see everything missing at once.
        $validator->after(function ($validator) use ($input, $grades, $submitting, $sheet, &$values) {
            if ($validator->errors()->has('program_id')) {
                return;
            }
            $program = (int) $input['program_id'];
            foreach (self::items([$program], $sheet)->get($program, collect()) as $item) {
                $value = $grades[$item->id] ?? null;
                $value = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
                if ($value !== null && mb_strlen($value) > 64) {
                    $validator->errors()->add("grades.{$item->id}", "The grade for {$item->gradeitem} must be 64 characters or fewer.");
                } elseif ($value === null && $submitting) {
                    $validator->errors()->add("grades.{$item->id}", "Enter a grade for {$item->gradeitem}.");
                }
                $values[$item->id] = $value;
            }
        });
        $data = $validator->validate();

        return [...$data, 'studentid' => (int) $data['studentid'], 'program_id' => (int) $data['program_id'], 'grades' => $values];
    }

    /**
     * Point-in-time copy of a sheet for history, including names as they were when saved.
     */
    /** @return array{name: string, data: string, mime: string} the grade sheet (final transcript) as a PDF */
    public static function pdf(FinalGrade $sheet): array
    {
        $snapshot = self::snapshot($sheet);
        $items = self::items([$sheet->program_id], $sheet)->get($sheet->program_id, collect());
        $name = 'final-grades-'.$sheet->id.'-'.preg_replace('/[^A-Za-z0-9]+/', '-', $snapshot['student_name'] ?? 'learner');

        return ['name' => trim($name, '-').'.pdf', 'mime' => 'application/pdf', 'data' => app(PdfRenderer::class)->renderView('forms.grades-pdf', [
            'sheet' => $sheet, 'values' => $snapshot, 'items' => $items,
            'grades' => $sheet->items()->pluck('grade', 'gradeditemid')->all(), 'signer' => $sheet->signer])];
    }

    public static function snapshot(FinalGrade $sheet): array
    {
        $student = Student::find($sheet->studentid);
        $items = GradedItem::query()->whereIn('id', $sheet->items()->pluck('gradeditemid'))->get()->keyBy('id');
        $grades = [];
        foreach ($sheet->items()->orderBy('id')->get() as $row) {
            $item = $items->get($row->gradeditemid);
            $grades[(string) $row->gradeditemid] = ['item' => $item?->gradeitem, 'clockhours' => $item?->clockhours, 'grade' => $row->grade];
        }

        return [
            'form_type' => self::TYPE,
            'studentid' => $sheet->studentid,
            'student_name' => $student?->displayName(),
            'student_cohort' => $student?->cohort,
            'program_id' => $sheet->program_id,
            'program_name' => Program::find($sheet->program_id)?->programdesc,
            'gradername' => $sheet->gradername,
            'gradedate' => $sheet->gradedate,
            'gradenotes' => $sheet->gradenotes,
            'grades' => $grades,
            'signed_by' => $sheet->signed_by,
            'signed_at' => $sheet->signed_at?->toIso8601String(),
        ];
    }

    /**
     * Applies validated values. Returns false when nothing would change.
     */
    public static function apply(FinalGrade $sheet, array $data, bool $submitted, User $user): bool
    {
        $current = $sheet->exists ? $sheet->items()->pluck('grade', 'gradeditemid')->all() : [];
        $changed = ! $sheet->exists || $sheet->status !== ($submitted ? 'submitted' : 'draft');
        foreach (['gradername', 'gradedate', 'gradenotes'] as $field) {
            $changed = $changed || $sheet->{$field} !== $data[$field];
        }
        foreach ($data['grades'] as $item => $grade) {
            $changed = $changed || ! array_key_exists($item, $current) || $current[$item] !== $grade;
        }
        if (! $changed) {
            return false;
        }
        $resigned = $sheet->gradername !== $data['gradername'];
        $sheet->forceFill([
            'studentid' => $data['studentid'],
            'program_id' => $data['program_id'],
            'gradername' => $data['gradername'],
            'gradedate' => $data['gradedate'],
            'gradenotes' => $data['gradenotes'],
            'status' => $submitted ? 'submitted' : 'draft',
            'updated_by' => $user->id,
        ]);
        if ($submitted && ($sheet->signed_at === null || $resigned)) {
            $sheet->signed_by = $user->id;
            $sheet->signed_at = now();
        }
        if ($submitted && $sheet->submitted_at === null) {
            $sheet->submitted_at = now();
            $sheet->submitted_by = $user->id;
        }
        if (! $sheet->exists) {
            $sheet->created_by = $user->id;
        }
        $sheet->save();
        foreach ($data['grades'] as $item => $grade) {
            $sheet->items()->updateOrCreate(['gradeditemid' => $item], ['studentid' => $sheet->studentid, 'grade' => $grade]);
        }

        return true;
    }
}
