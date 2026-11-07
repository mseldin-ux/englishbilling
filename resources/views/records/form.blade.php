@extends('layouts.app')
@section('content')
<script src="{{ asset('js/records.js') }}" defer></script>
<h2>{{ $student->exists ? 'Edit student records fields' : 'Create student' }}</h2>
<p>User ID: <strong>{{ $student->exists ? $student->id : 'Assigned when created' }}</strong></p>
<p><a href="{{ route('workspace.index') }}">Back to table</a></p>
@if($student->exists)
<div id="student-detail" data-lock-status="{{ route('workspace.status', $student) }}"
    data-lock-release="{{ route('workspace.release', $student) }}" data-lock-token="{{ $editLock['token'] }}"
    data-lock-owned="{{ $editLock['owned'] ? '1' : '0' }}">
<p id="edit-lock-message">{{ $editLock['owned'] ? 'Editing lock held by you.' : 'Read only — locked by '.$editLock['editor'] }}</p>
<button id="done-editing" type="button" @disabled(!$editLock['owned'])>Done Editing</button>
@endif
<form data-records-form method="post" action="{{ $student->exists ? route('workspace.update', $student) : route('workspace.store') }}">
    @csrf
    @if($student->exists)
        @method('PATCH')
        <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
        <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
    @endif
    @include('students.fieldset', ['heading' => 'Student records', 'recordsCheckboxes' => true])
    <button @disabled($student->exists && !$editLock['owned'])>Save</button>
</form>
@if($student->exists)</div>@endif
@endsection
