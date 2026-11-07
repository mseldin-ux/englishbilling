@extends('layouts.app')
@section('content')
<div class="form-page">
<h2>Course Completion Form</h2>
<p class="hint">Submission {{ $completion->id }}, entered by the Adult Learner through their emailed link. Submissions cannot be edited.
    | <a href="{{ route('forms.completion.pdf', $completion) }}">Download PDF</a>
    @if($completion->invitation?->formsdata_id) | <a href="{{ route('forms.edit', $completion->invitation->formsdata_id) }}">Graduation Initiation form</a>@endif
    | <a href="{{ route('forms.index') }}">Back to Forms</a></p>
@unless($completion->address_in_us)
    <p class="warning"><strong>International address:</strong> connect this Adult Learner with Finance for the mailing payment.</p>
@endunless
<table class="forms-table completion-table">
    @include('completions.rows')
</table>
@if($others->isNotEmpty())
    <h3>Other submissions from this Adult Learner</h3>
    <ul>
        @foreach($others as $other)
            <li><a href="{{ route('forms.completion.show', $other) }}">Submission {{ $other->id }}</a> — {{ $other->submitted_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}@if($other->replaces_previous) (marked as replacing an earlier submission)@endif</li>
        @endforeach
    </ul>
@endif
</div>
@endsection
