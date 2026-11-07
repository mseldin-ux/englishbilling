@extends('layouts.app')
@section('content')
<div class="forms-index">
<h2>My workflow tasks</h2>
<p class="hint"><a href="{{ route('workflows.index') }}">All workflows</a></p>
@php
    $titles = ['attention' => 'Needs attention (paused or failed)', 'finance' => 'Finance', 'records' => 'Records staff'];
@endphp
@if($groups->flatten(1)->isEmpty())
    <p>You have no open workflow tasks.</p>
@endif
@foreach($groups as $group => $runs)
    @if($runs->isNotEmpty())
        <h3>{{ $titles[$group] }} ({{ $runs->count() }})</h3>
        <table class="forms-table">
            <thead><tr><th>Adult Learner</th><th>Workflow</th><th>Program</th><th>Stage</th><th>Waiting since</th><th>Note</th><th></th></tr></thead>
            <tbody>
            @foreach($runs as $run)
                <tr>
                    <td>{{ $run->student?->displayName() ?? '(no learner)' }}</td>
                    <td>{{ $run->title }}</td>
                    <td>{{ $run->program?->programdesc }}</td>
                    <td>{{ $run->stageTitle }}</td>
                    <td>{{ $run->step_reached_at ? \Carbon\CarbonImmutable::parse($run->step_reached_at)->setTimezone(config('app.timezone'))->format('Y-m-d') : '' }}</td>
                    <td>@if($run->status === 'paused')<strong>Paused:</strong> {{ $run->pause_note }}@elseif($run->step_status === 'failed'){{ $run->step_error }}@endif</td>
                    <td><a href="{{ route('workflows.runs.show', $run) }}">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endforeach
</div>
@endsection
