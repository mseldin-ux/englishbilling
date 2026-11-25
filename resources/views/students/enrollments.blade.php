<section id="enrollment-history">
<h2>Enrollment and stages</h2>
<p>{{ $student->stageSummary() }}</p>
@php $enrollmentHistory = $student->enrollments()->with('program', 'stages', 'events')->get(); @endphp
@if($enrollmentHistory->isEmpty() && $student->program_id)
    <p>Existing program: {{ $student->program?->program }} |
        Program start: {{ $student->start_date?->format('Y-m-d') ?? 'Not recorded' }}</p>
    @foreach(\App\Billing\Enrollments::STATUS_DATES as $flag => $dateField)
        @if($flag !== 'onextension' && $student->$flag)
            <p>Program end ({{ $flag }}): {{ $student->$dateField?->format('Y-m-d') ?? 'Not recorded' }}</p>
        @endif
    @endforeach
@endif
@foreach($enrollmentHistory as $enrollment)
    <details @if($student->current_enrollment_id === $enrollment->id) open @endif>
        <summary>{{ $enrollment->label() }} — {{ $enrollment->status }}{{ $enrollment->ended_on ? ', ended '.$enrollment->ended_on->format('Y-m-d') : '' }}</summary>
        <p>Program start: {{ $enrollment->started_on?->format('Y-m-d') ?? 'Not recorded' }} |
            Program end: {{ $enrollment->ended_on?->format('Y-m-d') ?? 'Not ended' }}</p>
        @if($enrollment->mode === 'legacy')
            <p>Preserved legacy accounting context; no historical amounts or PDFs were changed.</p>
            <p>The legacy end above is the handoff to the new enrollment. Original recorded departure/completion dates:</p>
            @foreach(\App\Billing\Enrollments::STATUS_DATES as $flag => $dateField)
                @if($flag !== 'onextension' && ($enrollment->legacy_context['student'][$flag] ?? false))
                    <p>{{ $flag }}: {{ substr($enrollment->legacy_context['student'][$dateField] ?? 'Not recorded', 0, 10) }}</p>
                @endif
            @endforeach
        @else
            @if($enrollment->mode === 'whole')<p>Agreed service days: {{ $enrollment->service_days }}</p>@endif
            <table><thead><tr><th>Stage</th><th>Started</th><th>Service days</th><th>Status</th><th>Completed</th><th>Ended</th><th>Actions</th></tr></thead><tbody>
            @foreach($enrollment->stages as $stage)
                <tr><td>{{ $stage->stage }} — {{ $stage->label() }}</td>
                    <td>{{ $stage->started_on->format('Y-m-d') }}</td><td>{{ $stage->service_days }}</td><td>{{ $stage->status }}</td>
                    <td>{{ $stage->completed_on?->format('Y-m-d') ?? 'Not completed' }}</td><td>{{ $stage->ended_on?->format('Y-m-d') ?? 'Not ended' }}</td>
                    <td>@include('students.enrollment-edit', ['unit' => $stage, 'stageId' => $stage->id])</td></tr>
            @endforeach
            </tbody></table>
            @if($enrollment->mode === 'whole')
                @include('students.enrollment-edit', ['unit' => $enrollment, 'stageId' => null])
            @endif
        @endif
        <ul>@foreach($enrollment->events as $event)
            <li>{{ $event->effective_on->format('Y-m-d') }}: {{ $event->action }}{{ $event->stage_enrollment_id ? ' (stage record '.$event->stage_enrollment_id.')' : '' }}
                @if($event->voided_at) (undone; retained for audit) @endif
                @if($event->details) — {{ json_encode($event->details) }} @endif</li>
        @endforeach</ul>
    </details>
