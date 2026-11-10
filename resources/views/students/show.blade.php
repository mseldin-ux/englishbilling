@extends('layouts.app')
@section('content')
<div id="student-detail" data-lock-status="{{ route('locks.status', $student) }}"
    data-lock-release="{{ route('locks.release', $student) }}" data-lock-token="{{ $editLock['token'] }}"
    data-lock-owned="{{ $editLock['owned'] ? '1' : '0' }}">
<div id="edit-lock-banner" role="status">
    <span id="edit-lock-message">{{ $editLock['owned'] ? 'Editing lock held by you. All student records are protected.' : ($editLock['editor'] ? 'Read only — locked by '.$editLock['editor'] : 'Read only — reload to obtain an editing lock.') }}</span>
    <button type="button" id="done-editing" @disabled(!$editLock['owned'])>Done Editing</button>
    <a href="{{ route('students.show', $student) }}">Reload / Obtain Editing Lock</a>
</div>
<p class="record-navigation">
    Control-R: <button type="button" data-open="find-dialog">find</button> a record |
    @if($previous)<a id="previous-record" href="{{ route('students.show', $previous) }}">Control-P: previous</a>@endif |
    @if($next)<a id="next-record" href="{{ route('students.show', $next) }}">Control-N: next</a>@endif |
    <a id="list-records" href="{{ route('students.index', ['page' => $listPage]) }}">Control-L: list</a>
</p>
<h2>{{ $student->displayName() ?: 'Unnamed Learner' }} — Record {{ $student->id }}</h2>
<p>Status: {{ $student->status() }} | Account: {{ $student->pif ? 'PIF' : 'Open' }}</p>
<p><a href="{{ route('statements.html', $student) }}" target="_blank" rel="noopener">HTML Statement</a> |
    <a href="{{ route('statements.pdf', $student) }}" target="_blank" rel="noopener">Statement PDF</a></p>
@if($planned)
    <p>Planned payment found: ${{ $planned->paymentamount }} on {{ $planned->paymentduedate->format('m/d/Y') }}.
        @if($plannedInvoiceExists)
            Found matching invoice.
        @else
            No matching invoice.
            <button type="button" data-planned-amount="{{ $planned->paymentamount }}" data-planned-date="{{ $planned->paymentduedate->format('Y-m-d') }}" data-planned-notes="{{ $planned->paymentnotes }}">Create Invoice</button>
        @endif
    </p>
@endif
<form id="student-form" method="post" action="{{ route('students.update', $student) }}" data-ajax data-partial data-dirty>
    @csrf @method('PATCH')
    <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
    <div class="save-bar"><button>Save All Changes</button><span class="form-status" role="status">No changes to save</span></div>
    @include('students.fields')
    <button>Save All Changes</button>
</form>

@foreach(['loas'=>'Leaves of Absence','transactions'=>'Transactions','invoices'=>'Invoices'] as $kind => $heading)
<section id="{{ $kind }}" data-load="{{ route('records.index', [$student, $kind]) }}">
    <h2>{{ $heading }}</h2>
    <button type="button" data-load-section>{{ $kind === 'transactions' ? 'Refresh' : 'Load' }} {{ $heading }}</button>
    @if($kind === 'invoices')
        <button type="button" data-create-invoice>Create New Invoice</button>
        <a class="statement-button" href="{{ route('statements.pdf', $student) }}" target="_blank" rel="noopener">Statement PDF</a>
    @endif
    <div class="section-status" role="status"></div>
    <div class="section-content">
        @if($kind === 'transactions')
            @include('students.records', ['records'=>$transactions])
        @else
            <p>Click Load to view and edit records.</p>
        @endif
    </div>
</section>
@endforeach
<section id="schedule" data-load="{{ route('schedule.show', $student) }}">
    <h2>Payment Plans</h2><button type="button" data-load-section>Load Payment Plan</button>
    <div class="section-status" role="status"></div>
    <div class="section-content"><p>Load to set up or edit this learner's payments.</p></div>
</section>
</div>
@endsection
