@extends('layouts.app')
@section('content')
@php
    $tz = config('app.timezone');
    $fmt = fn ($date) => $date ? \Carbon\CarbonImmutable::parse($date)->setTimezone($tz)->format('Y-m-d H:i') : '';
    $isAdmin = auth()->user()->is_admin;
    $open = in_array($run->status, ['active', 'paused'], true);
    $answer = function ($field, $value) {
        return match ($field['type']) {
            'yesno' => $value === 'yes' ? 'Yes' : ($value === 'no' ? 'No' : '—'),
            'check' => $value ? '✓ Done' : '—',
            default => is_array($value) ? ($value['name'] ?? '') : ($value ?? '—'),
        };
    };
@endphp
<div class="form-page workflow-run">
<h2>{{ $run->title }} workflow: {{ $run->student?->displayName() ?? '(no learner)' }}</h2>
<p class="hint"><a href="{{ route('workflows.show', $run->workflow) }}">All {{ $run->title }} workflows</a> | <a href="{{ route('workflows.tasks') }}">My tasks</a></p>
<table class="forms-table workflow-summary">
    <tr><th>Adult Learner</th><td>{{ $run->student?->displayName() }} @if($run->student_id)<span class="hint">User ID {{ $run->student_id }}</span>@endif</td></tr>
    <tr><th>Program</th><td>{{ $run->program?->programdesc ?? '—' }}</td></tr>
    <tr><th>Status</th><td @class(['workflow-failed' => $run->status === 'paused'])><strong>{{ $run->statusLabel() }}</strong>
        @if($run->status === 'completed') on {{ $fmt($run->completed_at) }}@endif
        @if($run->status === 'cancelled') on {{ $fmt($run->cancelled_at) }}@endif</td></tr>
    @if($open)
        <tr><th>Current stage</th><td>{{ $run->stageTitle }} <span class="hint">(waiting on {{ $run->waitingOn }})</span></td></tr>
    @endif
    <tr><th>Started</th><td>{{ $fmt($run->created_at) }}@if($run->creator) by {{ $run->creator->name }}@endif</td></tr>
</table>

@if($run->status === 'paused')
    <div class="workflow-paused">
        <p><strong>Paused</strong> {{ $fmt($run->paused_at) }}@if($run->pauser) by {{ $run->pauser->name }}@endif:</p>
        <div class="template-preview">{{ $run->pause_note }}</div>
        <form method="post" action="{{ route('workflows.runs.resume', $run) }}">
            @csrf
            <label>Note (optional) <input type="text" name="note" maxlength="2000" size="60"></label>
            <button>Resume workflow</button>
        </form>
    </div>
@endif

@if($links)
    <h3>Forms and files</h3>
    <ul>@foreach($links as [$label, $url])<li><a href="{{ $url }}">{{ $label }}</a></li>@endforeach</ul>
@endif

@foreach($stages->where('current', true) as $stage)
    <section class="workflow-current" aria-labelledby="current-stage">
        <h3 id="current-stage">Current stage: {{ $stage->title }}</h3>
        @if($stage->step->status === 'failed')
            <p class="workflow-failed">{{ $stage->step->error }}</p>
            <form method="post" action="{{ route('workflows.steps.retry', $stage->step) }}">
                @csrf <input type="hidden" name="confirm" value="1"><button>Retry now</button>
            </form>
        @elseif($stage->step->status === 'scheduled')
            <p>Scheduled to continue {{ $fmt($stage->step->available_at) }}.@if($stage->step->error) {{ $stage->step->error }}@endif</p>
        @elseif($stage->step->error)
            <p>{{ $stage->step->error }}</p>
        @endif
        @if($stage->context)
            <table class="forms-table">
                @foreach($stage->context as $label => $value)
                    <tr><th>{{ $label }}</th><td class="pre-line">@if(is_array($value))<a href="{{ $value[1] }}">{{ $value[0] }}</a>@else{{ $value }}@endif</td></tr>
                @endforeach
            </table>
        @endif
        @if($stage->fields && $stage->step->status === 'waiting')
            @if($run->status === 'paused')
                <p class="hint">Resume the workflow to complete this stage.</p>
            @elseif(! $stage->canComplete)
                <p class="hint">{{ $stage->role === 'finance' ? 'Only billing staff and admins can complete this finance stage.' : 'Your user level cannot complete this stage.' }}</p>
            @else
                @if($stage->instructions)<p class="pre-line">{{ $stage->instructions }}</p>@endif
                <form method="post" action="{{ route('workflows.steps.complete', $stage->step) }}" enctype="multipart/form-data" class="workflow-stage-form">
                    @csrf
                    @foreach($stage->fields as $name => $field)
                        @php $old = old('answers.'.$name); @endphp
                        @switch($field['type'])
                            @case('yesno')
                                <fieldset class="yesno"><legend>{{ $field['label'] }}</legend>
                                    <label><input type="radio" name="answers[{{ $name }}]" value="yes" @checked($old === 'yes') required> Yes</label>
                                    <label><input type="radio" name="answers[{{ $name }}]" value="no" @checked($old === 'no')> No</label>
                                </fieldset>
                                @break
                            @case('check')
                                <label class="check"><input type="checkbox" name="answers[{{ $name }}]" value="1" @checked($old) @required($field['required'] ?? true)> {{ $field['label'] }}</label>
                                @break
                            @case('file')
                                <label>{{ $field['label'] }} <input type="file" name="answers[{{ $name }}]" accept="application/pdf,.pdf" @required($field['required'] ?? true)></label>
                                @break
                            @case('textarea')
                                <label>{{ $field['label'] }} <textarea name="answers[{{ $name }}]" rows="4" @required($field['required'] ?? false)>{{ $old }}</textarea></label>
                                @break
                            @default
                                <label>{{ $field['label'] }} <input type="{{ in_array($field['type'], ['date', 'email'], true) ? $field['type'] : 'text' }}" name="answers[{{ $name }}]" value="{{ $old }}" maxlength="1000" @required($field['required'] ?? false)></label>
                        @endswitch
                    @endforeach
                    <label>Note @if(collect($stage->fields)->contains(fn ($f) => $f['type'] === 'yesno'))(required if you answer No; the workflow will be paused)@else(optional)@endif
                        <textarea name="note" rows="2" maxlength="2000">{{ old('note') }}</textarea></label>
                    <button>Submit</button>
                </form>
            @endif
        @endif
    </section>
