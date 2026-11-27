<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CGMS {{ $title }}</title>
    @if(!$pdf && !($preview ?? false))
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script nonce="{{ $cspNonce }}" src="{{ asset('js/edit-locks.js') }}" defer></script>
        <script nonce="{{ $cspNonce }}" src="{{ asset('js/document-print.js') }}" defer></script>
    @endif
    @if(!$pdf && !($preview ?? false))
        <style>.document-actions { padding: 12px; background: #f3f7fa; margin-bottom: 18px; } @media print { .document-actions { display: none; } }</style>
    @endif
</head>
<body>
@if(!$pdf && !($preview ?? false))
<nav class="document-actions">
    <p id="session-state" role="status"></p>
    @include('students.lock-notice')
    <a href="{{ route('students.show', $student) }}">Back to learner</a> |
    <a href="{{ $invoice ? route('invoices.current-pdf', [$student,$invoice->id]) : route('statements.pdf',$student) }}">Download PDF</a>
    <button type="button" id="print-document">Print</button>
    <p>Current account information. Previously stored invoice PDFs are not changed by this preview.</p>
</nav>
@endif
{!! \App\Billing\DocumentTemplates::render($templateBody, get_defined_vars(), $pdf) !!}
</body>
</html>
