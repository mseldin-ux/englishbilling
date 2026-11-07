<?php

namespace App\Http\Controllers;

use App\Forms\FinalGrades;
use App\Forms\FormLocks;
use App\Models\FinalGrade;
use App\Models\Student;
use App\Models\User;
use App\Workflows\Workflows;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FinalGradesController extends Controller
{
    private const KIND = 'finalgrades';

    public function create(Request $request)
    {
        $student = $request->integer('student') ? Student::find($request->integer('student')) : null;
        $programs = FinalGrades::programs();
        $values = ['studentid' => $student?->id, 'program_id' => null, 'gradername' => null,
            'gradedate' => now()->format('Y-m-d'), 'gradenotes' => null];
        if ($programs->contains('id', $request->integer('program'))) {
            $values['program_id'] = $request->integer('program');
        } elseif ($student && $programs->contains('id', $student->program_id)) {
            $values['program_id'] = $student->program_id;
        }

        return $this->page(new FinalGrade(['status' => 'draft', 'revision' => 1]), $values, [], null, $programs);
    }

    /**
     * Grade sheets that already exist for a learner, newest first.
     */
    public function existing(Request $request)
    {
        $student = $request->validate(['student' => ['required', 'integer']])['student'];

        return response()->json(['sheets' => $this->sheetsFor((int) $student)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $submitting = $this->submitting($request);
        $data = FinalGrades::validate($request->all(), $submitting);
        try {
            $sheet = DB::transaction(function () use ($request, $data, $submitting) {
                $this->rejectDuplicate($data['studentid'], $data['program_id']);
                $sheet = new FinalGrade(['revision' => 1]);
                FinalGrades::apply($sheet, $data, $submitting, $request->user());
                $this->history($sheet, $submitting ? 'submitted' : 'created', $request->user());
                if ($submitting) {
                    Workflows::signal($sheet->studentid);
                }

                return $sheet;
            });
        } catch (QueryException $exception) {
            // A concurrent save for the same learner and program loses the unique-key race.
            if (FinalGrade::where('studentid', $data['studentid'])->where('program_id', $data['program_id'])->exists()) {
                $this->rejectDuplicate($data['studentid'], $data['program_id']);
            }
            throw $exception;
        }

        return redirect()->route('forms.grades.edit', $sheet)->with('status', $submitting ? 'Grade sheet submitted.' : 'Draft saved.');
    }

    public function edit(Request $request, FinalGrade $sheet)
    {
        $lock = FormLocks::claim($request, self::KIND, $sheet->id);
        $sheet->refresh();
        $values = ['studentid' => $sheet->studentid, 'program_id' => $sheet->program_id, 'gradername' => $sheet->gradername,
            'gradedate' => $sheet->gradedate, 'gradenotes' => $sheet->gradenotes];

        return $this->page($sheet, $values, $sheet->items()->pluck('grade', 'gradeditemid')->all(), $lock,
            FinalGrades::programs($sheet->program_id)->where('id', $sheet->program_id)->values());
    }

    public function update(Request $request, FinalGrade $sheet)
    {
        $data = FinalGrades::validate($request->all(), $this->submitting($request) || $sheet->status === 'submitted', $sheet);
        $revision = (int) $request->validate(['revision' => ['required', 'integer']])['revision'];
        $message = DB::transaction(function () use ($request, $sheet, $data, $revision) {
            $sheet = FinalGrade::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            FormLocks::requireOwner($request, self::KIND, $sheet->id);
            if ($sheet->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'This grade sheet changed after you opened it. Reload to see the current version; no changes were saved.']);
            }
            $wasSubmitted = $sheet->status === 'submitted';
            $sheet->revision++;
            if (! FinalGrades::apply($sheet, $data, $wasSubmitted || $this->submitting($request), $request->user())) {
                return 'No changes to save.';
            }
            $this->history($sheet, ! $wasSubmitted && $sheet->status === 'submitted' ? 'submitted' : 'updated', $request->user());
            if ($sheet->status === 'submitted') {
                Workflows::signal($sheet->studentid);
            }

            return $sheet->status === 'submitted' && ! $wasSubmitted ? 'Grade sheet submitted.' : 'Changes saved.';
        });

        return redirect()->route('forms.grades.edit', $sheet)->with('status', $message);
    }

    public function destroy(Request $request, FinalGrade $sheet)
    {
        $request->validate(['confirm' => ['accepted']]);
        DB::transaction(function () use ($request, $sheet) {
            $sheet = FinalGrade::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            FormLocks::requireOwner($request, self::KIND, $sheet->id);
            $this->history($sheet, 'deleted', $request->user());
            FormLocks::forget(self::KIND, $sheet->id);
            $sheet->items()->delete();
            $sheet->delete();
        });
        Log::notice('Final grade sheet deleted.', ['admin_id' => $request->user()->id, 'finalgradeid' => $sheet->id]);

        return redirect()->route('forms.index')->with('status', "Grade sheet {$sheet->id} deleted. Its history is retained.");
    }

    public function pdf(FinalGrade $sheet)
    {
        $pdf = FinalGrades::pdf($sheet);

        return response($pdf['data'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf['name'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function status(Request $request, FinalGrade $sheet)
    {
        return response()->json(FormLocks::state($request, self::KIND, $sheet->id, (string) $request->header('X-Student-Lock', '')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function release(Request $request, FinalGrade $sheet)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        FormLocks::release($request, self::KIND, $sheet->id, $data['token']);

        return response()->json(['message' => 'Editing lock released.']);
    }

    private function page(FinalGrade $sheet, array $saved, array $grades, ?array $lock, $programs)
    {
        $values = [];
        foreach ($saved as $field => $value) {
            $values[$field] = old($field, $value);
        }
        $grades = session()->hasOldInput() ? (array) old('grades', []) : $grades;
        $learner = $values['studentid'] ? Student::find($values['studentid']) : null;
        $history = $sheet->exists ? DB::table('finalgrades_history')->where('finalgradeid', $sheet->id)->orderBy('id')->get() : collect();

        return view('forms.grades', ['sheet' => $sheet, 'values' => $values, 'grades' => $grades, 'learner' => $learner,
            'programs' => $programs, 'items' => FinalGrades::items($programs->pluck('id')->all(), $sheet), 'editLock' => $lock,
            'history' => $this->describe($history), 'existing' => $learner ? $this->sheetsFor($learner->id, $sheet->id) : [],
            'signer' => $sheet->signer]);
    }

    private function sheetsFor(int $student, ?int $except = null): array
    {
        return FinalGrade::query()->with('program:id,programdesc')->where('studentid', $student)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->map(fn (FinalGrade $sheet) => ['id' => $sheet->id, 'program_id' => $sheet->program_id,
                'program' => $sheet->program?->programdesc, 'status' => $sheet->status,
                'updated_at' => $sheet->updated_at?->format('Y-m-d H:i'), 'url' => route('forms.grades.edit', $sheet)])->all();
    }

    private function rejectDuplicate(int $student, int $program): void
    {
        $existing = FinalGrade::where('studentid', $student)->where('program_id', $program)->value('id');
        if ($existing !== null) {
            throw ValidationException::withMessages(['program_id' => "This learner already has a grade sheet for this program (sheet {$existing}). Open it from the notice above instead of starting a new one."]);
        }
    }

    private function history(FinalGrade $sheet, string $action, User $user): void
    {
        DB::table('finalgrades_history')->insert(['finalgradeid' => $sheet->id, 'revision' => $sheet->revision,
            'action' => $action, 'status' => $sheet->status, 'data' => json_encode(FinalGrades::snapshot($sheet)),
            'user_id' => $user->id, 'user_name' => "{$user->name} ({$user->username})", 'created_at' => now()]);
    }

    private function describe($history)
    {
        $previous = null;

        return $history->map(function ($entry) use (&$previous) {
            $data = json_decode($entry->data, true);
            $changed = [];
            if ($entry->action !== 'deleted') {
                foreach (['gradername' => 'Signature', 'gradedate' => 'Date', 'gradenotes' => 'Notes'] as $field => $label) {
                    if (($data[$field] ?? null) !== ($previous[$field] ?? null)) {
                        $changed[] = $label;
                    }
                }
                foreach ($data['grades'] ?? [] as $item => $grade) {
                    if ($grade['grade'] !== ($previous['grades'][$item]['grade'] ?? null)) {
                        $changed[] = $grade['item'];
                    }
                }
            }
            $previous = $data;
            $entry->changed = $changed;

            return $entry;
        });
    }

    private function submitting(Request $request): bool
    {
        return $request->input('action') === 'submit';
    }
}
