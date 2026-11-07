@extends('layouts.app')
@section('content')
@use('App\Http\Controllers\FormsController', 'Forms')
<div class="forms-index">
<h2>Forms</h2>
<h3>Available forms</h3>
<ul>
    @foreach($creates as $title => $url)
        <li><a href="{{ $url }}">New {{ $title }}</a></li>
    @endforeach
</ul>
<h3>Completed and draft forms — {{ number_format($forms->total()) }} matching</h3>
<form method="get" class="forms-filter">
    <label>Search <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Learner, User ID, form #, or signature"></label>
    <label>Form
        <select name="type"><option value="">All</option>
            @foreach(Forms::TYPES as $type => $title)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $title }}</option>@endforeach
        </select>
    </label>
    <label>Status
        <select name="status"><option value="">All</option>
            @foreach(Forms::STATUSES as $status => $label)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>@endforeach
        </select>
    </label>
    <label>Program
        <select name="program_id"><option value="">All</option>
            @foreach($programs as $program)<option value="{{ $program->id }}" @selected((string) ($filters['program_id'] ?? '') === (string) $program->id)>{{ $program->programdesc }}</option>@endforeach
        </select>
    </label>
    <button>Search</button> <a href="{{ route('forms.index') }}">Clear</a>
</form>
@if($forms->isEmpty())
    <p>No forms match.</p>
@else
<table class="forms-table">
    <thead><tr><th>Form #</th><th>Form</th><th>Adult Learner</th><th>Program</th><th>Status</th><th>Date</th>
        <th>Signature / certificate name</th><th>Last updated</th><th>Lock</th><th></th></tr></thead>
    <tbody>
    @foreach($forms as $form)
        <tr>
            <td>{{ $form->id }}</td>
            <td>{{ Forms::TYPES[$form->form_type] ?? $form->form_type }}</td>
            <td>{{ $form->student?->displayName() }}<br>
                <span class="hint">User ID {{ $form->student_id }} — Cohort {{ $form->student?->cohort ?: '(none)' }}</span></td>
            <td>{{ $form->program }}</td>
            <td>{{ Forms::STATUSES[$form->status] ?? $form->status }}</td>
            <td>@if($form->form_date){{ match ($form->form_type) { 'final-grades' => 'Graded', 'course-completion' => 'Expected graduation', default => 'Graduation' } }} {{ $form->form_date }}@endif</td>
            <td>{{ $form->signer }}</td>
            <td>{{ \Carbon\CarbonImmutable::parse($form->updated_at)->format('Y-m-d H:i') }}@if($form->updater)<br><span class="hint">{{ $form->updater }}</span>@endif</td>
            <td>@if($form->lock)Locked by {{ $form->lock->name }} ({{ $form->lock->username }})@endif</td>
            <td><a href="{{ $form->edit_url }}">{{ $form->form_type === 'course-completion' ? 'View' : 'View / Edit' }}</a> | <a href="{{ $form->pdf_url }}">PDF</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
{{ $forms->links() }}
@endif
</div>
@endsection
