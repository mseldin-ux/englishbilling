<form method="get" action="{{ route('students.index') }}" class="search-form">
    <div class="field-grid">
        <label>Keyword or Record id
            <input name="query" value="{{ $filters['query'] ?? '' }}">
        </label>
        <label>Show archived
            <input type="hidden" name="showall" value="0">
            <input type="checkbox" name="showall" value="1" @checked($filters['showall'] ?? false)>
        </label>
        <label>Graduation Date <input type="date" name="graduation_date" value="{{ $filters['graduation_date'] ?? '' }}"></label>
        <label>Payment Due Between <input type="date" name="past_due_start" value="{{ $filters['past_due_start'] ?? '' }}"></label>
        <label>and <input type="date" name="past_due_end" value="{{ $filters['past_due_end'] ?? '' }}"></label>
        <label>Payer Type <input name="payment_type" value="{{ $filters['payment_type'] ?? '' }}"></label>
        <label title="Enter 0 to find blank or null transactions.">Transaction type
            <input name="transaction_type" value="{{ $filters['transaction_type'] ?? '' }}">
        </label>
        <label>Cohort <input name="cohort" value="{{ $filters['cohort'] ?? '' }}"></label>
        <label>Program (including history)
            <select name="program_id"><option value="">All</option>
                @foreach(\App\Models\Program::orderBy('id')->get() as $searchProgram)
                    <option value="{{ $searchProgram->id }}" @selected(($filters['program_id'] ?? '') == $searchProgram->id)>{{ $searchProgram->program }}</option>
                @endforeach
            </select>
        </label>
        <label>Stage (including completed)
            <select name="stage"><option value="">All</option>@foreach(\App\Billing\Enrollments::STAGES as $number => $label)
                <option value="{{ $number }}" @selected(($filters['stage'] ?? '') == $number)>{{ $number }} — {{ $label }}</option>
            @endforeach</select>
        </label>
        <label>Program or stage started from <input type="date" name="start_from" value="{{ $filters['start_from'] ?? '' }}"></label>
        <label>through <input type="date" name="start_to" value="{{ $filters['start_to'] ?? '' }}"></label>
        <label>Stage started from <input type="date" name="stage_start_from" value="{{ $filters['stage_start_from'] ?? '' }}"></label>
        <label>through <input type="date" name="stage_start_to" value="{{ $filters['stage_start_to'] ?? '' }}"></label>
    </div>
    <fieldset><legend>Status (all selected filters must match)</legend>
        @foreach(['active'=>'Active','extension'=>'Extension','grad'=>'Graduated','ingroup'=>'In Group',
            'loa'=>'LOA','open'=>'Open Balance','pif'=>'Paid in Full','spon'=>'Sponsored','sus'=>'Suspended',
            'wd'=>'Withdrawn','flag'=>'Flagged'] as $key => $label)
            <label class="inline"><input type="checkbox" name="statuses[]" value="{{ $key }}"
                @checked(in_array($key, $filters['statuses'] ?? []))> {{ $label }}</label>
        @endforeach
    </fieldset>
    <button type="submit">Find all</button>
    <a href="{{ route('students.index', ['reset' => 1]) }}">Clear form / Browse all</a>
</form>
