<label title="Matches learners in this overall program during the report period. All qualifying learner activity is included.">Program
    <select name="program_id">
        <option value="">All programs</option>
        @foreach($programs as $program)
            <option value="{{ $program->id }}" @selected((string) ($filters['program_id'] ?? '') === (string) $program->id)>{{ $program->program }}</option>
        @endforeach
    </select>
</label>