@endforeach
@php $currentEnrollment = $student->currentEnrollment; @endphp
@if($currentEnrollment && !$currentEnrollment->ended_on)
    @php $concurrentStages = config('billing.concurrent_stages') && $currentEnrollment->mode === 'staged'; @endphp
    @if($currentEnrollment->status === 'between_stages' || $concurrentStages)
        <form method="post" action="{{ route('enrollments.store', $student) }}" data-dirty>
            @csrf
            <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
            <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
            <input type="hidden" name="action" value="stage">
            @if($currentEnrollment->program_stages)
                @php $nextStageProgram = \App\Models\Program::find($currentEnrollment->stages->last()?->next_program_id); @endphp
                <p>Next stage program: {{ $nextStageProgram?->program ?? 'No next stage configured' }}. Enrollment begins only when you confirm below.</p>
                <input type="hidden" name="program_id" value="{{ $nextStageProgram?->id }}">
                <details>
                    <summary>Exception: enroll in a different stage</summary>
                    <label>Override stage program <select name="override_program_id">
                        <option value="">Use the proposed next stage</option>
                        @foreach($programs as $option)
                            @if($option->staged && $option->certificate_program_id === $currentEnrollment->program_id)
                                <option value="{{ $option->id }}" @selected((int) old('override_program_id') === $option->id)>{{ $option->program }}</option>
                            @endif
                        @endforeach
                    </select></label>
                    <label>Reason for exception <textarea name="override_reason" maxlength="1000">{{ old('override_reason') }}</textarea></label>
                    <p>Use only for an approved out-of-sequence or repeated stage. The reason is recorded in enrollment history; completed stages and their billing are retained.</p>
                </details>
            @else
            <label>Next stage <select name="stage">@foreach(\App\Billing\Enrollments::STAGES as $number => $label)
                <option value="{{ $number }}">Stage {{ $number }} — {{ $label }}</option>@endforeach</select></label>
            @endif
            <label>Start date <input type="date" name="started_on" value="{{ today()->toDateString() }}" required></label>
            <label><input type="checkbox" name="accounts_settled" value="1" required> Previous payment obligations have been met</label>
            <button>{{ $currentEnrollment->program_stages ? 'Enroll in next stage' : 'Start stage' }}</button>
        </form>
    @endif
    @if(($concurrentStages && $currentEnrollment->stages->whereNull('ended_on')->isNotEmpty())
        || (!$concurrentStages && $currentEnrollment->status !== 'between_stages'))
        <form method="post" action="{{ route('enrollments.store', $student) }}" data-dirty>
            @csrf
            <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
            <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
            <input type="hidden" name="action" value="complete">
            @if($concurrentStages)
                <label>Stage to complete <select name="stage_enrollment_id" required>
                    <option value="">Choose a stage</option>
                    @foreach($currentEnrollment->stages->whereNull('ended_on') as $stage)
                        <option value="{{ $stage->id }}">Stage {{ $stage->stage }} — {{ $stage->label() }}, started {{ $stage->started_on->format('Y-m-d') }}</option>
                    @endforeach
                </select></label>
            @endif
            <label>Completion date <input type="date" name="effective_on" value="{{ today()->toDateString() }}" required></label>
            @if(!$concurrentStages)<label><input type="checkbox" name="accounts_settled" value="1" required> Completion and financial settlement confirmed</label>@endif
            <button>Complete {{ $currentEnrollment->mode === 'staged' ? 'stage' : 'program' }}</button>
        </form>
    @endif
    @if($concurrentStages && $currentEnrollment->stages->whereNull('ended_on')->isEmpty())
        <form method="post" action="{{ route('enrollments.store', $student) }}" data-dirty>
            @csrf
            <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
            <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
            <input type="hidden" name="action" value="graduate">
            <label>Graduation date <input type="date" name="effective_on" value="{{ today()->toDateString() }}" required></label>
            <button>Graduate learner</button>
        </form>
    @endif
@else
    <form method="post" action="{{ route('enrollments.store', $student) }}" data-dirty>
        @csrf
        <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
        <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
        <input type="hidden" name="action" value="program">
        <label>Program <select name="program_id" required>@foreach($programs as $program)
            <option value="{{ $program->id }}" @selected($student->program_id === $program->id)>{{ $program->program }}{{ $program->staged ? ' (staged)' : '' }}</option>
        @endforeach</select></label>
        <p>Enrollment type and stage are determined by the selected program.</p>
        <label>Program start <input type="date" name="started_on" value="{{ today()->toDateString() }}" required></label>
        <label><input type="checkbox" name="accounts_settled" value="1" required> Any previous enrollment has been completed/closed and its payment obligations settled</label>
        <p>For a returning learner, this preserves previous accounting context and starts a separate enrollment. It does not create fees.</p>
        <button>Start new program enrollment</button>
    </form>
@endif
</section>
