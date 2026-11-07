@extends('layouts.app')
@section('content')
@use('App\Forms\FinalGrades', 'F')
<script src="{{ asset('js/forms.js') }}" defer></script>
<script src="{{ asset('js/grades.js') }}" defer></script>
@php $disabled = $sheet->exists && !$editLock['owned']; @endphp
<div class="form-page">
<h2>{{ F::TITLE }}</h2>
<p class="hint">
    @if($sheet->exists)
        Grade sheet {{ $sheet->id }} — <strong>{{ $sheet->status === 'submitted' ? 'Submitted' : 'Draft' }}</strong>
        @if($sheet->submitted_at) on {{ $sheet->submitted_at->format('Y-m-d H:i') }} @endif
        — revision {{ $sheet->revision }}
        | <a href="{{ route('forms.grades.pdf', $sheet) }}">Download PDF</a>
    @else
        New grade sheet — not saved yet.
    @endif
    | <a href="{{ route('forms.index') }}">Back to Forms</a>
</p>
<div id="existing-sheets" class="existing-sheets" role="status" aria-live="polite"
    data-url="{{ route('forms.grades.existing') }}" data-current="{{ $sheet->id }}" @if(!$existing) hidden @endif>
    @if($existing)
        <p><strong>{{ $sheet->exists ? 'This learner has other grade sheets:' : 'This learner already has grade sheets. Open an existing sheet rather than starting over:' }}</strong></p>
        <ul>
            @foreach($existing as $other)
                <li data-program="{{ $other['program_id'] }}"><a href="{{ $other['url'] }}">Sheet {{ $other['id'] }} — {{ $other['program'] }} — {{ $other['status'] === 'submitted' ? 'Submitted' : 'Draft' }}, updated {{ $other['updated_at'] }}</a>@if($loop->first) (most recent)@endif</li>
            @endforeach
        </ul>
    @endif
</div>
@if($sheet->exists)
<div id="student-detail" data-lock-status="{{ route('forms.grades.status', $sheet) }}"
    data-lock-release="{{ route('forms.grades.release', $sheet) }}" data-lock-token="{{ $editLock['token'] }}"
    data-lock-owned="{{ $editLock['owned'] ? '1' : '0' }}">