@endforeach

<h3>Stages</h3>
<table class="forms-table workflow-stages">
    <thead><tr><th>#</th><th>Stage</th><th>Type</th><th>Status</th><th>Completed</th><th>Details</th><th></th></tr></thead>
    <tbody>
    @foreach($stages as $stage)
        @php $step = $stage->step; $data = $step->data ?? []; @endphp
        <tr @class(['workflow-current-row' => $stage->current])>
            <td>{{ $step->position }}</td>
            <td>{{ $stage->title }}@if($stage->waitingOn && $stage->waitingOn !== 'System')<br><span class="hint">{{ $stage->waitingOn }}</span>@endif</td>
            <td>{{ $stage->kind }}</td>
            <td @class(['workflow-failed' => $step->status === 'failed'])>{{ $step->statusLabel() }}
                @if($step->status === 'failed' && $step->error)<br><span class="hint">{{ $step->error }}</span>@endif
                @if($step->status === 'scheduled')<br><span class="hint">until {{ $fmt($step->available_at) }}</span>@endif
                @if($step->status === 'skipped' && ($data['skip_note'] ?? null))<br><span class="hint">{{ $data['skip_note'] }}</span>@endif</td>
            <td>{{ $fmt($step->completed_at) }}@if($step->completer)<br><span class="hint">{{ $step->completer->name }}</span>@endif</td>
            <td>
                @if($step->subject)
                    <details><summary>To {{ implode(', ', $step->recipients ?? []) }}@if($data['cc'] ?? [])<br>CC {{ implode(', ', $data['cc']) }}@endif</summary>
                        <p><strong>{{ $step->subject }}</strong>@if($data['attachments'] ?? [])<br>Attachments: {{ implode(', ', $data['attachments']) }}@endif</p>
                        <div class="template-preview">{{ $step->body }}</div></details>
                @endif
                @if(isset($data['answers']))
                    <ul class="workflow-answers">
                        @foreach($stage->fields as $name => $field)
                            @if(array_key_exists($name, $data['answers']))
                                <li>{{ $field['label'] }}: <strong>@if(is_array($data['answers'][$name]) && isset($data['answers'][$name]['path']))<a href="{{ route('workflows.steps.file', [$step, $name]) }}">{{ $data['answers'][$name]['name'] }}</a>@else{{ $answer($field, $data['answers'][$name]) }}@endif</strong></li>
                            @endif
                        @endforeach
                    </ul>
                    @if($data['note'] ?? null)<p class="hint">Note: {{ $data['note'] }}</p>@endif
                @endif
                @if(isset($data['graduated_on']))<p>Marked Graduated as of {{ $data['graduated_on'] }}.</p>@endif
                @if(isset($data['until']) && $step->status === 'done')<p class="hint">Waited {{ $data['days'] ?? $data['amount'] }} {{ isset($data['unit']) ? rtrim($data['unit'], 's') : 'day' }}(s), until {{ $fmt($data['until']) }}.</p>@endif
            </td>
            <td class="workflow-actions">
                @if($run->status !== 'cancelled')
                    @if($stage->isEmail && in_array($step->status, ['done', 'skipped'], true))
                        <form method="post" action="{{ route('workflows.steps.retry', $step) }}" onsubmit="return confirm('Send this email again now?')">
                            @csrf <input type="hidden" name="confirm" value="1"><button>{{ $step->status === 'done' ? 'Resend' : 'Send now' }}</button>
                        </form>
                    @elseif($step->status === 'failed' && ! $stage->current)
                        <form method="post" action="{{ route('workflows.steps.retry', $step) }}">
                            @csrf <input type="hidden" name="confirm" value="1"><button>Retry</button>
                        </form>
                    @endif
                    @if($step->status === 'done' && $stage->canComplete)
                        @foreach($stage->fields as $name => $field)
                            @if($field['type'] === 'file')
                                <details><summary>Replace file</summary>
                                    <form method="post" action="{{ route('workflows.steps.replace-file', $step) }}" enctype="multipart/form-data">
                                        @csrf <input type="hidden" name="field" value="{{ $name }}">
                                        <input type="file" name="file" accept="application/pdf,.pdf" required> <button>Upload</button>
                                    </form></details>
                            @endif
                        @endforeach
                    @endif
                    @if($isAdmin && ! in_array($step->status, ['done', 'skipped', 'cancelled', 'sending'], true))
                        <details><summary>Skip</summary>
                            <form method="post" action="{{ route('workflows.steps.skip', $step) }}" onsubmit="return confirm('Skip this stage?')">
                                @csrf <input type="hidden" name="confirm" value="1">
                                <input type="text" name="note" required maxlength="2000" placeholder="Reason"> <button>Skip stage</button>
                            </form></details>
                    @endif
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

