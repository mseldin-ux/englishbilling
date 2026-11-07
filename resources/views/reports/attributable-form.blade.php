<form action="{{ route('reports.attributable') }}" method="get">
    <div class="field-grid">
        <label>Start Date <input type="date" name="startdate" value="{{ $filters['startdate'] ?? '' }}" required></label>
        <label>End Date (exclusive) <input type="date" name="enddate" value="{{ $filters['enddate'] ?? '' }}" required></label>
        <label>Ignore Students Enrolled Before (optional)
            <input type="date" name="enrolldate" value="{{ $filters['enrolldate'] ?? '' }}">
        </label>
        <label class="inline"><input type="checkbox" name="usesimplefields" value="1" @checked(!empty($filters['usesimplefields']))> Fewer Fields</label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