<p id="edit-lock-message">{{ $editLock['owned'] ? 'Editing lock held by you.' : 'Read only — locked by '.$editLock['editor'] }}</p>
<button id="done-editing" type="button" @disabled(!$editLock['owned'])>Done Editing</button>
@endif
<form id="grades-form" method="post" action="{{ $sheet->exists ? route('forms.grades.update', $sheet) : route('forms.grades.store') }}">
    @csrf
    @if($sheet->exists)
        @method('PUT')
        <input type="hidden" name="revision" value="{{ $sheet->revision }}">
        <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
    @endif
    <fieldset>
        <legend>Adult Learner and program</legend>
        @if($sheet->exists)
            <p><strong>{{ $learner?->displayName() ?: 'Unnamed learner' }}</strong> — Cohort {{ $learner?->cohort ?: '(none)' }} — User ID {{ $sheet->studentid }}</p>
            <p>Program: <strong>{{ $programs->first()?->programdesc }}</strong></p>
            <p class="hint">The learner and program cannot be changed after a sheet is saved. An admin can delete a sheet started for the wrong learner or program.</p>
        @else
            <input type="hidden" name="studentid" id="learner-id" value="{{ $values['studentid'] }}">
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
            <label>{{ F::LABELS['program_id'] }}
                <select name="program_id" id="program-id">
                    <option value="">— Select a program —</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}" @selected((string) $values['program_id'] === (string) $program->id)>{{ $program->programdesc }}</option>
                    @endforeach
                </select>
            </label>
            <p id="duplicate-sheet" class="warning" role="alert" hidden></p>
        @endif
    </fieldset>
    <fieldset>
        <legend>Grades</legend>
        @unless($sheet->exists)<p class="hint" id="choose-program" @if($values['program_id']) hidden @endif>Select a program to show its graded items.</p>@endunless
        @foreach($items as $programId => $programItems)
            @php
                $shown = (string) $programId === (string) $values['program_id'];
                $hours = $programItems->contains(fn ($item) => $item->clockhours !== null);
            @endphp
            <div class="grade-items" data-program="{{ $programId }}" @unless($shown) hidden @endunless>
                <table class="grades-table">
                    <thead><tr><th>#</th><th>Graded item</th>@if($hours)<th>Clock hours</th>@endif<th>Grade</th></tr></thead>
                    <tbody>
                    @foreach($programItems as $item)
                        <tr>
                            <td>{{ $item->itemorder }}</td>
                            <td><label for="grade-{{ $item->id }}">{{ $item->gradeitem }}</label></td>
                            @if($hours)<td>{{ $item->clockhours }}</td>@endif
                            <td><input type="text" id="grade-{{ $item->id }}" name="grades[{{ $item->id }}]" maxlength="64"
                                value="{{ $grades[$item->id] ?? '' }}" @unless($shown) disabled @endunless
                                @error('grades.'.$item->id) aria-invalid="true" @enderror></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
        <p class="hint">Enter grades in whatever form the program uses: number, letter, Pass/Fail, and so on.</p>
    </fieldset>
    <fieldset>
        <legend>{{ F::LABELS['gradenotes'] }}</legend>
        <label for="gradenotes" class="visually-hidden">Notes about this Adult Learner</label>
        <textarea id="gradenotes" name="gradenotes" rows="5" cols="80" maxlength="65000">{{ $values['gradenotes'] }}</textarea>
    </fieldset>
    <fieldset>
        <legend>Signature</legend>
        <label>{{ F::LABELS['gradername'] }}
            <input type="text" name="gradername" maxlength="255" value="{{ $values['gradername'] }}">
        </label>
        <label>{{ F::LABELS['gradedate'] }}
            <input type="date" name="gradedate" value="{{ $values['gradedate'] }}">
        </label>
        @if($sheet->signed_at)
            <p class="hint">Signed electronically by login {{ $signer?->name ?? 'a deleted user' }}@if($signer) ({{ $signer->username }})@endif
                on {{ $sheet->signed_at->format('Y-m-d H:i') }}.</p>
        @else
            <p class="hint">Your login name and the time are recorded with the typed signature when the sheet is submitted.</p>
        @endif
    </fieldset>
    @if($sheet->status !== 'submitted')
        <button type="submit" name="action" value="draft" formnovalidate @disabled($disabled)>Save Draft</button>
        <button type="submit" name="action" value="submit" @disabled($disabled)>Submit Grades</button>
        <p class="hint">Drafts can be saved at any time. Submitting requires a grade for every item, your typed name, and the date.</p>
    @else
        <button type="submit" name="action" value="submit" @disabled($disabled)>Save Changes</button>
        <p class="hint">This sheet has been submitted. Changes must remain complete and are recorded in the history below.</p>
    @endif
</form>
@if($sheet->exists && auth()->user()->is_admin)
    <form method="post" action="{{ route('forms.grades.destroy', $sheet) }}" class="danger-zone">
        @csrf @method('DELETE')
        <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
        <label class="check"><input type="checkbox" name="confirm" value="1" required> Confirm deletion of grade sheet {{ $sheet->id }}</label>
        <button @disabled($disabled)>Delete Grade Sheet (admin)</button>
    </form>
@endif
@if($sheet->exists)
    <details class="form-history">
        <summary>History ({{ $history->count() }} {{ \Illuminate\Support\Str::plural('entry', $history->count()) }})</summary>
        <table>
            <thead><tr><th>Revision</th><th>When</th><th>Who</th><th>Action</th><th>Status</th><th>Changed</th></tr></thead>
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
</div>
@endsection
