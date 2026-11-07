<form action="{{ route('reports.transactions') }}" method="get">
    <div class="field-grid">
        <label>Minimum Signed Amount <input type="number" step="0.01" name="transmin" value="{{ $filters['transmin'] ?? '' }}"></label>
        <label>Maximum Signed Amount <input type="number" step="0.01" name="transmax" value="{{ $filters['transmax'] ?? '' }}"></label>
        <label>Starting Processed Date <input type="date" name="startdate" value="{{ $filters['startdate'] ?? '' }}"></label>
        <label>Processed Before Date (exclusive) <input type="date" name="enddate" value="{{ $filters['enddate'] ?? '' }}"></label>
        <label>Transaction Type
            <select name="whichtransactions">
                @foreach(['all' => 'All transactions', 'feesonly' => 'Fees only', 'paymentsonly' => 'Payments only'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['whichtransactions'] ?? 'all') === $value)>{{ $label }}</option>
                @endforeach
                @foreach($transactionTypes as $type)
                    <option value="{{ $type }}" @selected(($filters['whichtransactions'] ?? '') === $type)>-- {{ $type }}</option>
                @endforeach
                @if(!empty($filters['whichtransactions']) && !in_array($filters['whichtransactions'], [...$transactionTypes, 'all', 'feesonly', 'paymentsonly'], true))
                    <option value="{{ $filters['whichtransactions'] }}" selected>-- {{ $filters['whichtransactions'] }}</option>
                @endif
            </select>
        </label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
