@extends('layouts.portal')
@section('title', 'Course Completion Form')
@section('content')
@php
    $yesNo = function (string $field, array $values) {
        return (string) ($values[$field] ?? '');
    };
@endphp
<h1>Course Completion Form</h1>
@if(session('submitted'))
    <div class="portal-thanks" role="status">
        <p><strong>Thank you! Your Course Completion Form was received.</strong></p>
        <p>If you need to correct anything, you can submit the form again using the same link until
            {{ $invitation->expires_at->setTimezone(config('app.timezone'))->format('F j, Y') }}.</p>
    </div>
@endif
<p>Hello {{ $student->first_name ?: $student->displayName() }}. Please tell us how you would like your name to appear on your
    certificate and where it should be mailed.@if($invitation->program) Program: <strong>{{ $invitation->program->programdesc }}</strong>.@endif</p>
@if($previous)
    <p class="hint">We received a submission from you on {{ $previous->submitted_at->setTimezone(config('app.timezone'))->format('F j, Y') }}.
        The form below shows those answers.</p>
@endif
@if($errors->any())
    <div role="alert" class="portal-errors">
        <p><strong>Please correct the following:</strong></p>
        <ul>@foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
    </div>
@endif
<form method="post" action="{{ route('portal.completion.store', $token) }}" class="portal-form">
    @csrf
    <fieldset>
        <legend>{{ $labels['replaces_previous'] }}</legend>
        <label class="check"><input type="radio" name="replaces_previous" value="1" required @checked($yesNo('replaces_previous', $values) === '1')> Yes</label>
        <label class="check"><input type="radio" name="replaces_previous" value="0" @checked($yesNo('replaces_previous', $values) === '0')> No</label>
    </fieldset>
    <label>{{ $labels['cohort'] }}
        <input type="text" name="cohort" maxlength="128" required value="{{ $values['cohort'] }}">
    </label>
    <label>{{ $labels['instructor'] }}
        <input type="text" name="instructor" maxlength="128" required value="{{ $values['instructor'] }}">
    </label>
    <label>{{ $labels['certificate_name'] }}
        <input type="text" name="certificate_name" maxlength="256" required value="{{ $values['certificate_name'] }}">
    </label>
    <label>{{ $labels['mailing_address'] }}
        <textarea name="mailing_address" rows="4" maxlength="256" required>{{ $values['mailing_address'] }}</textarea>
    </label>
    <fieldset>
        <legend>{{ $labels['address_in_us'] }}</legend>
        <label class="check"><input type="radio" name="address_in_us" value="1" required @checked($yesNo('address_in_us', $values) === '1')> Yes</label>
        <label class="check"><input type="radio" name="address_in_us" value="0" @checked($yesNo('address_in_us', $values) === '0')> No</label>
    </fieldset>
    <fieldset>
        <legend>Did you complete the short Course Completion Survey? It is at
            <a href="{{ $surveyUrl }}" target="_blank" rel="noopener noreferrer">{{ $surveyUrl }}</a>
            before your certificate is ordered. Answers are anonymous and are greatly appreciated by the faculty,
            director and administration team of CGMS.</legend>
        <label class="check"><input type="radio" name="survey_completed" value="1" required @checked($yesNo('survey_completed', $values) === '1')> Yes</label>
        <label class="check"><input type="radio" name="survey_completed" value="0" @checked($yesNo('survey_completed', $values) === '0')> No</label>
        <p class="hint">The survey must be completed before you submit this form.</p>
    </fieldset>
    <label>{{ $labels['expected_graduation_date'] }}
        <input type="date" name="expected_graduation_date" required value="{{ $values['expected_graduation_date'] }}">
    </label>
    <fieldset>
        <legend>{{ $labels['employment_status'] }}</legend>
        @foreach(\App\Models\CourseCompletion::EMPLOYMENT as $value => $label)
            <label class="check"><input type="radio" name="employment_status" value="{{ $value }}" @required($loop->first) @checked($values['employment_status'] === $value)> {{ $label }}</label>
        @endforeach
    </fieldset>
    <label>{{ $labels['additional_info'] }}
        <textarea name="additional_info" rows="5" maxlength="65000">{{ $values['additional_info'] }}</textarea>
    </label>
    <button type="submit">Submit Course Completion Form</button>
</form>
@endsection
