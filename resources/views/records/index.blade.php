@extends('layouts.app')
@section('content')
<script src="{{ asset('js/records.js') }}" defer></script>
<div class="records-workspace">
<h2>Student Records — {{ number_format($students->total()) }} matching rows</h2>
<p><a href="{{ route('workspace.create') }}">Create student</a></p>
<form method="get" id="records-search">
    <div id="records-filters"></div>
    <button type="button" id="add-records-filter" aria-label="Add filter">+ Add field</button>
    <button>Search (all conditions)</button> <a href="{{ route('workspace.index') }}">Clear</a>
    <noscript>Enable JavaScript to use contextual search controls.</noscript>
</form>
<p>All search conditions must match. Ranges include both endpoints.
    Click Edit Row to acquire a student-wide lock. Save or Cancel releases it.
    Copying uses saved values, not unsaved drafts.</p>
<p>
    <button type="button" data-copy="selected">Copy selected rows</button>
    <button type="button" data-copy="page">Copy current page</button>
    <button type="button" data-copy="all">Copy all matching rows</button>
    <a href="{{ route('workspace.export', ['filters' => $filters]) }}">Download matching rows (TSV)</a>
</p>
<p id="records-message" role="status"></p>
<div style="overflow:auto">
<table id="records-table" data-locks="{{ route('workspace.table-locks') }}"
    data-export="{{ route('workspace.export', ['filters' => $filters]) }}">
    <thead><tr><th><input type="checkbox" id="select-page" aria-label="Select all rows on this page"></th><th>User ID</th><th>Actions / Lock</th>@foreach($fields as $field)<th>{{ $field[0] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($students as $student)
        @php $values = \App\Billing\RecordsFields::values($student); @endphp
        <tr data-id="{{ $student->id }}" data-base="{{ url('/records/'.$student->id) }}">
            <td><input type="checkbox" data-select aria-label="Select row {{ $loop->iteration }}"></td>
            <td>{{ $student->id }}</td>
            <td>
                <a href="{{ route('workspace.edit', $student) }}">View</a>
                <button type="button" data-edit>Edit Row</button>
                <button type="button" data-save hidden>Save</button>
                <button type="button" data-cancel hidden>Cancel / Done</button>
                <span data-lock>{{ isset($locks[$student->id]) ? 'Locked by '.$locks[$student->id]->name.' ('.$locks[$student->id]->username.')' : '' }}</span>
            </td>
            @foreach($fields as $name => $field)
            <td data-field="{{ $name }}">
                @if($field[1] === 'nullable_boolean')
                    <input type="checkbox" disabled @checked($values[$name]) aria-label="{{ $field[0] }}"
                        @if($values[$name] === null) title="Not specified (null)" @endif>
                @else
                    <span data-display>{{ $field[1] === 'access_status' ? ($accessStatuses->firstWhere('id', $values[$name])?->status ?? '') : $values[$name] }}</span>
                @endif
            </td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
</div>
{{ $students->links() }}
<script type="application/json" id="records-fields">@json($fields)</script>
<script type="application/json" id="records-statuses">@json($accessStatuses)</script>
<script type="application/json" id="records-search-fields">@json($searchFields)</script>
<script type="application/json" id="records-initial-filters">@json($filters)</script>
</div>
@endsection
