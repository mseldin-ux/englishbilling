@extends('layouts.app')
@section('content')
<h2>Editing Locks and Session Timeout</h2>
<form action="{{ route('locks.settings') }}" method="post">
    @csrf @method('PUT')
    <label>Inactivity Timeout (minutes, 1–1440)
        <input type="number" name="idle_minutes" min="1" max="1440" required value="{{ $minutes }}">
    </label>
    <button>Save Timeout</button>
</form>
<p>Only user interactions keep sessions active. Automatic status checks do not.
    A lock covers the student and every associated transaction, invoice, LOA, and schedule.
    Forms have their own locks, listed separately below.
    Forced release invalidates the editor's current page; it does not log them out.</p>
<form action="{{ route('locks.force-release') }}" method="post">
    @csrf
    <label><input type="checkbox" name="confirm" value="1" required> Confirm release of all student and form locks</label>
    <button>Release All Locks</button>
</form>
<table>
    <thead><tr><th>Student</th><th>Editor</th><th>Acquired</th><th>Expires</th><th>Action</th></tr></thead>
    <tbody>
    @forelse($locks as $lock)
        <tr><td>Record {{ $lock->student_id }} — {{ $lock->first_name }} {{ $lock->last_name }}</td>
            <td>{{ $lock->name }} ({{ $lock->username }})</td>
            <td>{{ \Carbon\CarbonImmutable::createFromTimestamp($lock->acquired_at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
            <td>{{ \Carbon\CarbonImmutable::createFromTimestamp($lock->last_activity + $minutes * 60)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
            <td><form method="post" action="{{ route('locks.force-release') }}">
                @csrf <input type="hidden" name="student_id" value="{{ $lock->student_id }}">
                <input type="hidden" name="token" value="{{ $lock->token }}">
                <label><input type="checkbox" name="confirm" value="1" required> Confirm</label><button>Release Lock</button>
            </form></td></tr>
    @empty <tr><td colspan="5">No active editing locks.</td></tr>
    @endforelse
    </tbody>
</table>
<h3>Form Locks</h3>
<table>
    <thead><tr><th>Form</th><th>Editor</th><th>Acquired</th><th>Expires</th><th>Action</th></tr></thead>
    <tbody>
    @forelse($formLocks as $lock)
        <tr><td><a href="{{ $lock->form_kind === 'finalgrades' ? route('forms.grades.edit', $lock->form_id) : route('forms.edit', $lock->form_id) }}">{{ \App\Forms\FormLocks::KINDS[$lock->form_kind] }} {{ $lock->form_id }}</a> — {{ $lock->first_name }} {{ $lock->last_name }}</td>
            <td>{{ $lock->name }} ({{ $lock->username }})</td>
            <td>{{ \Carbon\CarbonImmutable::createFromTimestamp($lock->acquired_at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
            <td>{{ \Carbon\CarbonImmutable::createFromTimestamp($lock->last_activity + $minutes * 60)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
            <td><form method="post" action="{{ route('locks.force-release') }}">
                @csrf <input type="hidden" name="form_kind" value="{{ $lock->form_kind }}">
                <input type="hidden" name="form_id" value="{{ $lock->form_id }}">
                <input type="hidden" name="token" value="{{ $lock->token }}">
                <label><input type="checkbox" name="confirm" value="1" required> Confirm</label><button>Release Lock</button>
            </form></td></tr>
    @empty <tr><td colspan="5">No active form locks.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
