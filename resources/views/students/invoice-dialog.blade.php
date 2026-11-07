@php
    $creating = $invoice === null;
    $formId = $creating ? 'new-invoices' : 'invoices-'.$invoice->id;
    $dialogId = $creating ? 'new-invoice-dialog' : 'invoice-dialog-'.$invoice->id;
@endphp
<dialog id="{{ $dialogId }}" class="invoice-dialog" aria-labelledby="{{ $dialogId }}-title">
    <h2 id="{{ $dialogId }}-title">{{ $creating ? 'Create New Invoice' : 'Edit Invoice '.$invoice->id }}</h2>
    <form id="{{ $formId }}" method="post"
        action="{{ $creating ? route('records.store', [$student, 'invoices']) : route('records.update', [$student, 'invoices', $invoice->id]) }}"
        data-ajax data-refresh="invoices" data-dirty @if(!$creating) data-partial
        data-old-dates="{{ substr($invoice->getRawOriginal('datesent') ?? '',0,10) }},{{ substr($invoice->getRawOriginal('nextdate') ?? '',0,10) }}" @endif>
        @csrf
        @if(!$creating)
            @method('PATCH')
            <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($invoice) }}">
        @endif
        <div class="invoice-editor-fields">
            <label>Sent <input name="datesent" type="date" value="{{ $creating ? today()->format('Y-m-d') : $invoice->datesent?->format('Y-m-d') }}"></label>
            <label>Due <input name="nextdate" type="date" value="{{ $creating ? $student->next_due_date?->format('Y-m-d') : $invoice->nextdate?->format('Y-m-d') }}" @required($creating)></label>
            <label>Amount <input name="amount" inputmode="decimal" value="{{ $creating ? $student->payment_amount_due : $invoice->amount }}" @required($creating)></label>
            <label>Reference <input name="invoicereference" maxlength="255" value="{{ $creating ? $student->invoicenumber : $invoice->invoicereference }}" @required($creating)></label>
            <label>Batch ID <input name="batchid" maxlength="128" value="{{ $invoice?->batchid }}"></label>
            <label>Urgent message <input name="urgent_message" maxlength="255" value="{{ $invoice?->urgent_message }}"></label>
            <label class="wide-field">Notes (up to 255 characters, matching the current database)
                <textarea name="notes" rows="6" maxlength="255">{{ $creating ? $student->invoice_notes : $invoice->notes }}</textarea>
            </label>
        </div>
        <p class="hint">{{ $creating ? 'Saving creates the invoice and its PDF together. Rendering errors will leave no partial invoice.' : 'Edits retain the stored PDF. HTML and Current PDF reflect the latest values; Stored PDF is unchanged.' }}</p>
        <div class="dialog-actions"><button>{{ $creating ? 'Create Invoice' : 'Save Invoice' }}</button>
            <button type="button" data-close-dialog>Close</button><span class="form-status" role="status">No changes to save</span></div>
    </form>
</dialog>
