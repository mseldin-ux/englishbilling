@extends('layouts.app')
@section('content')
<h2>{{ number_format($students->total()) }} record(s), page {{ $students->currentPage() }} of {{ $students->lastPage() }}</h2>
<form method="get" action="{{ route('students.index') }}" class="inline">
    <label class="inline">Jump to page number <input class="compact" name="page" type="number" min="1" value="{{ $students->currentPage() }}"></label>
    <button>Go</button>
</form>
<div class="table-scroll">
<table class="browse">
    <thead><tr><th>Id</th><th>Cohort</th><th>Last Name</th><th>First Name</th><th>Email</th><th>Status</th><th>Account</th><th>Balance</th><th>Flag</th><th>Archive</th></tr></thead>
    <tbody>
    @forelse($students as $student)
        <tr data-href="{{ route('students.show', $student) }}">
            <td><a href="{{ route('students.show', $student) }}">{{ $student->id }}</a>
                @if(isset($editingLocks[$student->id]))<span class="hint">Locked by {{ $editingLocks[$student->id]->name }} ({{ $editingLocks[$student->id]->username }})</span>@endif
            </td>
            <td>{{ $student->cohort }}</td><td>{{ $student->last_name }}</td><td>{{ $student->first_name }}</td>
            <td>{{ $student->emailaddr }}</td><td>{{ $student->status() }}</td><td>{{ $student->pif ? 'PIF' : 'Open' }}</td>
            <td><a href="{{ route('statements.html', $student) }}" target="_blank" rel="noopener">{{ $student->balance ?? '0.00' }}</a></td><td>{{ $student->flag > 0 ? 'FLAGGED' : '' }}</td>
            <td>
                @if($student->archived || ($student->inactive && abs((float) $student->balance) < 1))
                <form method="post" action="{{ route('students.archive', $student) }}" data-confirm="Really {{ $student->archived ? 'unarchive' : 'archive' }} learner {{ $student->id }}?">
                    @csrf <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
                    <input type="hidden" name="archived" value="{{ $student->archived ? '0' : '1' }}">
                    <button @disabled(isset($editingLocks[$student->id]))>{{ $student->archived ? 'Unarchive' : 'Archive' }}</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="10">No matching learners found.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<nav aria-label="Browse pages">
    @if($students->previousPageUrl())<a href="{{ $students->previousPageUrl() }}">Previous</a>@endif
    @if($students->nextPageUrl())<a href="{{ $students->nextPageUrl() }}">Next</a>@endif
</nav>
<p>END OF Student LIST</p>
<details><summary>Use this form to find student records</summary>@include('students.search')</details>
@endsection
