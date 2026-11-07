@extends('layouts.app')
@section('content')
<div class="form-page workflow-settings">
<h2>Workflow Settings</h2>
<p class="hint">Separate multiple email addresses with commas. Email wording is edited on the <a href="{{ route('templates.index') }}">Email Templates</a> page.
    Saving retries any workflow stage that failed because a setting was missing.</p>
<form method="post" action="{{ route('workflows.settings.update') }}">
    @csrf @method('PUT')
    <fieldset><legend>Recipients, links and timing</legend>
        @foreach($definitions as $key => $definition)
            <label>{{ $definition['label'] }}
                @if($definition['type'] === 'days')
                    <input type="number" name="settings[{{ $key }}]" min="0" max="60" required value="{{ old("settings.$key", $values[$key]) }}">
                @else
                    <input type="{{ $definition['type'] === 'url' ? 'url' : 'text' }}" name="settings[{{ $key }}]" size="80" value="{{ old("settings.$key", $values[$key]) }}">
                @endif
            </label>
            <p class="hint">{{ $definition['help'] }}</p>
            @error("settings.$key")<p class="workflow-failed">{{ $message }}</p>@enderror
        @endforeach
    </fieldset>
    <fieldset><legend>Programs</legend>
        <p class="hint">Director team emails are CC'd on the final congratulations email. A blank link uses the default link above.</p>
        <table class="forms-table">
            <thead><tr><th>Program</th><th>Director team email(s)</th><th>Course Completion Survey link</th><th>Employer Graduate Survey link</th><th>Employment Question Form link (one year later)</th></tr></thead>
            <tbody>
            @foreach($programs as $program)
                <tr><td>{{ $program->programdesc }}@if($program->shortname) <span class="hint">({{ $program->shortname }})</span>@endif</td>
                    @foreach(['director_team_email' => 'text', 'course_completion_survey_url' => 'url', 'employer_survey_url' => 'url', 'employment_form_url' => 'url'] as $field => $type)
                        <td><input type="{{ $type }}" name="programs[{{ $program->id }}][{{ $field }}]" size="36" maxlength="{{ $type === 'url' ? 500 : 255 }}"
                                aria-label="{{ $program->programdesc }} {{ str_replace('_', ' ', $field) }}" value="{{ old("programs.{$program->id}.$field", $program->{$field}) }}">
                            @error("programs.{$program->id}.$field")<br><span class="workflow-failed">{{ $message }}</span>@enderror</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </fieldset>
    @foreach($stages as $workflow => $steps)
        <fieldset><legend>{{ $workflows[$workflow]->title() }}: staff stage wording</legend>
            <p class="hint">Leave a box unchanged to keep the built-in wording. Clearing a box also restores the built-in wording.</p>
            @foreach($steps as $key => ['step' => $step, 'override' => $override])
                <details>
                    <summary>{{ $step->title() }}@if($override !== [] && array_filter($override)) <span class="hint">(customized)</span>@endif</summary>
                    <label>Stage title <input type="text" name="stages[{{ $workflow }}][{{ $key }}][title]" size="80" maxlength="200"
                        value="{{ old("stages.$workflow.$key.title", $step->title()) }}"></label>
                    <label>Instructions <textarea name="stages[{{ $workflow }}][{{ $key }}][instructions]" rows="4" cols="80">{{ old("stages.$workflow.$key.instructions", $step->instructions()) }}</textarea></label>
                    @foreach($step->defaultFields() as $field => $definition)
                        <label>Question: {{ $field }} <input type="text" name="stages[{{ $workflow }}][{{ $key }}][labels][{{ $field }}]" size="100" maxlength="500"
                            value="{{ old("stages.$workflow.$key.labels.$field", $override['labels'][$field] ?? $definition['label']) }}"></label>
                    @endforeach
                </details>
            @endforeach
        </fieldset>
    @endforeach
    <button>Save settings</button>
</form>
</div>
@endsection
