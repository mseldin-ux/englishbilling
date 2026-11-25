@if(config('billing.concurrent_stages') && $student->current_enrollment_id)
    @php
        $allocationOptions = $student->loadMissing('enrollments.stages')->enrollments->flatMap->stages;
        $savedAllocations = $allocationRecord ? ($allocations->get($allocationRecord->id) ?? collect()) : collect();
    @endphp
    @if($allocationOptions->count() > 1 || $savedAllocations->isNotEmpty())
        <details>
            <summary>Split amount across stages</summary>
            <p class="hint">Leave all amounts blank for a single-stage entry. Otherwise enter amounts that add exactly to the total.</p>
            @foreach($allocationOptions as $allocationStage)
                @php $allocationValue = $savedAllocations->firstWhere('stage_enrollment_id', $allocationStage->id)?->amount; @endphp
                <label>Stage {{ $allocationStage->stage }} — {{ $allocationStage->label() }}
                    (program enrollment {{ $allocationStage->enrollment_id }}, started {{ $allocationStage->started_on->format('Y-m-d') }})
                    <input form="{{ $allocationForm }}" name="stage_allocation_{{ $allocationStage->id }}" inputmode="decimal"
                        value="{{ $allocationValue === null ? '' : \App\Billing\Money::cents((string) $allocationValue) }}" @disabled($readOnly ?? false)>
                </label>
            @endforeach
        </details>
    @endif
@endif
