@extends('layouts.app')
@section('content')
<script src="{{ asset('js/workflow-guide.js') }}" defer></script>
<div class="forms-index">
<h2>{{ $title }} workflow</h2>
<p class="hint"><a href="{{ route('workflows.index') }}">All workflows</a> | <a href="{{ route('workflows.tasks') }}">My tasks</a></p>
<details>
    <summary>Stages ({{ count($handlers) }}) and how many learners are at each</summary>
    <p class="hint">Click ⓘ next to a stage to see what it does, how it is completed, and the templates it uses.</p>
    <ol class="stage-list">
        @foreach($handlers as $stageKey => $handler)
            <li><a href="{{ route('workflows.show', [$key, 'stage' => $stageKey, 'status' => 'open']) }}">{{ $handler->title() }}</a>
                <button type="button" class="stage-info" data-stage-info="stage-guide-{{ $stageKey }}" title="About this stage" aria-label="About the stage: {{ $handler->title() }}">ⓘ</button>
                <span class="hint">— {{ \App\Workflows\Step::WAITING_ON[$handler->waitingOn()] ?? $handler->waitingOn() }}</span>
                @if($stageCounts[$stageKey] ?? 0) <strong>({{ $stageCounts[$stageKey] }})</strong>@endif</li>
        @endforeach
    </ol>
</details>
@foreach($handlers as $stageKey => $handler)
    @php $info = $guide[$stageKey]; @endphp
    <dialog id="stage-guide-{{ $stageKey }}" class="stage-guide" aria-labelledby="stage-guide-{{ $stageKey }}-title">
        <h3 id="stage-guide-{{ $stageKey }}-title">Stage {{ $loop->iteration }} of {{ $loop->count }}: {{ $handler->title() }}</h3>
        <p class="hint">{{ $info->kind }} · Waiting on: {{ \App\Workflows\Step::WAITING_ON[$handler->waitingOn()] ?? $handler->waitingOn() }}</p>
        <h4>What happens</h4>
        <p>{{ $info->about ?? 'No description has been written for this stage.' }}</p>
        <h4>How it is completed</h4>
        <p>{{ $info->how }}</p>
        @if($info->instructions)<p><em>Instructions shown with the stage:</em> {{ $info->instructions }}</p>@endif
        @if($info->fields)
            <ul>@foreach($info->fields as $label)<li>{{ $label }}</li>@endforeach</ul>
        @endif
        <h4>Templates</h4>
        @if($info->templates)
            <ul>@foreach($info->templates as $template)
                <li><a href="{{ route('templates.edit', $template->key) }}">{{ $template->name }}</a></li>
            @endforeach</ul>
        @else
            <p>None. This stage does not use an editable template.</p>
        @endif
        <div class="dialog-actions"><button type="button" data-stage-info-close>Close</button></div>
    </dialog>
@endforeach
<form method="get" class="forms-filter">
    <label>Learner <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email, or User ID"></label>
    <label>Status
        <select name="status">
            <option value="open" @selected($filters['status'] === 'open')>In progress or paused</option>
            <option value="all" @selected($filters['status'] === 'all')>All</option>
            @foreach(\App\Models\WorkflowRun::STATUSES as $status => $label)<option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>@endforeach
        </select>
    </label>
    <label>Stage
        <select name="stage"><option value="">Any</option>
            @foreach($handlers as $stageKey => $handler)<option value="{{ $stageKey }}" @selected(($filters['stage'] ?? '') === $stageKey)>{{ $handler->title() }}</option>@endforeach
        </select>
    </label>
    <label>Waiting on
        <select name="waiting"><option value="">Anyone</option>
            @foreach(\App\Workflows\Step::WAITING_ON as $who => $label)<option value="{{ $who }}" @selected(($filters['waiting'] ?? '') === $who)>{{ $label }}</option>@endforeach
        </select>
    </label>
    <button>Filter</button> <a href="{{ route('workflows.show', $key) }}">Clear</a>
</form>
<h3>{{ number_format($runs->total()) }} matching</h3>
@if($runs->isNotEmpty())
    @include('workflows._runs', ['runs' => $runs])
    {{ $runs->links() }}
@endif
</div>
@endsection
