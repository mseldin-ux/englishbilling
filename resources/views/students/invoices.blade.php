<p class="hint">HTML and Current PDF show the current invoice and ledger. Stored PDF preserves the document saved when the invoice was created.</p>
<div class="table-scroll">
<table class="record-table">
    <thead><tr><th>ID</th><th>Creator</th><th>Sent</th><th>Due</th><th>Amount</th><th>Notes</th><th>Reference</th><th>Urgent Msg</th><th>Actions</th></tr></thead>
    <tbody>
    @forelse($records as $invoice)
        <tr>
            <td>{{ $invoice->id }}</td><td>{{ $invoice->user_sent }}</td>
            <td>{{ $invoice->datesent?->format('Y-m-d') }}</td><td>{{ $invoice->nextdate?->format('Y-m-d') }}</td>
            <td>{{ $invoice->amount }}</td><td class="invoice-notes">{{ $invoice->notes }}</td>
            <td>{{ $invoice->invoicereference }}</td><td>{{ $invoice->urgent_message }}</td>
            <td class="record-actions">
                <button type="button" data-open="invoice-dialog-{{ $invoice->id }}">Edit Invoice</button>
                <a target="_blank" rel="noopener" href="{{ route('invoices.html', [$student,$invoice->id]) }}">HTML</a>
                <a target="_blank" rel="noopener" href="{{ route('invoices.current-pdf', [$student,$invoice->id]) }}">Download Current PDF</a>
                @if($invoice->has_pdf)<a target="_blank" rel="noopener" href="{{ route('invoices.pdf', [$student,$invoice->id]) }}">Stored PDF</a>@else<span>No stored PDF</span>@endif
                <button type="button" data-pay-invoice="{{ $invoice->id }}" data-pay-amount="{{ $invoice->amount }}">Payment</button>
                <form method="post" action="{{ route('records.destroy', [$student,'invoices',$invoice->id]) }}" data-ajax data-refresh="invoices" data-confirm="Really delete this invoice? Linked transactions will be kept and unlinked.">
                    @csrf @method('DELETE')
                    <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($invoice) }}">
                    <button>DELETE</button><span class="form-status" role="status"></span>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="9">No existing records.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@include('students.invoice-dialog', ['invoice' => null])
@foreach($records as $invoice)
    @include('students.invoice-dialog')
@endforeach
<p>END OF invoices LIST</p>
