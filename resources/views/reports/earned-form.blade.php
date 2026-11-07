<form action="{{ route('reports.earned') }}" method="get">
    <div class="field-grid">
        <label>Enrolled On or After <input type="date" name="startdate" value="{{ $filters['startdate'] ?? '' }}" required></label>
        <label>Enrolled Before (exclusive) <input type="date" name="lastpossiblestartdate" value="{{ $filters['lastpossiblestartdate'] ?? '' }}" required></label>
        <label>Earned As Of (midnight) <input type="date" name="dateasof" value="{{ $filters['dateasof'] ?? '' }}" required></label>
        <label>Sort By <select name="sort">
            @foreach(['' => 'Unsorted (ID order)', 'ID' => 'ID', 'Last Name' => 'Last Name', 'Graduation Date' => 'Graduation Date'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['sort'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label class="inline"><input type="checkbox" name="skipzero" value="yes" @checked(($filters['skipzero'] ?? '') === 'yes')> Skip Zero Service Progress (legacy “Skip Zero Balance Records”)</label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
