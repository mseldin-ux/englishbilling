<?php

namespace App\Workflows;

use App\Jobs\AdvanceWorkflow;
use App\Models\CourseCompletionInvitation;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Runs workflows. Each run moves through its stages in order and waits at the first stage
 * that is not finished. Every change is recorded in workflow_events.
 */
class Workflows
{
    /** Minutes to wait before each automatic retry of a temporary failure. */
    private const BACKOFF = [1, 5, 15, 60, 240];

    /** @return array<string, Workflow> */
    public static function all(): array
    {
        $workflows = [];
        foreach (config('workflows.workflows', []) as $class) {
            $workflow = app($class);
            $workflows[$workflow->key()] = $workflow;
        }

        return $workflows;
    }

    public static function find(string $key): Workflow
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown workflow {$key}.");
    }

    /** @return array<string, Step> */
    public static function steps(string $workflow): array
    {
        $steps = [];
        foreach (self::find($workflow)->steps() as $key => $step) {
            $step = is_string($step) ? app($step) : $step;
            $step->workflow = $workflow;
            $step->key = $key;
            $steps[$key] = $step;
        }

        return $steps;
    }

    public static function handler(WorkflowStep|string $step, ?string $workflow = null): Step
    {
        $workflow ??= $step->run->workflow;
        $key = $step instanceof WorkflowStep ? $step->step : $step;

        return self::steps($workflow)[$key] ?? throw new InvalidArgumentException("Unknown stage {$key}.");
    }

    /**
     * Starts every workflow registered for the trigger, once per record. Call inside the
     * transaction that changes the record: the run starts only after it commits.
     */
    public static function trigger(string $trigger, int $subjectId, ?User $user = null): void
    {
        foreach (self::all() as $workflow) {
            if ($workflow->trigger() !== $trigger) {
                continue;
            }
            $created = DB::table('workflow_runs')->insertOrIgnore(['workflow' => $workflow->key(),
                'subject_type' => $workflow->subjectType(), 'subject_id' => $subjectId,
                'student_id' => $workflow->studentId($subjectId), 'program_id' => $workflow->programId($subjectId),
                'status' => 'active', 'created_by' => $user?->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($created === 0) {
                continue;
            }
            $run = WorkflowRun::where(['workflow' => $workflow->key(), 'subject_type' => $workflow->subjectType(), 'subject_id' => $subjectId])->firstOrFail();
            foreach (array_keys($workflow->steps()) as $position => $key) {
                WorkflowStep::create(['workflow_run_id' => $run->id, 'step' => $key, 'position' => $position + 1, 'status' => 'upcoming']);
            }
            self::event($run, null, 'started', $user);
            self::dispatch($run->id);
        }
    }

    /** Trigger fired when a run of the given workflow completes. */
    public static function completedTrigger(string $workflow): string
    {
        return "{$workflow}.completed";
    }

    /** Keeps a run's learner and program in step with its record after the record is edited. */
    public static function subjectChanged(string $subjectType, int $subjectId): void
    {
        foreach (WorkflowRun::where(['subject_type' => $subjectType, 'subject_id' => $subjectId])->get() as $run) {
            $workflow = self::all()[$run->workflow] ?? null;
            if ($workflow) {
                $run->forceFill(['student_id' => $workflow->studentId($subjectId), 'program_id' => $workflow->programId($subjectId)])->save();
                self::dispatch($run->id);
            }
        }
    }

    /** Something changed for a learner (a form was submitted); re-check their active runs after commit. */
    public static function signal(int $studentId): void
    {
        foreach (WorkflowRun::where('student_id', $studentId)->where('status', 'active')->pluck('id') as $id) {
            self::dispatch($id);
        }
    }

    private static function dispatch(int $runId): void
    {
        DB::afterCommit(fn () => AdvanceWorkflow::dispatchAfterResponse($runId));
    }

    /** Moves a run forward through every stage that can finish now. */
    public static function advance(int $runId, ?User $user = null): void
    {
        for ($guard = 0; $guard < 200; $guard++) {
            $run = WorkflowRun::find($runId);
            if ($run === null || $run->status !== 'active') {
                return;
            }
            $handlers = self::steps($run->workflow);
            $blocking = null;
            foreach (WorkflowStep::where('workflow_run_id', $run->id)->orderBy('position')->get() as $step) {
                $handler = $handlers[$step->step] ?? null;
                if (in_array($step->status, WorkflowStep::FINISHED, true)
                    || ($step->status === 'failed' && $handler !== null && ! $handler->required())) {
                    continue;
                }
                $blocking = $step;
                break;
            }
            if ($blocking === null) {
                if (WorkflowRun::whereKey($run->id)->where('status', 'active')
                    ->update(['status' => 'completed', 'completed_at' => now(), 'current_step' => null, 'updated_at' => now()]) > 0) {
                    self::event($run, null, 'finished', null);
                    // Follow-on workflows listen for "{key}.completed" on the same record.
                    self::trigger(self::completedTrigger($run->workflow), $run->subject_id);
                }

                return;
            }
            if ($run->current_step !== $blocking->step) {
                $run->forceFill(['current_step' => $blocking->step])->save();
            }
            $handler = $handlers[$blocking->step] ?? null;
            if ($handler === null) {
                if ($blocking->status !== 'failed') {
                    $blocking->forceFill(['status' => 'failed', 'error' => 'This stage no longer exists in the workflow definition. An admin can skip it.'])->save();
                }

                return;
            }
            if (! self::due($blocking, $handler)) {
                return;
            }
            $result = self::execute($blocking, $handler, null);
            if (! in_array($result, ['done', 'skipped'], true) && ! ($result === 'failed' && ! $handler->required())) {
                return;
            }
        }
    }

    private static function due(WorkflowStep $step, Step $handler): bool
    {
        return match ($step->status) {
            'upcoming', 'pending' => true,
            'waiting' => $handler->polls(),
            'scheduled' => $step->available_at === null || ! $step->available_at->isFuture(),
            'failed' => $step->available_at !== null && ! $step->available_at->isFuture(),
            default => false,
        };
    }

    /**
     * Claims and performs a stage. The claim is an atomic status change, so a stage never runs
     * twice at once. A rerun (resend) of a finished stage keeps it finished if it fails.
     *
     * @return string the resulting status, or 'busy' if another process holds the stage
     */
    private static function execute(WorkflowStep $step, Step $handler, ?User $user, bool $rerun = false): string
    {
        $previous = $step->status;
        if (WorkflowStep::whereKey($step->id)->where('status', $previous)->update(['status' => 'sending', 'updated_at' => now()]) === 0) {
            return 'busy';
        }
        $step->refresh();
        $run = $step->run;
        if ($previous === 'upcoming' || $step->reached_at === null) {
            $step->reached_at = now();
        }
        try {
            $outcome = $handler->reach($run, $step);
        } catch (StepFailed $exception) {
            return self::failed($step, $handler, $previous, $rerun, $exception->getMessage(), false, $user);
        } catch (Throwable $exception) {
            report($exception);

            return self::failed($step, $handler, $previous, $rerun, $exception->getMessage(), true, $user);
        }
        $data = [...($step->data ?? []), ...$outcome->data];
        switch ($outcome->kind) {
            case 'done':
                $email = $outcome->email ?? [];
                $step->forceFill(['status' => 'done', 'attempts' => 0, 'available_at' => null, 'error' => null, 'data' => $data,
                    'completed_at' => now(), 'completed_by' => $handler instanceof AcceptsInput ? ($data['submitted_by'] ?? $user?->id) : $user?->id,
                    ...($email ? ['recipients' => $email['recipients'], 'subject' => $email['subject'], 'body' => $email['body']] : [])])->save();
                if ($email) {
                    self::event($run, $step->step, $rerun ? 'resent' : 'sent', $user, null, ['recipients' => $email['recipients'],
                        'cc' => $data['cc'] ?? [], 'subject' => $email['subject'], 'attachments' => $data['attachments'] ?? []]);
                } elseif (! $handler instanceof AcceptsInput) {
                    self::event($run, $step->step, 'completed', $user, null, $outcome->data ?: null);
                }
                break;
            case 'skipped':
                $step->forceFill(['status' => 'skipped', 'attempts' => 0, 'available_at' => null, 'error' => null,
                    'data' => [...$data, 'skip_note' => $outcome->note], 'completed_at' => now(), 'completed_by' => $user?->id])->save();
                self::event($run, $step->step, 'skipped', $user, $outcome->note);
                break;
            case 'waiting':
                $step->forceFill(['status' => 'waiting', 'available_at' => null, 'error' => $outcome->note, 'data' => $data ?: null])->save();
                if ($previous !== 'waiting') {
                    self::event($run, $step->step, 'reached', $user, $outcome->note);
                }
                break;
            case 'scheduled':
                $changed = $previous !== 'scheduled' || $step->available_at?->toIso8601String() !== $outcome->at->toIso8601String();
                $step->forceFill(['status' => 'scheduled', 'available_at' => $outcome->at, 'error' => $outcome->note, 'data' => $data ?: null])->save();
                if ($changed) {
                    self::event($run, $step->step, 'scheduled', $user, $outcome->note, ['until' => $outcome->at->toIso8601String()]);
                }
                break;
        }

        return $step->status;
    }

    private static function failed(WorkflowStep $step, Step $handler, string $previous, bool $rerun, string $message, bool $temporary, ?User $user): string
    {
        $run = $step->run;
        if ($rerun && in_array($previous, ['done', 'skipped'], true)) {
            $step->forceFill(['status' => $previous])->save();
            self::event($run, $step->step, 'failed', $user, ($temporary ? 'Sending failed: ' : '').$message);

            return 'failed';
        }
        $attempts = $step->attempts + 1;
        $retry = $temporary && $attempts < config('workflows.max_attempts', 5)
            ? now()->addMinutes(self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1]) : null;
        $error = $temporary ? 'Temporary problem: '.$message.($retry ? ' It will be retried automatically.' : ' Click Retry once it is fixed.') : $message;
        $step->forceFill(['status' => 'failed', 'attempts' => $attempts, 'available_at' => $retry, 'error' => $error])->save();
        if ($step->wasChanged('error') || $retry) {
            self::event($run, $step->step, 'failed', $user, $error);
        }

        return 'failed';
    }

    /**
     * Completes a staff stage from submitted answers. A "No" pauses the run instead.
     *
     * @param  array<string, mixed>  $input
     * @return 'completed'|'paused'
     */
    public static function complete(WorkflowStep $step, array $input, User $user): string
    {
        $run = $step->run;
        $handler = self::handler($step);
        if (! $handler instanceof AcceptsInput) {
            throw new InvalidArgumentException('This stage is completed automatically.');
        }
        if (! $handler->canComplete($user)) {
            throw new AuthorizationException($handler->role() === 'finance'
                ? 'Only billing staff and admins can complete finance stages.' : 'Your user level cannot complete this stage.');
        }
        if ($run->status !== 'active' || $step->status !== 'waiting' || $run->current_step !== $step->step) {
            throw ValidationException::withMessages(['stage' => $run->status === 'paused'
                ? 'This workflow is paused. Resume it before completing stages.' : 'This stage is not open. Reload the page to see its current state.']);
        }
        $fields = $handler->fields($run);
        [$rules, $names] = [['note' => ['nullable', 'string', 'max:2000']], ['note' => 'note']];
        foreach ($fields as $name => $field) {
            $required = $field['required'] ?? in_array($field['type'], ['yesno', 'check', 'file'], true);
            $rules["answers.{$name}"] = match ($field['type']) {
                'yesno' => ['required', 'in:yes,no'],
                'check' => $required ? ['accepted'] : ['nullable', 'boolean'],
                'date' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2200-01-01'],
                'email' => [$required ? 'required' : 'nullable', 'string', 'max:255', 'email:rfc'],
                'file' => [$required ? 'required' : 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
                'textarea' => [$required ? 'required' : 'nullable', 'string', 'max:5000'],
                default => [$required ? 'required' : 'nullable', 'string', 'max:1000'],
            };
            $names["answers.{$name}"] = '"'.$field['label'].'"';
        }
        $validated = Validator::make($input, $rules, ['answers.*.accepted' => 'Tick :attribute to continue.',
            'answers.*.in' => 'Answer Yes or No to :attribute.', 'answers.*.required' => ':attribute is required.'], $names)->validate();
        $answers = [];
        foreach ($fields as $name => $field) {
            $value = $validated['answers'][$name] ?? null;
            $answers[$name] = match ($field['type']) {
                'check' => (bool) $value,
                'file' => $value,
                default => is_string($value) && trim($value) !== '' ? trim($value) : null,
            };
        }
        $note = trim((string) ($validated['note'] ?? '')) ?: null;
        $problems = $handler->checkInput($run, $answers);
        if ($problems !== []) {
            throw ValidationException::withMessages(collect($problems)->mapWithKeys(fn ($message, $field) => ["answers.{$field}" => $message])->all());
        }
        $no = array_keys(array_filter($answers, fn ($value, $name) => $fields[$name]['type'] === 'yesno' && $value === 'no', ARRAY_FILTER_USE_BOTH));
        if ($no !== []) {
            if ($note === null) {
                throw ValidationException::withMessages(['note' => 'Explain why you answered No. The workflow will be paused with your note.']);
            }

            return DB::transaction(function () use ($run, $step, $handler, $fields, $no, $note, $user, $answers) {
                $locked = WorkflowRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'active') {
                    throw ValidationException::withMessages(['stage' => 'This workflow is no longer active.']);
                }
                $questions = implode('; ', array_map(fn ($name) => $fields[$name]['label'], $no));
                $locked->forceFill(['status' => 'paused', 'paused_at' => now(), 'paused_by' => $user->id,
                    'pause_note' => $handler->title().' — answered No to: '.$questions."\n".$note])->save();
                self::event($locked, $step->step, 'answered-no', $user, $note, ['answers' => $answers]);

                return 'paused';
            });
        }
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'file' && $answers[$name] instanceof UploadedFile) {
                $answers[$name] = self::storeFile($run, $step, $name, $answers[$name], $user);
            }
        }
        $claimed = WorkflowStep::whereKey($step->id)->where('status', 'waiting')->update(['status' => 'pending', 'completed_by' => $user->id,
            'data' => json_encode([...($step->data ?? []), 'answers' => $answers, 'note' => $note,
                'submitted_at' => now()->toIso8601String(), 'submitted_by' => $user->id]), 'updated_at' => now()]);
        if ($claimed === 0) {
            throw ValidationException::withMessages(['stage' => 'Someone else completed this stage first. Reload the page.']);
        }
        self::event($run, $step->step, 'completed', $user, $note, ['answers' => self::displayAnswers($answers)]);
        self::advance($run->id, $user);

        return 'completed';
    }

    /** Replaces the PDF stored by a finished upload stage. */
    public static function replaceFile(WorkflowStep $step, string $field, UploadedFile $file, User $user): void
    {
        $handler = self::handler($step);
        $fields = $handler instanceof AcceptsInput ? $handler->fields($step->run) : [];
        if (($fields[$field]['type'] ?? null) !== 'file' || ! $handler->canComplete($user) || $step->status !== 'done') {
            throw new InvalidArgumentException('This file cannot be replaced.');
        }
        $data = $step->data ?? [];
        $data['answers'][$field] = self::storeFile($step->run, $step, $field, $file, $user);
        $step->forceFill(['data' => $data])->save();
    }

    private static function storeFile(WorkflowRun $run, WorkflowStep $step, string $field, UploadedFile $file, User $user): array
    {
        $path = Storage::disk('local')->putFileAs("workflows/{$run->id}", $file, "{$step->step}-{$field}.pdf");
        $stored = ['name' => mb_substr($file->getClientOriginalName(), 0, 200), 'size' => $file->getSize(), 'path' => $path,
            'uploaded_at' => now()->toIso8601String(), 'uploaded_by' => $user->id];
        self::event($run, $step->step, 'uploaded', $user, null, ['name' => $stored['name'], 'size' => $stored['size']]);

        return $stored;
    }

    /** @return array<string, mixed> */
    public static function displayAnswers(array $answers): array
    {
        return array_map(fn ($value) => is_array($value) && isset($value['path']) ? $value['name'] : $value, $answers);
    }

    public static function pause(WorkflowRun $run, string $note, User $user): void
    {
        if (WorkflowRun::whereKey($run->id)->where('status', 'active')->update(['status' => 'paused', 'paused_at' => now(),
            'paused_by' => $user->id, 'pause_note' => $note, 'updated_at' => now()]) === 0) {
            throw ValidationException::withMessages(['run' => 'Only an active workflow can be paused.']);
        }
        self::event($run, $run->current_step, 'paused', $user, $note);
    }

    public static function resume(WorkflowRun $run, ?string $note, User $user): void
    {
        if (WorkflowRun::whereKey($run->id)->where('status', 'paused')->update(['status' => 'active', 'paused_at' => null,
            'paused_by' => null, 'pause_note' => null, 'updated_at' => now()]) === 0) {
            throw ValidationException::withMessages(['run' => 'Only a paused workflow can be resumed.']);
        }
        self::event($run, $run->current_step, 'resumed', $user, $note);
        self::advance($run->id, $user);
    }

    /** Admin: reopens a stage and every stage after it. Emails in those stages are sent again when reached. */
    public static function sendBack(WorkflowRun $run, string $stepKey, string $note, User $user): void
    {
        $target = $run->step($stepKey);
        if ($target === null || $run->status === 'cancelled') {
            throw ValidationException::withMessages(['step' => 'That stage cannot be reopened.']);
        }
        DB::transaction(function () use ($run, $target, $note, $user) {
            WorkflowStep::where('workflow_run_id', $run->id)->where('position', '>=', $target->position)->update([
                'status' => 'upcoming', 'attempts' => 0, 'available_at' => null, 'reached_at' => null, 'recipients' => null,
                'subject' => null, 'body' => null, 'data' => null, 'error' => null, 'completed_at' => null, 'completed_by' => null,
                'updated_at' => now()]);
            $run->forceFill(['status' => 'active', 'current_step' => $target->step, 'completed_at' => null,
                'paused_at' => null, 'paused_by' => null, 'pause_note' => null])->save();
            self::event($run, $target->step, 'sent-back', $user, $note);
        });
        self::advance($run->id, $user);
    }

    /** Admin: marks an unfinished stage as skipped so the run can continue. */
    public static function skip(WorkflowStep $step, string $note, User $user): void
    {
        if (in_array($step->status, [...WorkflowStep::FINISHED, 'sending'], true) || $step->run->status === 'cancelled') {
            throw ValidationException::withMessages(['step' => 'That stage cannot be skipped now.']);
        }
        $step->forceFill(['status' => 'skipped', 'available_at' => null, 'error' => null, 'completed_at' => now(),
            'completed_by' => $user->id, 'data' => [...($step->data ?? []), 'skip_note' => $note]])->save();
        self::event($step->run, $step->step, 'skipped', $user, $note);
        self::advance($step->workflow_run_id, $user);
    }

    /** Sends an email stage again, or tries a failed stage now. */
    public static function retry(WorkflowStep $step, ?User $user): WorkflowStep
    {
        $handler = self::handler($step);
        $rerun = in_array($step->status, ['done', 'skipped'], true);
        if ($rerun && ! $handler instanceof EmailStep) {
            throw ValidationException::withMessages(['step' => 'Only email stages can be sent again.']);
        }
        if (! $rerun && $step->status !== 'failed') {
            throw ValidationException::withMessages(['step' => $step->status === 'sending'
                ? 'This stage is in progress right now. Reload the page in a minute.' : 'This stage has not failed.']);
        }
        if ($step->run->status === 'cancelled') {
            throw ValidationException::withMessages(['step' => 'This workflow was cancelled.']);
        }
        self::execute($step, $handler, $user, $rerun);
        if ($step->run->status === 'active') {
            self::advance($step->workflow_run_id, $user);
        }

        return $step->refresh();
    }

    /** Admin, or the record being deleted: stops the run for good. */
    public static function cancelRun(WorkflowRun $run, ?string $note, ?User $user): void
    {
        if (in_array($run->status, ['cancelled', 'completed'], true)) {
            return;
        }
        $run->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'current_step' => null])->save();
        WorkflowStep::where('workflow_run_id', $run->id)->whereNotIn('status', WorkflowStep::FINISHED)
            ->update(['status' => 'cancelled', 'available_at' => null, 'updated_at' => now()]);
        CourseCompletionInvitation::where('workflow_run_id', $run->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        self::event($run, null, 'cancelled', $user, $note);
    }

    /** Cancels the runs for a record, for example when it is deleted. */
    public static function cancel(string $subjectType, int $subjectId, ?User $user = null): void
    {
        foreach (WorkflowRun::where(['subject_type' => $subjectType, 'subject_id' => $subjectId])->get() as $run) {
            self::cancelRun($run, 'The record that started this workflow was deleted.', $user);
        }
    }

    /** Re-checks runs after a settings change so stages waiting on a missing setting can continue. */
    public static function retryFailed(?User $user): void
    {
        $steps = WorkflowStep::where('status', 'failed')->whereNull('available_at')
            ->whereHas('run', fn ($query) => $query->where('status', 'active'))->get();
        foreach ($steps as $step) {
            self::execute($step, self::handler($step), $user);
            self::advance($step->workflow_run_id, $user);
        }
    }

    /** Scheduler pass: recovers interrupted stages, runs due retries and timers. Returns runs checked. */
    public static function runPending(): int
    {
        WorkflowStep::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'available_at' => now(), 'error' => 'This stage was interrupted; retrying.', 'updated_at' => now()]);
        $due = WorkflowStep::where('status', 'failed')->whereNotNull('available_at')->where('available_at', '<=', now())
            ->whereHas('run', fn ($query) => $query->where('status', 'active'))->orderBy('id')->limit(100)->get();
        foreach ($due as $step) {
            $handler = self::steps($step->run->workflow)[$step->step] ?? null;
            if ($handler && ! $handler->required()) {
                self::execute($step, $handler, null);
            }
        }
        $runs = WorkflowRun::where('status', 'active')->orderBy('id')->pluck('id');
        foreach ($runs as $id) {
            self::advance($id);
        }

        return $runs->count();
    }

    /** Runs for a record, with titles, for the summary shown on the record's page. */
    public static function panel(string $subjectType, int $subjectId): Collection
    {
        return WorkflowRun::where(['subject_type' => $subjectType, 'subject_id' => $subjectId])->orderBy('id')->get()
            ->each(fn (WorkflowRun $run) => self::describe($run));
    }

    /** Adds display titles: $run->title, $run->stageTitle, $run->waitingOn. */
    public static function describe(WorkflowRun $run): WorkflowRun
    {
        $workflow = self::all()[$run->workflow] ?? null;
        $run->title = $workflow?->title() ?? $run->workflow;
        $handler = $workflow && $run->current_step ? (self::steps($run->workflow)[$run->current_step] ?? null) : null;
        $run->stageTitle = $handler?->title() ?? $run->current_step;
        $run->waitingOn = $handler ? Step::WAITING_ON[$handler->waitingOn()] ?? $handler->waitingOn() : null;

        return $run;
    }

    public static function event(WorkflowRun $run, ?string $step, string $action, ?User $user, ?string $note = null, ?array $data = null): void
    {
        WorkflowEvent::create(['workflow_run_id' => $run->id, 'step' => $step, 'action' => $action, 'note' => $note,
            'data' => $data, 'user_id' => $user?->id, 'created_at' => now()]);
    }
}
