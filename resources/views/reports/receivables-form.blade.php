<form action="{{ route('reports.receivables') }}" method="get">
    <div class="field-grid">
        <label>Enrolled On or After <input type="date" name="startdate" value="{{ $filters['startdate'] ?? '' }}"></label>
        <label>Receivable As Of (exclusive) <input type="date" name="enddate" value="{{ $filters['enddate'] ?? '' }}"></label>
        <label class="inline"><input type="checkbox" name="zeros" value="hidezero" @checked(($filters['zeros'] ?? '') === 'hidezero')> Hide Zero / Negative Rows (legacy)</label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
