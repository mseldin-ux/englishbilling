@extends('layouts.app')
@section('content')
<div class="forms-index">
<h2>Workflows</h2>
<p><a href="{{ route('workflows.tasks') }}">My tasks ({{ $taskCount }})</a>
    @if(auth()->user()->is_admin) | <a href="{{ route('workflows.settings.edit') }}">Workflow Settings</a>@endif
    | <a href="{{ route('templates.index') }}">Email Templates</a></p>
<table class="forms-table">
    <thead><tr><th>Workflow</th>@foreach(\App\Models\WorkflowRun::STATUSES as $label)<th>{{ $label }}</th>@endforeach<th></th></tr></thead>
    <tbody>
    @foreach($workflows as $key => $workflow)
        <tr>
            <td>{{ $workflow['title'] }}</td>
            @foreach(array_keys(\App\Models\WorkflowRun::STATUSES) as $status)<td>{{ $workflow['counts'][$status] ?? 0 }}</td>@endforeach
            <td><a href="{{ route('workflows.show', $key) }}">Open</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
<h3>Find an Adult Learner's workflows</h3>
<form method="get" class="forms-filter">
    <label>Learner <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email, or User ID"></label>
    <label>Status
        <select name="status"><option value="">All</option><option value="open" @selected(($filters['status'] ?? '') === 'open')>In progress or paused</option>
            @foreach(\App\Models\WorkflowRun::STATUSES as $status => $label)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>@endforeach
        </select>
    </label>
    <button>Search</button> <a href="{{ route('workflows.index') }}">Clear</a>
</form>
@if($runs !== null)
    @if($runs->isEmpty())
        <p>No workflows match.</p>
    @else
        @include('workflows._runs', ['runs' => $runs, 'showWorkflow' => true])
        @if($runs->count() === 200)<p class="hint">Showing the 200 most recent matches. Narrow the search to see others.</p>@endif
    @endif
@endif
</div>
@endsection
