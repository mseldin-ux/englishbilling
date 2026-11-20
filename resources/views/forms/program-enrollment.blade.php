@if($enrollmentRecord->exists)
    <p>Program enrollment: {{ collect($enrollmentOptions)->firstWhere('id', $enrollmentRecord->enrollment_id)['label'] ?? ($enrollmentRecord->enrollment_key === 'legacy' ? 'Original / legacy program' : 'Historical staging enrollment (refresh discarded its enrollment history)') }}</p>
    <p class="hint">A new enrollment requires a new form; this form keeps its original enrollment.</p>
@else
    <label>Program enrollment
        <select name="enrollment_id" id="program-enrollment" data-options="{{ json_encode($enrollmentOptions) }}"
            data-selected="{{ $values['enrollment_id'] ?? '' }}">
            <option value="">Original / legacy program (no tracked enrollment)</option>
            @foreach($enrollmentOptions as $option)
                @if((string) $option['program_id'] === (string) $values['program_id'])
                    <option value="{{ $option['id'] }}" @selected((string) ($values['enrollment_id'] ?? '') === (string) $option['id'])>{{ $option['label'] }}</option>
                @endif
            @endforeach
        </select>
    </label>
    <p class="hint">All stages of one program enrollment share one final grade sheet. A return enrollment needs a separate sheet.</p>
@endif
