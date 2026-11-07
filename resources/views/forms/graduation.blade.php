@extends('layouts.app')
@section('content')
@use('App\Forms\GraduationInitiation', 'G')
<script src="{{ asset('js/forms.js') }}" defer></script>
<div class="form-page">
<h2>{{ G::TITLE }}</h2>
<p class="hint">
    @if($form->exists)
        Form {{ $form->id }} — <strong>{{ $form->status === 'submitted' ? 'Submitted' : 'Draft' }}</strong>
        @if($form->submitted_at) on {{ $form->submitted_at->format('Y-m-d H:i') }} @endif
        — revision {{ $form->revision }}
        | <a href="{{ route('forms.pdf', $form) }}">Download PDF</a>
    @else
        New form — not saved yet.
    @endif
    | <a href="{{ route('forms.index') }}">Back to Forms</a>
</p>
@if($form->exists)
<div id="student-detail" data-lock-status="{{ route('forms.status', $form) }}"
    data-lock-release="{{ route('forms.release', $form) }}" data-lock-token="{{ $editLock['token'] }}"
    data-lock-owned="{{ $editLock['owned'] ? '1' : '0' }}">
<p id="edit-lock-message">{{ $editLock['owned'] ? 'Editing lock held by you.' : 'Read only — locked by '.$editLock['editor'] }}</p>
<button id="done-editing" type="button" @disabled(!$editLock['owned'])>Done Editing</button>
@endif
@if($others->isNotEmpty())
    <p class="hint" role="note">This learner has other {{ G::TITLE }} forms:
        @foreach($others as $other)<a href="{{ route('forms.edit', $other->id) }}">Form {{ $other->id }}</a> ({{ $other->status }})@if(!$loop->last), @endif @endforeach
    </p>
@endif
<form id="graduation-form" method="post" action="{{ $form->exists ? route('forms.update', $form) : route('forms.store', G::TYPE) }}">
    @csrf
    @if($form->exists)
        @method('PUT')
        <input type="hidden" name="revision" value="{{ $form->revision }}">
        <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
    @endif
    <fieldset>
        <legend>{{ G::LABELS['student_id'] }}</legend>
        <input type="hidden" name="student_id" id="learner-id" value="{{ $values['student_id'] }}">
        <p id="learner-selected" aria-live="polite">
            @if($learner)
                <strong>{{ $learner->displayName() ?: 'Unnamed learner' }}</strong> — Cohort {{ $learner->cohort ?: '(none)' }} — User ID {{ $learner->id }}
            @else
                No learner selected.
            @endif
        </p>
        <label>Search by name or User ID
            <input type="search" id="learner-search" autocomplete="off" data-url="{{ route('forms.learners') }}"
                placeholder="Type at least 2 characters" aria-controls="learner-results">
        </label>
        <ul id="learner-results" class="learner-results" role="listbox" aria-label="Matching learners"></ul>
    </fieldset>
    <fieldset>
        <legend>Program and completion</legend>
        <label>{{ G::LABELS['program_id'] }}
            <select name="program_id" id="program-id">
                <option value="">— Select a program —</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected((string) $values['program_id'] === (string) $program->id)>{{ $program->programdesc }}</option>
                @endforeach
            </select>
        </label>
        @foreach(G::QUESTIONS as $field => $question)
            <div class="yes-question">
                <span>{{ $loop->iteration }}) {{ $question }}</span>
                <label class="check"><input type="checkbox" name="{{ $field }}" value="1" @checked($values[$field])> Yes</label>
            </div>
        @endforeach
    </fieldset>
    <fieldset>
        <legend>Residency, practicum, and graduation</legend>
        <label>{{ G::LABELS['residency_location'] }}
            <input type="text" name="residency_location" maxlength="255" value="{{ $values['residency_location'] }}">
        </label>
        <label>{{ G::LABELS['residency_date'] }}
            <input type="date" name="residency_date" value="{{ $values['residency_date'] }}">
        </label>
        <label>{{ G::LABELS['practicum_passed_date'] }}
            <input type="date" name="practicum_passed_date" value="{{ $values['practicum_passed_date'] }}">
        </label>
        <label>{{ G::LABELS['graduation_date'] }}
            <input type="date" name="graduation_date" value="{{ $values['graduation_date'] }}">
        </label>
        <p class="hint">The residency and the practicum must both occur before the graduation date.</p>
    </fieldset>
    <fieldset>
        <legend>Instructor</legend>
        <label>{{ G::LABELS['instructor_email'] }}
            <input type="email" name="instructor_email" maxlength="255" value="{{ $values['instructor_email'] }}">
        </label>
        <p class="hint">Optional. The program's Level Director team address is copied automatically.</p>
    </fieldset>
    <fieldset>
        <legend>Signature</legend>
        <label>{{ G::LABELS['director_signature'] }}
            <input type="text" name="director_signature" maxlength="255" value="{{ $values['director_signature'] }}">
        </label>
        @if(!empty($form->data['signed_by']))
            <p class="hint">Signed electronically by login {{ $form->data['signed_by']['name'] }} ({{ $form->data['signed_by']['username'] }})
                on {{ \Carbon\CarbonImmutable::parse($form->data['signed_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}.</p>
        @else
            <p class="hint">Your login name and the time are recorded with the typed signature when the form is submitted.</p>
        @endif
    </fieldset>
    <p class="form-notice"><em>*{{ G::NOTICE }} *</em></p>
    @php $disabled = $form->exists && !$editLock['owned']; @endphp
    @if($form->status !== 'submitted')
        <button type="submit" name="action" value="draft" formnovalidate @disabled($disabled)>Save Draft</button>
        <button type="submit" name="action" value="submit" @disabled($disabled)>Submit Form</button>
        <p class="hint">Drafts can be saved at any time. Submitting requires every field, Yes to all three questions, and valid dates.</p>
    @else
        <button type="submit" name="action" value="submit" @disabled($disabled)>Save Changes</button>
        <p class="hint">This form has been submitted. Changes must remain complete and are recorded in the history below.</p>
    @endif
</form>
@if($form->exists && auth()->user()->is_admin)
    <form method="post" action="{{ route('forms.destroy', $form) }}" class="danger-zone">
        @csrf @method('DELETE')
        <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
        <label class="check"><input type="checkbox" name="confirm" value="1" required> Confirm deletion of form {{ $form->id }}</label>
        <button @disabled($disabled)>Delete Form (admin)</button>
    </form>
@endif
@if($form->exists)
    <details class="form-history">
        <summary>History ({{ $history->count() }} {{ \Illuminate\Support\Str::plural('entry', $history->count()) }})</summary>
        <table>
            <thead><tr><th>Revision</th><th>When</th><th>Who</th><th>Action</th><th>Status</th><th>Fields changed</th></tr></thead>
            <tbody>
            @foreach($history as $entry)
                <tr><td>{{ $entry->revision }}</td>
                    <td>{{ \Carbon\CarbonImmutable::parse($entry->created_at)->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $entry->user_name }}</td><td>{{ $entry->action }}</td><td>{{ $entry->status }}</td>
                    <td>{{ $entry->changed ? implode('; ', $entry->changed) : '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </details>
</div>
@endif
@if($form->exists)
    @include('workflows.panel')
@endif
</div>
@endsection