@if($run->status === 'active' || $isAdmin)
<h3>Actions</h3>
<div class="workflow-admin">
    @if($run->status === 'active')
        <details><summary>Pause this workflow</summary>
            <form method="post" action="{{ route('workflows.runs.pause', $run) }}">
                @csrf <label>Reason <input type="text" name="note" required maxlength="2000" size="60"></label> <button>Pause</button>
            </form></details>
    @endif
    @if($isAdmin && $run->status !== 'cancelled')
        <details><summary>Send back to an earlier stage (admin)</summary>
            <p class="hint">The chosen stage and every stage after it are reopened. Emails in those stages are sent again when reached.</p>
            <form method="post" action="{{ route('workflows.runs.send-back', $run) }}" onsubmit="return confirm('Reopen this stage and every stage after it?')">
                @csrf <input type="hidden" name="confirm" value="1">
                <label>Stage <select name="step" required>
                    @foreach($stages as $stage)
                        @if(in_array($stage->step->status, ['done', 'skipped', 'failed', 'waiting', 'scheduled'], true))
                            <option value="{{ $stage->step->step }}">{{ $stage->step->position }}. {{ $stage->title }}</option>
                        @endif
                    @endforeach
                </select></label>
                <label>Reason <input type="text" name="note" required maxlength="2000" size="50"></label> <button>Send back</button>
            </form></details>
        @if($open)
            <details><summary>Cancel this workflow (admin)</summary>
                <form method="post" action="{{ route('workflows.runs.cancel', $run) }}" onsubmit="return confirm('Cancel this workflow? This cannot be undone.')">
                    @csrf <input type="hidden" name="confirm" value="1">
                    <label>Reason <input type="text" name="note" required maxlength="2000" size="50"></label> <button>Cancel workflow</button>
                </form></details>
        @endif
    @endif
</div>
@endif

<h3>History</h3>
<table class="forms-table">
    <thead><tr><th>When</th><th>Event</th><th>Stage</th><th>By</th><th>Details</th></tr></thead>
    <tbody>
    @php $titles = $stages->mapWithKeys(fn ($s) => [$s->step->step => $s->title]); @endphp
    @foreach($run->events->reverse() as $event)
        <tr>
            <td>{{ $fmt($event->created_at) }}</td>
            <td>{{ $event->actionLabel() }}</td>
            <td>{{ $event->step ? ($titles[$event->step] ?? $event->step) : '' }}</td>
            <td>{{ $event->user?->name ?? ($event->user_id ? 'a deleted user' : 'System') }}</td>
            <td class="pre-line">{{ $event->note }}@if(isset($event->data['recipients']))
To: {{ implode(', ', $event->data['recipients']) }}@if($event->data['cc'] ?? []) — CC: {{ implode(', ', $event->data['cc']) }}@endif
@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endsection
