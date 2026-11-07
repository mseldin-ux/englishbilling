@include('students.lock-notice')
@if($kind === 'invoices')
    @include('students.invoices')
@else
@php
    $fields = match($kind) {
        'transactions' => ['transactiondate'=>['Customer Date','date'], 'processeddate'=>['Our Date','date'],
            'transactiontype'=>['Type','type'], 'transactionamount'=>['Amount','money'],
            'invoice_id'=>['INVID','id'], 'transactionnotes'=>['Notes','text']],
        'loas' => ['loa_start'=>['LOA Start','date'], 'loa_end'=>['LOA End','date']],
    };
@endphp
@if($kind === 'transactions')
    <p class="ledger-summary" data-fees="{{ $summary['fees'] }}">BALANCE: {{ $student->balance ?? '0.00' }} | TOTAL FEES: {{ $summary['fees'] }} | PAID: {{ $summary['paid'] }}</p>
    <datalist id="transaction-types">@foreach($types as $t)<option value="{{ $t }}"></option>@endforeach</datalist>
    <p class="hint">Customer Date appears on statements. Our Date is the internal processed/deposit date. Amounts are signed: payments normally reduce the balance.</p>
@endif
<div class="table-scroll">
<table class="record-table">
    <thead><tr><th>ID</th>
        @foreach($fields as [$label, $type])<th>{{ $label }}</th>@endforeach
        <th>Action</th>
    </tr></thead>
    <tbody>
    @php $newForm = 'new-'.$kind; @endphp
    <tr>
        <td>NEW
            <form id="{{ $newForm }}" method="post" action="{{ route('records.store', [$student, $kind]) }}" data-ajax data-refresh="{{ $kind }}" data-dirty>
                @csrf <span class="form-status" role="status"></span>
            </form>
        </td>
        @foreach($fields as $name => [$label, $type])
        <td>
            @include('students.record-input', ['formId'=>$newForm,'value'=>'','creating'=>true])
        </td>
        @endforeach
        <td><button form="{{ $newForm }}">SAVE</button></td>
    </tr>
    @forelse($records as $record)
        @php $formId = $kind.'-'.$record->id; @endphp
        <tr>
            <td>{{ $record->id }}
                <form id="{{ $formId }}" method="post" action="{{ route('records.update', [$student, $kind, $record->id]) }}" data-ajax data-partial data-refresh="{{ $kind }}" data-dirty
                    @if($kind === 'transactions') data-old-dates="{{ substr($record->getRawOriginal('transactiondate') ?? '',0,10).','.substr($record->getRawOriginal('processeddate') ?? '',0,10) }}" @endif>
                    @csrf @method('PATCH')
                    <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($record) }}">
                    <span class="form-status" role="status"></span>
                </form>
            </td>
            @foreach($fields as $name => [$label, $type])
                @php $value = $type === 'date' ? substr($record->getRawOriginal($name) ?? '',0,10) : $record->$name; @endphp
                <td>@include('students.record-input', ['creating'=>false])</td>
            @endforeach
            <td class="record-actions">
                <button form="{{ $formId }}">SAVE</button>
                <form method="post" action="{{ route('records.destroy', [$student, $kind, $record->id]) }}" data-ajax data-refresh="{{ $kind }}" data-confirm="Really delete this record?">
                    @csrf @method('DELETE')
                    <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($record) }}">
                    <button>DELETE</button><span class="form-status" role="status"></span>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="{{ count($fields)+3 }}">No existing records.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<p>END OF {{ $kind }} LIST</p>
@endif
