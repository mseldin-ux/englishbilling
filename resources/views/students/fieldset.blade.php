<fieldset><legend>{{ $heading }}</legend>
    <div class="field-grid">
    @php $column = 0; @endphp
    @foreach($fields as $name => $field)
        @php
            $raw = $student->exists ? $student->getRawOriginal($name) : ($student->getAttributes()[$name] ?? null);
            $formatted = match ($field[1]) {
                'date' => $raw ? substr($raw, 0, 10) : '',
                'datetime' => $raw ? str_replace(' ', 'T', substr($raw, 0, 19)) : '',
                default => $raw,
            };
            $value = old($name, $formatted);
            $help = ($field[1] === 'checkbox' || ($field[1] === 'nullable_boolean' && ($recordsCheckboxes ?? false)))
                ? (\App\Billing\StudentFields::CHECKBOX_HELP[$name] ?? null) : null;
            $wide = $field[1] === 'textarea';
            $alignEnd = ! $wide && $column === 1;
            $column = $wide ? 0 : 1 - $column;
        @endphp
        <div class="field-row {{ $wide ? 'wide-field' : '' }} {{ $alignEnd ? 'help-align-end' : '' }}">
            <span>
                <label for="student-field-{{ $name }}">{{ $field[0] }}</label>
                @if($help)
                    <span class="field-help" tabindex="0" role="img" aria-label="About {{ $field[0] }}" aria-describedby="field-help-{{ $name }}">ⓘ<span class="field-tooltip" id="field-help-{{ $name }}" role="tooltip">{{ $help }}</span></span>
                @endif
            </span>
            @if($field[1] === 'checkbox')
                <input type="hidden" name="{{ $name }}" value="{{ $name === 'hasenrolled' ? '0' : ($raw === null ? '' : '0') }}">
                <input type="checkbox" name="{{ $name }}" value="1" @checked($value) id="student-field-{{ $name }}" @if($help) aria-describedby="field-help-{{ $name }}" @endif>
            @elseif($field[1] === 'nullable_boolean' && ($recordsCheckboxes ?? false))
                <input type="hidden" name="{{ $name }}" value="0">
                <input type="checkbox" name="{{ $name }}" value="1" @checked($value) id="student-field-{{ $name }}" @if($help) aria-describedby="field-help-{{ $name }}" @endif>
            @elseif($field[1] === 'nullable_boolean')
                <select name="{{ $name }}" id="student-field-{{ $name }}">
                    <option value="" @selected($value === null || $value === '')>Not specified</option>
                    <option value="1" @selected($value !== null && $value !== '' && (int) $value === 1)>Yes</option>
                    <option value="0" @selected($value !== null && $value !== '' && (int) $value === 0)>No</option>
                </select>
            @elseif($field[1] === 'textarea')
                <textarea name="{{ $name }}" id="student-field-{{ $name }}" rows="2">{{ $value }}</textarea>
            @elseif($field[1] === 'program')
                <select name="{{ $name }}" id="student-field-{{ $name }}">
                    <option value="">No program</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}" @selected((string) $value === (string) $program->id)>{{ $program->program }}{{ $program->staged ? ' (staged)' : '' }}</option>
                    @endforeach
                </select>
            @elseif($field[1] === 'access_status')
                <select name="{{ $name }}" id="student-field-{{ $name }}">
                    <option value="">Not specified</option>
                    @foreach($accessStatuses as $status)
                        <option value="{{ $status->id }}" @selected((string) $value === (string) $status->id)>{{ $status->status }}</option>
                    @endforeach
                </select>
            @elseif($field[1] === 'flag')
                <select name="{{ $name }}" id="student-field-{{ $name }}">
                    <option value="{{ $value ?: 0 }}" selected>{{ $value > 0 ? 'Flagged' : 'Not Flagged' }}</option>
                    <option value="0">Not Flagged</option><option value="10">Flagged</option>
                </select>
            @else
                <input name="{{ $name }}" id="student-field-{{ $name }}" type="{{ match ($field[1]) { 'date' => 'date', 'datetime' => 'datetime-local', default => 'text' } }}"
                    value="{{ $value }}" @if(isset($field[2])) maxlength="{{ $field[2] }}" @endif
                    @if($field[1] === 'datetime') step="1" @endif
                    @if($field[1] === 'money') inputmode="decimal" @endif>
            @endif
        </div>
    @endforeach
    </div>
</fieldset>
