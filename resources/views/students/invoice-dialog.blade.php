@php
    $creating = $invoice === null;
    $formId = $creating ? 'new-invoices' : 'invoices-'.$invoice->id;
    $dialogId = $creating ? 'new-invoice-dialog' : 'invoice-dialog-'.$invoice->id;
@endphp
<dialog id="{{ $dialogId }}" class="invoice-dialog" aria-labelledby="{{ $dialogId }}-title">
    <h2 id="{{ $dialogId }}-title">{{ $creating ? 'Create New Invoice' : 'Edit Invoice '.$invoice->id }}</h2>
    <form id="{{ $formId }}" method="post"
        action="{{ $creating ? route('records.store', [$student, 'invoices']) : route('records.update', [$student, 'invoices', $invoice->id]) }}"
        data-ajax data-refresh="invoices" data-dirty
        @if($creating) data-email-preview-url="{{ route('invoices.email-preview', $student) }}" @endif
        @if(!$creating) data-partial
        data-old-dates="{{ substr($invoice->getRawOriginal('datesent') ?? '',0,10) }},{{ substr($invoice->getRawOriginal('nextdate') ?? '',0,10) }}" @endif>
        @csrf
        @if(!$creating)
            @method('PATCH')
            <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($invoice) }}">
        @endif
        <fieldset class="invoice-editor-fields" data-invoice-fields>
            @if($student->current_enrollment_id)
                <label>Program / Stage @include('students.billing-period', ['periodRecord' => $invoice, 'periodForm' => $formId])</label>
            @endif
            <label>Sent <input name="datesent" type="date" value="{{ $creating ? today()->format('Y-m-d') : $invoice->datesent?->format('Y-m-d') }}"></label>
            <label>Due <input name="nextdate" type="date" value="{{ $creating ? $student->next_due_date?->format('Y-m-d') : $invoice->nextdate?->format('Y-m-d') }}" @required($creating)></label>
            <label>Amount <input name="amount" inputmode="decimal" value="{{ $creating ? $student->payment_amount_due : $invoice->amount }}" @required($creating)></label>
            @include('students.stage-allocations', ['allocationForm' => $formId, 'allocationRecord' => $invoice])
            <label>Reference <input name="invoicereference" maxlength="255" value="{{ $creating ? $student->invoicenumber : $invoice->invoicereference }}" @required($creating)></label>
            <label>Batch ID <input name="batchid" maxlength="128" value="{{ $invoice?->batchid }}"></label>
            <label>Urgent message <input name="urgent_message" maxlength="255" value="{{ $invoice?->urgent_message }}"></label>
            <label class="wide-field">Notes (up to 255 characters, matching the current database)
                <textarea name="notes" rows="6" maxlength="255">{{ $creating ? $student->invoice_notes : $invoice->notes }}</textarea>
            </label>
        </fieldset>
        @if($creating)
            <label><input type="checkbox" name="email_invoice" value="1"> Email the stored invoice PDF after creating it</label>
            <div data-invoice-email-fields hidden>
                <input type="hidden" name="email_previewed" value="1">
                <input type="hidden" name="email_is_html" value="0">
                <p>From: <span data-email-from>{{ auth()->user()->email ?: 'No email in your user profile' }}</span><br>
                    To: <span data-email-to>{{ $student->emailaddr ?: 'No learner email recorded' }}</span></p>
                <label>Subject <input name="email_subject" maxlength="255"></label>
                <label>Message <textarea name="email_message" rows="6" maxlength="20000"></textarea></label>
                <p class="hint" data-email-format></p>
                <button type="button" data-refresh-email-preview>Refresh from template</button>
                <p class="hint">This is an editable preview of the <a href="{{ route('templates.edit', \App\Billing\InvoiceEmail::KEY) }}" target="_blank" rel="noopener">invoice email template</a>.
                    Only {invoice_id} waits until the invoice is saved. If you change invoice details after preparing the preview, refresh from the template or update the message yourself.</p>
                <p class="hint">Uses the learner's saved email addresses, separated by commas. Save any contact changes first. Only the send date is recorded; later bounces are handled through the sender's mailbox.</p>
            </div>
        @endif
        <p class="hint">{{ $creating ? 'Saving creates the invoice and its PDF together. Rendering errors will leave no partial invoice.' : 'Edits retain the stored PDF. HTML and Current PDF reflect the latest values; Stored PDF is unchanged.' }}</p>
        <div class="dialog-actions"><button>{{ $creating ? 'Create Invoice' : 'Save Invoice' }}</button>
            <button type="button" data-close-dialog>Close</button><span class="form-status" role="status">No changes to save</span></div>
    </form>
    @if(!$creating && $invoice->has_pdf)
        <details data-invoice-email>
            <summary>Email stored PDF{{ $invoice->emailed_at ? ' again' : '' }}</summary>
            <p class="hint">Emails the original stored PDF, not unsaved edits or Current PDF. Last emailed: {{ $invoice->emailed_at?->format('Y-m-d H:i') ?? 'Not emailed' }}.</p>
            <form method="post" action="{{ route('invoices.email', [$student, $invoice->id]) }}" data-ajax data-refresh="invoices" data-dirty
                data-invoice-email data-invoice-id="{{ $invoice->id }}" data-email-preview-url="{{ route('invoices.email-preview', $student) }}"
                @if($invoice->emailed_at) data-confirm="This invoice has already been emailed. Send it again?" @endif>
                @csrf
                <input type="hidden" name="email_previewed" value="1">
                <input type="hidden" name="email_is_html" value="0">
                <p>From: <span data-email-from>{{ auth()->user()->email ?: 'No email in your user profile' }}</span><br>
                    To: <span data-email-to>{{ $student->emailaddr ?: 'No learner email recorded' }}</span></p>
                <label>Subject <input name="email_subject" maxlength="255" required></label>
                <label>Message <textarea name="email_message" rows="6" maxlength="20000" required></textarea></label>
                <p class="hint" data-email-format></p>
                <button type="button" data-refresh-email-preview>Refresh from template</button>
                <p class="hint">This editable preview uses saved invoice values. Edit it for this email, or change the <a href="{{ route('templates.edit', \App\Billing\InvoiceEmail::KEY) }}" target="_blank" rel="noopener">shared invoice email template</a>.
                    The attached stored PDF is unchanged.</p>
                <button>Email stored PDF</button><span class="form-status" role="status"></span>
            </form>
        </details>
    @endif
</dialog>
