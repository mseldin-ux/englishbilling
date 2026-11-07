<fieldset><legend>{{ $heading }}</legend>
    <div class="field-grid">
    @foreach($fields as $name => $field)
        @php
            $raw = $student->getRawOriginal($name);
            $formatted = match ($field[1]) {
                'date' => $raw ? substr($raw, 0, 10) : '',
                'datetime' => $raw ? str_replace(' ', 'T', substr($raw, 0, 19)) : '',
                default => $raw,
            };
            $value = old($name, $formatted);
        @endphp
        <label class="{{ $field[1] === 'textarea' ? 'wide-field' : '' }}">
            <span>{{ $field[0] }}</span>
            @if($field[1] === 'checkbox')
                <input type="hidden" name="{{ $name }}" value="{{ $raw === null ? '' : '0' }}">
                <input type="checkbox" name="{{ $name }}" value="1" @checked($value)>
            @elseif($field[1] === 'nullable_boolean' && ($recordsCheckboxes ?? false))
                <input type="hidden" name="{{ $name }}" value="0">
                <input type="checkbox" name="{{ $name }}" value="1" @checked($value)>
            @elseif($field[1] === 'nullable_boolean')
                <select name="{{ $name }}">
                    <option value="" @selected($value === null || $value === '')>Not specified</option>
                    <option value="1" @selected($value !== null && $value !== '' && (int) $value === 1)>Yes</option>
                    <option value="0" @selected($value !== null && $value !== '' && (int) $value === 0)>No</option>
                </select>
            @elseif($field[1] === 'textarea')
                <textarea name="{{ $name }}" rows="2">{{ $value }}</textarea>
            @elseif($field[1] === 'program')
                <select name="{{ $name }}">
                    <option value="">No program</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}" @selected((string) $value === (string) $program->id)>{{ $program->program }}</option>
                    @endforeach
                </select>
            @elseif($field[1] === 'access_status')
                <select name="{{ $name }}">
                    <option value="">Not specified</option>
                    @foreach($accessStatuses as $status)
                        <option value="{{ $status->id }}" @selected((string) $value === (string) $status->id)>{{ $status->status }}</option>
                    @endforeach
                </select>
            @elseif($field[1] === 'flag')
                <select name="{{ $name }}">
                    <option value="{{ $value ?: 0 }}" selected>{{ $value > 0 ? 'Flagged' : 'Not Flagged' }}</option>
                    <option value="0">Not Flagged</option><option value="10">Flagged</option>
                </select>
            @else
                <input name="{{ $name }}" type="{{ match ($field[1]) { 'date' => 'date', 'datetime' => 'datetime-local', default => 'text' } }}"
                    value="{{ $value }}" @if(isset($field[2])) maxlength="{{ $field[2] }}" @endif
                    @if($field[1] === 'datetime') step="1" @endif
                    @if($field[1] === 'money') inputmode="decimal" @endif>
            @endif
        </label>
    @endforeach
    </div>
</fieldset>
