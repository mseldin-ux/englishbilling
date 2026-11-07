<?php

namespace App\Http\Controllers;

use App\Billing\PdfRenderer;
use App\Forms\FinalGrades;
use App\Forms\FormLocks;
use App\Forms\GraduationInitiation;
use App\Models\CourseCompletionInvitation;
use App\Models\FormData;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Workflows\CourseCompletionWorkflow;
use App\Workflows\Workflows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FormsController extends Controller
{
    public const COMPLETION = 'course-completion';

    public const TYPES = [GraduationInitiation::TYPE => GraduationInitiation::TITLE, FinalGrades::TYPE => FinalGrades::TITLE,
        self::COMPLETION => 'Course Completion Form'];

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted'];

    /**
     * One list of every saved form. Each form type is normalized to the same columns and unioned.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUSES))],
            'program_id' => ['nullable', 'integer'],
        ]);
        $term = trim($filters['q'] ?? '');
        $graduation = DB::table('formsdata')->where('form_type', GraduationInitiation::TYPE)
            ->select(['id', 'form_type', 'student_id', 'program_id', 'status', 'data->graduation_date as form_date',
                'data->director_signature as signer', 'updated_by', 'updated_at']);
        $grades = DB::table('finalgrades')
            ->select(['id', DB::raw("'".FinalGrades::TYPE."' as form_type"), 'studentid as student_id', 'program_id', 'status',
                'gradedate as form_date', 'gradername as signer', 'updated_by', 'updated_at']);
        // Course Completion submissions come from Adult Learners and are always complete.
        $completions = DB::table('course_completions as c')->leftJoin('course_completion_invitations as i', 'i.id', '=', 'c.invitation_id')
            ->select(['c.id', DB::raw("'".self::COMPLETION."' as form_type"), 'c.student_id', 'i.program_id', DB::raw("'submitted' as status"),
                'c.expected_graduation_date as form_date', 'c.certificate_name as signer', DB::raw('NULL as updated_by'), 'c.submitted_at as updated_at'])
            ->when(($filters['status'] ?? null) === 'draft', fn ($query) => $query->whereRaw('1 = 0'));
        $sources = [
            [$graduation, 'student_id', 'data->director_signature', 'id', 'program_id', 'status'],
            [$grades, 'studentid', 'gradername', 'id', 'program_id', 'status'],
            [$completions, 'c.student_id', 'c.certificate_name', 'c.id', 'i.program_id', null],
        ];
        foreach ($sources as [$query, $student, $signer, $id, $program, $status]) {
            $query->when($status && ($filters['status'] ?? null), fn ($query) => $query->where($status, $filters['status']))
                ->when($filters['program_id'] ?? null, fn ($query, $value) => $query->where($program, $value));
            if ($term !== '') {
                $query->where(function ($query) use ($term, $student, $signer, $id) {
                    if (ctype_digit($term)) {
                        $query->orWhere($student, (int) $term)->orWhere($id, (int) $term);
                    }
                    $query->orWhereIn($student, $this->matchName(Student::query()->select('id'), $term))
                        // MySQL extracts a JSON null as the text 'null', so exclude it from signature matches.
                        ->orWhere(fn ($query) => $query->where($signer, 'like', '%'.$this->like($term).'%')->where($signer, '!=', 'null'));
                });
            }
        }
        $union = match ($filters['type'] ?? null) {
            GraduationInitiation::TYPE => $graduation,
            FinalGrades::TYPE => $grades,
            self::COMPLETION => $completions,
            default => $graduation->unionAll($grades)->unionAll($completions),
        };
        $forms = DB::query()->fromSub($union, 'forms')->orderByDesc('updated_at')->orderBy('form_type')->orderByDesc('id')
            ->paginate(40)->withQueryString();
        $students = Student::whereIn('id', $forms->pluck('student_id'))->get(['id', 'first_name', 'middle_name', 'last_name', 'cohort'])->keyBy('id');
        $programs = Program::whereIn('id', $forms->pluck('program_id')->filter())->pluck('programdesc', 'id');
        $users = User::whereIn('id', $forms->pluck('updated_by')->filter())->pluck('name', 'id');
        $locks = [];
        foreach (['formsdata' => GraduationInitiation::TYPE, 'finalgrades' => FinalGrades::TYPE] as $kind => $type) {
            $locks[$type] = FormLocks::holders($kind, $forms->where('form_type', $type)->pluck('id')->all());
        }
        $graduationData = FormData::whereIn('id', $forms->where('form_type', GraduationInitiation::TYPE)->pluck('id'))->pluck('data', 'id');
        $forms->getCollection()->transform(function ($form) use ($students, $programs, $users, $locks, $graduationData) {
            if ($form->form_type === GraduationInitiation::TYPE) {
                $data = $graduationData->get($form->id) ?? [];
                $form->form_date = $data['graduation_date'] ?? null;
                $form->signer = $data['director_signature'] ?? null;
            }
            $form->student = $students->get($form->student_id);
            $form->program = $programs->get($form->program_id);
            $form->updater = $users->get($form->updated_by);
            $form->lock = isset($locks[$form->form_type]) ? $locks[$form->form_type]->get($form->id) : null;
            [$edit, $pdf] = match ($form->form_type) {
                FinalGrades::TYPE => ['forms.grades.edit', 'forms.grades.pdf'],
                self::COMPLETION => ['forms.completion.show', 'forms.completion.pdf'],
                default => ['forms.edit', 'forms.pdf'],
            };
            $form->edit_url = route($edit, $form->id);
            $form->pdf_url = route($pdf, $form->id);

            return $form;
        });
        $used = DB::table('formsdata')->select('program_id')->union(DB::table('finalgrades')->select('program_id'))
            ->union(DB::table('course_completion_invitations')->select('program_id'));

        return view('forms.index', ['forms' => $forms, 'filters' => $filters,
            'creates' => [GraduationInitiation::TITLE => route('forms.create', GraduationInitiation::TYPE), FinalGrades::TITLE => route('forms.grades.create')],
            'programs' => DB::table('programs')->whereIn('id', $used)->orderBy('programdesc')->get(['id', 'programdesc'])]);
    }

    public function learners(Request $request)
    {
        $term = trim($request->validate(['q' => ['required', 'string', 'max:100']])['q']);
        $query = Student::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'cohort', 'program_id']);
        $query->where(function ($query) use ($term) {
            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
            $query->orWhere(fn ($student) => $this->matchName($student, $term));
        });
        $learners = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(25)->get()
            ->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->displayName() ?: 'Unnamed learner',
                'cohort' => $student->cohort, 'program_id' => $student->program_id]);

        return response()->json(['learners' => $learners])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, string $type)
    {
        $student = $request->integer('student') ? Student::find($request->integer('student')) : null;
        $values = array_fill_keys(array_keys(GraduationInitiation::LABELS), null);
        if ($student) {
            $values['student_id'] = $student->id;
            $values['program_id'] = GraduationInitiation::programs()->contains('id', $student->program_id) ? $student->program_id : null;
        }

        return $this->form(new FormData(['form_type' => $type, 'status' => 'draft', 'revision' => 1]), $values, null);
    }

    public function store(Request $request, string $type)
    {
        $submitting = $this->submitting($request);
        $data = GraduationInitiation::validate($request->all(), $submitting);
        $form = DB::transaction(function () use ($request, $type, $data, $submitting) {
            $form = new FormData(['form_type' => $type, 'revision' => 1, 'created_by' => $request->user()->id]);
            $this->apply($form, $data, $submitting, $request->user());
            $form->save();
            $this->history($form, $submitting ? 'submitted' : 'created', $request->user());
            if ($submitting) {
                Workflows::trigger(CourseCompletionWorkflow::TRIGGER, $form->id, $request->user());
            }

            return $form;
        });

        return redirect()->route('forms.edit', $form)->with('status', $submitting ? 'Form submitted.' : 'Draft saved.');
    }

    public function edit(Request $request, FormData $form)
    {
        $lock = FormLocks::claim($request, 'formsdata', $form->id);
        $form->refresh();

        return $this->form($form, $form->data, $lock);
    }

    public function update(Request $request, FormData $form)
    {
        $data = GraduationInitiation::validate($request->all(), $this->submitting($request) || $form->status === 'submitted', $form);
        $revision = (int) $request->validate(['revision' => ['required', 'integer']])['revision'];
        $message = DB::transaction(function () use ($request, $form, $data, $revision) {
            $form = FormData::whereKey($form->id)->lockForUpdate()->firstOrFail();
            FormLocks::requireOwner($request, 'formsdata', $form->id);
            if ($form->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'This form changed after you opened it. Reload to see the current version; no changes were saved.']);
            }
            $wasSubmitted = $form->status === 'submitted';
            $before = [$form->status, $form->program_id, $form->student_id, self::canonical($form->data)];
            $this->apply($form, $data, $wasSubmitted || $this->submitting($request), $request->user());
            if ($before === [$form->status, $form->program_id, $form->student_id, self::canonical($form->data)]) {
                return 'No changes to save.';
            }
            $form->revision++;
            $form->save();
            $this->history($form, ! $wasSubmitted && $form->status === 'submitted' ? 'submitted' : 'updated', $request->user());
            if (! $wasSubmitted && $form->status === 'submitted') {
                Workflows::trigger(CourseCompletionWorkflow::TRIGGER, $form->id, $request->user());
            } elseif ($wasSubmitted) {
                Workflows::subjectChanged('formsdata', $form->id);
            }

            return $form->status === 'submitted' && ! $wasSubmitted ? 'Form submitted.' : 'Changes saved.';
        });

        return redirect()->route('forms.edit', $form)->with('status', $message);
    }

    public function destroy(Request $request, FormData $form)
    {
        $request->validate(['confirm' => ['accepted']]);
        DB::transaction(function () use ($request, $form) {
            $form = FormData::whereKey($form->id)->lockForUpdate()->firstOrFail();
            FormLocks::requireOwner($request, 'formsdata', $form->id);
            $this->history($form, 'deleted', $request->user());
            FormLocks::forget('formsdata', $form->id);
            Workflows::cancel('formsdata', $form->id, $request->user());
            CourseCompletionInvitation::where('formsdata_id', $form->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $form->delete();
        });
        Log::notice('Form deleted.', ['admin_id' => $request->user()->id, 'formsdata_id' => $form->id]);

        return redirect()->route('forms.index')->with('status', "Form {$form->id} deleted. Its history is retained.");
    }

    public function pdf(FormData $form, PdfRenderer $renderer)
    {
        $name = 'graduation-initiation-'.$form->id.'-'.preg_replace('/[^A-Za-z0-9]+/', '-', $form->data['student_name'] ?? 'learner');

        return response($renderer->renderView('forms.pdf', ['form' => $form, 'values' => $form->data]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.trim($name, '-').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function status(Request $request, FormData $form)
    {
        return response()->json(FormLocks::state($request, 'formsdata', $form->id, (string) $request->header('X-Student-Lock', '')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function release(Request $request, FormData $form)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        FormLocks::release($request, 'formsdata', $form->id, $data['token']);

        return response()->json(['message' => 'Editing lock released.']);
    }

    private function form(FormData $form, array $saved, ?array $lock)
    {
        $values = [];
        foreach (array_keys(GraduationInitiation::LABELS) as $field) {
            $values[$field] = old($field, $saved[$field] ?? null);
        }
        if (session()->hasOldInput()) {
            foreach (array_keys(GraduationInitiation::QUESTIONS) as $question) {
                $values[$question] = old($question) !== null;
            }
        }
        $learner = $values['student_id'] ? Student::find($values['student_id']) : null;
        $history = $form->exists ? DB::table('formsdata_history')->where('formsdata_id', $form->id)->orderBy('id')->get() : collect();
        $others = $learner ? FormData::where('student_id', $learner->id)->where('form_type', $form->form_type)
            ->when($form->exists, fn ($query) => $query->whereKeyNot($form->id))->orderBy('id')->get(['id', 'status']) : collect();

        return view('forms.graduation', ['form' => $form, 'values' => $values, 'learner' => $learner,
            'programs' => GraduationInitiation::programs($form->program_id), 'editLock' => $lock,
            'history' => $this->describe($history), 'others' => $others,
            'workflowRuns' => $form->exists ? Workflows::panel('formsdata', $form->id) : collect()]);
    }

    private function apply(FormData $form, array $data, bool $submitted, User $user): void
    {
        $form->forceFill([
            'student_id' => $data['student_id'],
            'program_id' => $data['program_id'],
            'status' => $submitted ? 'submitted' : 'draft',
            'data' => GraduationInitiation::document($data, $form->data, $submitted, $user),
        ]);
        if ($submitted && $form->submitted_at === null) {
            $form->submitted_at = now();
            $form->submitted_by = $user->id;
        }
        $form->updated_by = $user->id;
    }

    private function history(FormData $form, string $action, User $user): void
    {
        DB::table('formsdata_history')->insert(['formsdata_id' => $form->id, 'revision' => $form->revision,
            'action' => $action, 'status' => $form->status, 'data' => json_encode($form->data),
            'user_id' => $user->id, 'user_name' => "{$user->name} ({$user->username})", 'created_at' => now()]);
    }

    private function describe($history)
    {
        $previous = [];

        return $history->map(function ($entry) use (&$previous) {
            $data = json_decode($entry->data, true);
            $changed = [];
            foreach (GraduationInitiation::LABELS as $field => $label) {
                $value = $data[$field] ?? null;
                $differs = $previous === [] ? ! in_array($value, [null, false], true) : $value !== ($previous[$field] ?? null);
                if ($differs && $entry->action !== 'deleted') {
                    $changed[] = $label;
                }
            }
            $previous = $data;
            $entry->changed = $changed;

            return $entry;
        });
    }

    /**
     * MySQL JSON columns do not preserve key order, so compare documents with keys sorted.
     */
    private static function canonical(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }
        ksort($value);

        return array_map(fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
    }

    private function submitting(Request $request): bool
    {
        return $request->input('action') === 'submit';
    }

    private function matchName($query, string $term)
    {
        foreach (preg_split('/\s+/', $term) as $word) {
            $like = '%'.$this->like($word).'%';
            $query->where(fn ($name) => $name->where('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }

        return $query;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
