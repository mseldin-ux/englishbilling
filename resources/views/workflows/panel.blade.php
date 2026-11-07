{{-- Workflow summary for a record. Expects $workflowRuns from App\Workflows\Workflows::panel(). --}}
@if($workflowRuns->isNotEmpty())
<section class="workflow-panel" aria-labelledby="workflow-heading">
    <h3 id="workflow-heading">Workflow</h3>
    @foreach($workflowRuns as $run)
        <p><strong>{{ $run->title }}</strong> workflow, started {{ $run->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}:
            <strong>{{ $run->statusLabel() }}</strong>
            @if(in_array($run->status, ['active', 'paused'], true)) — current stage: {{ $run->stageTitle }} (waiting on {{ $run->waitingOn }})@endif
            @if(auth()->user()->canUseRecordsWorkspace()) <a href="{{ route('workflows.runs.show', $run) }}">Open workflow</a>@endif
        </p>
        @foreach($run->invitations as $invitation)
            <p>Adult Learner's Course Completion link
                @if($invitation->revoked_at)
                    — <strong>withdrawn</strong> {{ $invitation->revoked_at->format('Y-m-d') }}.
                @else
                    ({{ $invitation->expires_at->isFuture() ? 'active until' : 'expired' }} {{ $invitation->expires_at->setTimezone(config('app.timezone'))->format('Y-m-d') }}@if($invitation->last_opened_at); last opened {{ $invitation->last_opened_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}@endif):
                    <input type="text" readonly size="60" value="{{ $invitation->url() }}" aria-label="Course Completion link" onclick="this.select()">
                @endif
            </p>
            @if($invitation->submissions->isNotEmpty())
                <ul>
                    @foreach($invitation->submissions as $submission)
                        <li><a href="{{ route('forms.completion.show', $submission) }}">Course Completion submission {{ $submission->id }}</a>
                            — {{ $submission->submitted_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</li>
                    @endforeach
                </ul>
            @else
                <p class="hint">The Adult Learner has not submitted the Course Completion Form yet.</p>
            @endif
        @endforeach
    @endforeach
</section>
@endif
