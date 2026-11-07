<?php

namespace App\Http\Controllers;

use App\Models\CourseCompletion;
use App\Models\CourseCompletionInvitation;
use App\Models\FinalGrade;
use App\Models\FormData;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\AcceptsInput;
use App\Workflows\AwaitStep;
use App\Workflows\EmailStep;
use App\Workflows\ManualStep;
use App\Workflows\Step;
use App\Workflows\TimerStep;
use App\Workflows\Workflows;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class WorkflowController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['open', ...array_keys(WorkflowRun::STATUSES)])]]);
        $workflows = [];
        foreach (Workflows::all() as $key => $workflow) {
            $counts = WorkflowRun::where('workflow', $key)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
            $workflows[$key] = ['title' => $workflow->title(), 'counts' => $counts];
        }
        $runs = null;
        if (($filters['q'] ?? '') !== '' || ($filters['status'] ?? '') !== '') {
            $runs = $this->filtered(WorkflowRun::query(), $filters)->orderByDesc('id')->limit(200)->get()
                ->each(fn (WorkflowRun $run) => Workflows::describe($run));
        }

        return view('workflows.index', ['workflows' => $workflows, 'runs' => $runs, 'filters' => $filters,
            'taskCount' => $this->tasksFor($request->user())->flatten(1)->count()]);
    }

    public function show(Request $request, string $workflow)
    {
        abort_unless(array_key_exists($workflow, Workflows::all()), 404);
        $handlers = Workflows::steps($workflow);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['open', 'all', ...array_keys(WorkflowRun::STATUSES)])],
            'stage' => ['nullable', Rule::in(array_keys($handlers))],
            'waiting' => ['nullable', Rule::in(array_keys(Step::WAITING_ON))],
        ]) + ['status' => 'open'];
        $query = $this->filtered(WorkflowRun::where('workflow', $workflow), $filters);
        if (($filters['stage'] ?? '') !== '') {
            $query->where('current_step', $filters['stage']);
        }
        if (($filters['waiting'] ?? '') !== '') {
            $query->whereIn('current_step', array_keys(array_filter($handlers, fn (Step $step) => $step->waitingOn() === $filters['waiting'])) ?: ['']);
        }
        $runs = $query->orderBy('created_at')->paginate(50)->withQueryString();
        $runs->getCollection()->each(fn (WorkflowRun $run) => Workflows::describe($run));
        $stageCounts = WorkflowRun::where('workflow', $workflow)->whereIn('status', ['active', 'paused'])
            ->selectRaw('current_step, COUNT(*) AS total')->groupBy('current_step')->pluck('total', 'current_step');

        return view('workflows.show', ['key' => $workflow, 'title' => Workflows::find($workflow)->title(), 'handlers' => $handlers,
            'runs' => $runs, 'filters' => $filters, 'stageCounts' => $stageCounts, 'guide' => $this->guide($workflow, $handlers)]);
    }

    /**
     * What each stage does, how it is finished, its questions and its editable templates.
     *
     * @param  array<string, Step>  $handlers
     */
    private function guide(string $workflow, array $handlers): array
    {
        $about = Workflows::find($workflow)->guide();
        $guide = [];
        foreach ($handlers as $key => $handler) {
            $guide[$key] = (object) [
                'kind' => $this->kind($handler),
                'about' => $about[$key] ?? null,
                'how' => $handler->howCompleted(),
                'instructions' => $handler instanceof AcceptsInput ? $handler->instructions() : null,
                'fields' => $handler instanceof AcceptsInput ? array_column($handler->fields(new WorkflowRun), 'label') : [],
                'templates' => $handler->templates(),
            ];
        }

        return $guide;
    }

    public function run(Request $request, WorkflowRun $run)
    {
        Workflows::describe($run);
        $run->load(['student', 'program', 'creator', 'pauser', 'events.user', 'steps.completer']);
        $handlers = rescue(fn () => Workflows::steps($run->workflow), [], false);
        $user = $request->user();
        $stages = $run->steps->map(function (WorkflowStep $step) use ($handlers, $run, $user) {
            $handler = $handlers[$step->step] ?? null;
            $current = $run->current_step === $step->step && in_array($run->status, ['active', 'paused'], true);

            return (object) [
                'step' => $step,
                'title' => $handler?->title() ?? $step->step,
                'kind' => $this->kind($handler),
                'waitingOn' => $handler ? (Step::WAITING_ON[$handler->waitingOn()] ?? $handler->waitingOn()) : null,
                'current' => $current,
                'fields' => $handler instanceof AcceptsInput ? $handler->fields($run) : [],
                'instructions' => $handler instanceof AcceptsInput ? $handler->instructions() : null,
                'context' => $current && $handler ? rescue(fn () => $handler->context($run), [], false) : [],
                'canComplete' => $handler instanceof AcceptsInput && $handler->canComplete($user),
                'role' => $handler instanceof AcceptsInput ? $handler->role() : null,
                'isEmail' => $handler instanceof EmailStep,
            ];
        });

        return view('workflows.run', ['run' => $run, 'stages' => $stages, 'links' => $this->links($run)]);
    }

    public function tasks(Request $request)
    {
        return view('workflows.tasks', ['groups' => $this->tasksFor($request->user()), 'user' => $request->user()]);
    }

    public function complete(Request $request, WorkflowStep $step)
    {
        try {
            $result = Workflows::complete($step, $request->all(), $request->user());
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        return redirect()->route('workflows.runs.show', $step->workflow_run_id)->with('status', $result === 'paused'
            ? 'You answered No, so the workflow is paused with your note. Resume it once the issue is resolved.'
            : 'Stage completed.');
    }

    public function pause(Request $request, WorkflowRun $run)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        Workflows::pause($run, trim($data['note']), $request->user());

        return back()->with('status', 'Workflow paused.');
    }

    public function resume(Request $request, WorkflowRun $run)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        Workflows::resume($run, trim((string) ($data['note'] ?? '')) ?: null, $request->user());

        return back()->with('status', 'Workflow resumed.');
    }

    public function sendBack(Request $request, WorkflowRun $run)
    {
        $data = $request->validate(['step' => ['required', 'string', Rule::in($run->steps()->pluck('step'))],
            'note' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        Workflows::sendBack($run, $data['step'], trim($data['note']), $request->user());

        return back()->with('status', 'Workflow sent back. Stages from that point on will run again.');
    }

    public function cancel(Request $request, WorkflowRun $run)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        Workflows::cancelRun($run, trim($data['note']), $request->user());

        return back()->with('status', 'Workflow cancelled.');
    }

    public function skip(Request $request, WorkflowStep $step)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        Workflows::skip($step, trim($data['note']), $request->user());

        return back()->with('status', 'Stage skipped.');
    }

    public function retry(Request $request, WorkflowStep $step)
    {
        $request->validate(['confirm' => ['accepted']]);
        $wasFinished = in_array($step->status, ['done', 'skipped'], true);
        $step = Workflows::retry($step, $request->user());
        $failure = $step->run->events()->where('step', $step->step)->reorder('id', 'desc')->first();
        $message = match (true) {
            $step->status === 'failed' => 'The stage failed again: '.$step->error,
            $wasFinished && $failure?->action === 'failed' => 'The email could not be sent: '.$failure->note,
            $step->status === 'done' && $step->subject !== null => 'Email sent.',
            default => 'Stage retried; it is now "'.$step->statusLabel().'".',
        };

        return back()->with('status', $message);
    }

    public function replaceFile(Request $request, WorkflowStep $step)
    {
        $data = $request->validate(['field' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480']]);
        try {
            Workflows::replaceFile($step, $data['field'], $data['file'], $request->user());
        } catch (InvalidArgumentException $exception) {
            abort(403, $exception->getMessage());
        }

        return back()->with('status', 'File replaced.');
    }

    public function file(WorkflowStep $step, string $field)
    {
        $file = $step->data['answers'][$field] ?? null;
        abort_unless(is_array($file) && isset($file['path']) && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'] ?: "{$step->step}.pdf",
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    private function kind(?Step $handler): string
    {
        return match (true) {
            $handler === null => 'Unknown',
            $handler instanceof EmailStep && $handler instanceof AcceptsInput => 'Email (staff may supply address)',
            $handler instanceof EmailStep => 'Email',
            $handler instanceof ManualStep => collect($handler->defaultFields())->contains(fn ($field) => $field['type'] === 'yesno') ? 'Approval' : 'Staff task',
            $handler instanceof AwaitStep => 'Waits for a form',
            $handler instanceof TimerStep => 'Waiting period',
            default => 'Automatic',
        };
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        $status = $filters['status'] ?? '';
        if ($status === 'open') {
            $query->whereIn('status', ['active', 'paused']);
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        $term = trim($filters['q'] ?? '');
        if ($term !== '') {
            $query->whereHas('student', function ($query) use ($term) {
                if (ctype_digit($term)) {
                    $query->where('id', (int) $term);

                    return;
                }
                foreach (preg_split('/\s+/', $term) as $word) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
                    $query->where(fn ($query) => $query->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)->orWhere('emailaddr', 'like', $like));
                }
            });
        }

        return $query->with(['student', 'program']);
    }

    /** Forms, submissions and files linked to a run. */
    private function links(WorkflowRun $run): array
    {
        $links = [];
        if ($run->subject_type === 'formsdata' && ($form = FormData::find($run->subject_id))) {
            $links[] = ['Graduation Initiation form '.$form->id, route('forms.edit', $form->id)];
            $links[] = ['Graduation Initiation PDF', route('forms.pdf', $form->id)];
        }
        $invitations = CourseCompletionInvitation::where('workflow_run_id', $run->id)->pluck('id');
        foreach (CourseCompletion::whereIn('invitation_id', $invitations)->orderByDesc('submitted_at')->get() as $completion) {
            $links[] = ['Course Completion submission '.$completion->id.' ('.$completion->submitted_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i').')',
                route('forms.completion.show', $completion)];
        }
        if ($run->student_id && $run->program_id) {
            foreach (FinalGrade::where('studentid', $run->student_id)->where('program_id', $run->program_id)->get() as $sheet) {
                $links[] = ['Final Grades '.$sheet->id.' ('.($sheet->status === 'submitted' ? 'submitted' : 'draft').')', route('forms.grades.edit', $sheet)];
            }
        }
        foreach ($run->steps as $step) {
            foreach (($step->data['answers'] ?? []) as $field => $value) {
                if (is_array($value) && isset($value['path'])) {
                    $links[] = ['Uploaded: '.$value['name'], route('workflows.steps.file', [$step, $field])];
                }
            }
        }
        foreach (Workflows::panel($run->subject_type, $run->subject_id) as $other) {
            if ($other->id === $run->id) {
                continue;
            }
            $links[] = [$other->title.' workflow ('.$other->status.')', route('workflows.runs.show', $other)];
        }
        if ($run->student_id && request()->user()->canAccessBilling()) {
            $links[] = ['Billing record', route('students.show', $run->student_id)];
        }

        return $links;
    }

    /**
     * Open tasks for a user: finance stages for billing staff and admins; other staff stages,
     * paused runs and failed stages for records users and admins.
     *
     * @return Collection<string, Collection<int, WorkflowRun>>
     */
    private function tasksFor(User $user): Collection
    {
        $finance = $user->canAccessBilling();
        $records = $user->is_admin || $user->access_level === 'records';
        $groups = collect(['finance' => collect(), 'records' => collect(), 'attention' => collect()]);
        if (! $finance && ! $records) {
            return $groups;
        }
        $runs = WorkflowRun::whereIn('workflow_runs.status', ['active', 'paused'])
            ->join('workflow_steps as s', fn ($join) => $join->on('s.workflow_run_id', '=', 'workflow_runs.id')->on('s.step', '=', 'workflow_runs.current_step'))
            ->select('workflow_runs.*', 's.status as step_status', 's.error as step_error', 's.reached_at as step_reached_at')
            ->with(['student', 'program'])->orderBy('s.reached_at')->get();
        $handlers = [];
        foreach ($runs as $run) {
            $handlers[$run->workflow] ??= rescue(fn () => Workflows::steps($run->workflow), [], false);
            $handler = $handlers[$run->workflow][$run->current_step] ?? null;
            Workflows::describe($run);
            if ($run->status === 'paused' || ($run->step_status === 'failed')) {
                if ($records || ($finance && $handler instanceof AcceptsInput && $handler->role() === 'finance')) {
                    $groups['attention']->push($run);
                }
            } elseif ($run->step_status === 'waiting' && $handler instanceof AcceptsInput) {
                if ($handler->role() === 'finance' && $finance) {
                    $groups['finance']->push($run);
                } elseif ($handler->role() !== 'finance' && $records) {
                    $groups['records']->push($run);
                }
            }
        }

        return $groups;
    }
}
