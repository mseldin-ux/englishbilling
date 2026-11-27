@foreach($transactions as $transaction)
    @php $chunks = $pdf ? \App\Billing\DocumentNotes::chunks($transaction->transactionnotes) : [$transaction->transactionnotes]; @endphp
    @foreach($chunks as $chunk)
    <tr><td>{{ $loop->first ? $transaction->transactiondate?->format('Y-m-d') : '' }}</td><td>{{ $loop->first ? $transaction->transactiontype : '' }}</td>
        <td class="multiline">{{ $chunk }}</td><td class="amount">{{ $loop->first ? $transaction->transactionamount : '' }}</td></tr>
    @endforeach
@endforeach
