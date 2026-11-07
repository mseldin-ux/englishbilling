<form action="{{ route('reports.expected') }}" method="get">
    <div class="field-grid">
        <label>Payment Due Start Date <input type="date" name="startdate" value="{{ $filters['startdate'] ?? '' }}"></label>
        <label>End Date (exclusive) <input type="date" name="enddate" value="{{ $filters['enddate'] ?? '' }}"></label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
