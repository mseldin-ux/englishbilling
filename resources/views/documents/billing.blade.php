<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CGMS {{ $title }}</title>
    @if(!$pdf)
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script src="{{ asset('js/edit-locks.js') }}" defer></script>
    @endif
    <style>{!! file_get_contents(public_path('css/documents.css')) !!}</style>
</head>
<body>
@if(!$pdf)
<nav class="document-actions">
    <p id="session-state" role="status"></p>
    @include('students.lock-notice')
    <a href="{{ route('students.show', $student) }}">Back to learner</a> |
    <a href="{{ $invoice ? route('invoices.current-pdf', [$student,$invoice->id]) : route('statements.pdf',$student) }}">Download PDF</a>
    <button type="button" onclick="window.print()">Print</button>
    <p>Current account information. Previously stored invoice PDFs are not changed by this preview.</p>
</nav>
@endif
<table class="document-header"><tr>
    <td><img class="logo" src="{{ $pdf ? public_path('images/cgms-logo.png') : asset('images/cgms-logo.png') }}" alt="The Center for Guided Montessori Studies"></td>
    <td class="contact">9650 Strickland Rd Suite 103-127<br>Raleigh, NC 27615<br>(888) 344-7897<br>www.cgms.edu<br>info@cgms.edu</td>
</tr></table>
<h1 class="titlebar">{{ $title }}</h1>
<aside class="sidebar">
    <div class="box">
        <h3>{{ $invoice ? 'Invoice Info' : 'Statement Info' }}</h3>
        @if($invoice)
            {{ $invoice->id }}-{{ $student->last_name }}-{{ $generatedAt->format('n-j-Y') }}
        @else
            Account information believed to be accurate as of {{ $generatedAt->format('n/j/Y') }}
        @endif
    </div>
    <div class="box">
        <h3>Student Info</h3>Adult Learner<br>{{ $student->displayName() }}<br>
        {{ $student->streetaddress }}<br>
        @if($student->address_line_2){{ $student->address_line_2 }}<br>@endif
        {{ $student->city }}, {{ $student->state }} {{ $student->zipcode }}<br>{{ $student->emailaddr }}
        <p><b>Student ID:</b> {{ $invoice ? $student->id : $student->stid }}<br>
        <b>Cohort:</b> {{ $student->cohort }}<br>
        <b>Start Date:</b> {{ $student->start_date?->format('n/j/Y') }}<br>
        <b>Grad Date:</b> {{ $student->graddate?->format('n/j/Y') }}<br>
        <b>Status:</b> {{ $student->status() }}</p>
    </div>
</aside>
<div class="content">
    <table class="billing-recipient"><tr><td>
        <h3>{{ $invoice ? 'BILL TO:' : 'ACCOUNT:' }}</h3>
        <div>{{ $recipient }}</div><div class="multiline">{{ $address }}</div><div>{{ $email }}</div>
    </td><td>
        @if($invoice)
            Payment: {{ $student->pmt_type }}
            @if($reference)<p>REFERENCE: {{ $reference }}</p>@endif
            @if($invoice->batchid)<p>Batch: {{ $invoice->batchid }}</p>@endif
        @endif
    </td></tr></table>
    @if(!$invoice)<h3>TRANSACTIONS</h3>@endif
    <table class="ledger" id="billingtable">
        <thead><tr><th class="date">Date</th><th>Event</th><th>Description</th><th class="amount">Amount</th></tr></thead>
        <tbody>
        @foreach($transactions as $transaction)
            @php $chunks = $pdf ? \App\Billing\DocumentNotes::chunks($transaction->transactionnotes) : [$transaction->transactionnotes]; @endphp
            @foreach($chunks as $chunk)
            <tr><td>{{ $loop->first ? $transaction->transactiondate?->format('Y-m-d') : '' }}</td><td>{{ $loop->first ? $transaction->transactiontype : '' }}</td>
                <td class="multiline">{{ $chunk }}</td><td class="amount">{{ $loop->first ? $transaction->transactionamount : '' }}</td></tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
    <p class="balance">Balance: {{ $balance }}</p>
    <div class="payment-notice">
        <h3>PAYMENT DUE: <span class="due_amount">{{ $amountDue }}</span></h3>
        <h3>DUE DATE: <span class="due_date">{{ $due }}</span></h3>
        @if($invoice?->urgent_message)<p class="urgent multiline">{{ $invoice->urgent_message }}</p>@endif
        @if($notes)<p class="multiline"><b>NOTE:</b> {{ $notes }}</p>@endif
    </div>
    <p>PLEASE MAIL PAYMENT TO:<br>9650 Strickland Rd Suite 103-127, Raleigh, NC 27615</p>
</div>
<footer>Please direct invoice-related questions to finance@cgms.edu. Make checks to Center for Guided Montessori Studies.</footer>
</body>
</html>
