<form action="{{ route('reports.collections') }}" method="get">
    <div class="field-grid">
        <label>Minimum Next Payment Due <input type="number" step="0.01" name="collectionsmin" value="{{ $filters['collectionsmin'] ?? '' }}"></label>
        <label>Maximum Next Payment Due <input type="number" step="0.01" name="collectionsmax" value="{{ $filters['collectionsmax'] ?? '' }}"></label>
        <label>Next Payment Due Before <input type="date" name="next_due_date" value="{{ $filters['next_due_date'] ?? '' }}"></label>
        <label>Enrolled On or After <input type="date" name="enrolldate" value="{{ $filters['enrolldate'] ?? '' }}"></label>
        <label>Enrolled Before <input type="date" name="enrolledbeforedate" value="{{ $filters['enrolledbeforedate'] ?? '' }}"></label>
        <label class="inline"><input type="checkbox" name="hideinactive" value="1" @checked(!empty($filters['hideinactive']))> Hide Inactive</label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
