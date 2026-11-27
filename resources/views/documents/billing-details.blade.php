@if($invoice)
    Payment: {{ $student->pmt_type }}
    @if($reference)<p>REFERENCE: {{ $reference }}</p>@endif
    @if($invoice->batchid)<p>Batch: {{ $invoice->batchid }}</p>@endif
@endif
