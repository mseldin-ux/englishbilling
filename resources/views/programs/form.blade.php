@extends('layouts.app')
@section('content')
<div class="form-page">
<h2>{{ $program->exists ? 'Edit program '.$program->id : 'Create program' }}</h2>
<p><a href="{{ route('programs.index') }}">Programs</a></p>
@php $selectedCertificate = old('certificate_program_id', $program->certificate_program_id); @endphp
<form method="post" action="{{ $program->exists ? route('programs.update', $program) : route('programs.store') }}" data-dirty data-certificate-values="{{ json_encode($certificateValues) }}">
    @csrf
    @if($program->exists)
        @method('put')
        <input type="hidden" name="version" value="{{ old('version', \App\Billing\RecordVersion::of($program)) }}">
    @endif
    <label>Program <input name="program" value="{{ old('program', $program->program) }}" maxlength="255" required></label>
    <label>Description <textarea name="programdesc">{{ old('programdesc', $program->programdesc) }}</textarea></label>
    <label>Service days <input type="number" name="servicedays" value="{{ old('servicedays', $program->servicedays) }}" min="1" max="10000"></label>
    <p>For a staged program this is the duration of this stage, not the whole certificate. Existing agreed durations are retained. Durations used by legacy learners cannot be changed here because that would change past reports.</p>
    @foreach(['staged' => 'Staged program', 'certleading' => 'Certificate leading'] as $field => $label)
        <label>{{ $label }} <select name="{{ $field }}">
            <option value="" @selected(old($field, $program->$field) === null)>Not specified{{ $field === 'staged' ? ' (not staged)' : ' (use legacy type)' }}</option>
            <option value="1" @selected((string) old($field, $program->$field) === '1')>Yes</option>
            <option value="0" @selected(old($field, $program->$field) !== null && in_array(old($field, $program->$field), [false, 0, '0'], true))>No</option>
        </select></label>
    @endforeach
    <label>Next stage program <select name="nextprog">
        <option value="">None / final stage</option>
        @foreach($programs as $option) @if($option->staged && $option->id !== $program->id)
            <option value="{{ $option->id }}" @selected((int) old('nextprog', $program->nextprog) === $option->id)>{{ $option->id }} - {{ $option->program }}</option>
        @endif @endforeach
    </select></label>
    <label>Shared certificate program <select name="certificate_program_id">
        <option value="">None (non-staged programs)</option>
        @foreach($programs as $option) @if(!$option->staged && $option->isCertificateLeading() && $option->id !== $program->id)
            <option value="{{ $option->id }}" @selected((int) old('certificate_program_id', $program->certificate_program_id) === $option->id)>{{ $option->id }} - {{ $option->program }}</option>
        @endif @endforeach
    </select></label>
    <p>Staged programs must be certificate leading. Null or zero nextprog means this is the final stage. Stages cannot form loops or share a successor. Finish configuring the sequence before enrolling learners. Later catalog edits affect future enrollments only.</p>
    <p data-certificate-hint @if(!$selectedCertificate) hidden @endif>The following fields are linked to the shared certificate program. Edit that program to change them; its future changes apply here automatically, including blank values.</p>
    @foreach(['type' => ['Legacy type', 8], 'shortname' => ['Short name', 32], 'director_team_email' => ['Director team email(s)', 255], 'course_completion_survey_url' => ['Course completion survey URL', 500], 'employer_survey_url' => ['Employer survey URL', 500], 'employment_form_url' => ['Employment form URL', 500]] as $field => [$label, $length])
        @php $localValue = old($field, $program->getRawOriginal($field)); @endphp
        <label>{{ $label }} <input name="{{ $field }}" data-certificate-field data-local-value="{{ $localValue }}"
            value="{{ $selectedCertificate ? ($certificateValues[$selectedCertificate][$field] ?? '') : $localValue }}"
            maxlength="{{ $length }}" @readonly($selectedCertificate)></label>
    @endforeach
    <button>Save program</button><span class="form-status" role="status"></span>
</form>
</div>
@endsection
