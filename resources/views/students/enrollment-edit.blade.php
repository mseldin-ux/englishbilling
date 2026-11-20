<details>
    <summary>Edit {{ $stageId ? 'stage' : 'program' }} / resume / undo closure / cancel</summary>
    @foreach(['correct' => 'Save changes', 'resume' => 'Resume study', 'undo' => 'Undo closure', 'cancel' => 'Cancel (retain billing)'] as $action => $button)
        @if(in_array($action, ['resume', 'undo'], true) && (!$unit->ended_on || $student->current_enrollment_id !== $enrollment->id || ($stageId && $enrollment->stages->last()->id !== $stageId))) @continue @endif
        @if($action === 'cancel' && $unit->status === 'cancelled') @continue @endif
        <form method="post" action="{{ route('enrollments.store', $student) }}" data-dirty
            @if($action !== 'correct') data-confirm="{{ match ($action) {
                'resume' => 'Resume academic activity from the return date? The closed interval will not earn service days. Prior settlement remains effective.',
                'undo' => 'Undo the latest closure as a correction? Its automatic settlement will be invalidated and historical earned-income reports may change. Billing records will not change.',
                default => 'Cancel this record? Billing records and existing financial settlement history will be retained. No refund or adjustment is created.',
            } }}" @endif>
            @csrf
            <input type="hidden" name="_edit_lock" value="{{ $editLock['token'] }}">
            <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($student) }}">
            <input type="hidden" name="action" value="{{ $action }}">
            <input type="hidden" name="enrollment_id" value="{{ $enrollment->id }}">
            @if($stageId)<input type="hidden" name="stage_enrollment_id" value="{{ $stageId }}">@endif
            @if($action === 'correct')
                <label>Service days <input name="service_days" type="number" min="1" max="10000" value="{{ $unit->service_days }}" required></label>
                <label>Start date <input name="started_on" type="date" value="{{ $unit->started_on->format('Y-m-d') }}" required></label>
                <label>End/completion date <input name="ended_on" type="date" value="{{ $unit->ended_on?->format('Y-m-d') }}"></label>
                <p>Clearing an end date does not reopen a record; use Resume study or Undo closure. Corrections can change historical reports.</p>
            @elseif($action === 'cancel')
                <label>Cancellation date <input name="effective_on" type="date" value="{{ today()->toDateString() }}" required></label>
                <p>Stops further service on an active record; retains billing and prior settlement events. No automatic financial reversal.</p>
            @elseif($action === 'resume')
                <label>Return date <input name="effective_on" type="date" value="{{ today()->toDateString() }}" required></label>
                <p>Excludes the closed interval from service days. Prior financial settlement remains effective.</p>
            @else
                <p>Corrects a mistaken closure, including its automatic 100%-earned marker. Earlier independent settlements remain effective. No fees, payments or invoices are changed.</p>
                <label><input type="checkbox" name="confirm_undo" value="1" required> I understand that historical earned-income reports may change</label>
            @endif
            <label>Reason <input name="reason" maxlength="1000" required></label>
            <button>{{ $button }}</button>
        </form>
    @endforeach
</details>
