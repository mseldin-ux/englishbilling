@include('students.lock-notice')
@if($stageChoices)
    <label>Payment plan stage
        <select data-schedule-stage>
            <option value="">Choose a stage</option>
            @foreach($stageChoices as $value => $label)
                <option value="{{ $value }}" @selected($selectedPeriod === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <button type="button" data-select-schedule>Open stage plan</button>
@endif
@if(!$stageChoices || $selectedPeriod)
<div class="schedule-editor" data-fees="{{ $fees }}" @if($stageChoices) data-stage-specific @endif>
@if($student->current_enrollment_id)<p>This schedule and its fee total apply only to the current program/stage. Prior schedules are retained in the database.</p>@endif
<p>TOTAL FEES: {{ $fees }} | <span class="remaining-amount"></span></p>
<form id="schedule-generator" method="post" action="{{ route('schedule.generate', $student) }}">
    @csrf
    @if($selectedPeriod)<input type="hidden" name="billing_period" value="{{ $selectedPeriod }}">@endif
    <label>Payment Plan Template
        <select name="template_id" required>
            @foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->paymentplan }} — {{ $template->paymentplandesc }}</option>@endforeach
        </select>
    </label>
    <label>Start Date <input type="date" name="start_date" value="{{ $startDate?->format('Y-m-d') }}" required></label>
    <label class="inline"><input type="checkbox" name="exclude_app_fee" value="1" checked> Do not include app fee</label>
    <label class="inline">$ <input name="app_fee" class="compact" value="{{ $appFee }}" inputmode="decimal"></label>
    <button>Create Plan</button>
    <span class="form-status" role="status"></span>
</form>
<form id="schedule-form" method="post" action="{{ route('schedule.save', $student) }}" data-dirty>
    @csrf @method('PUT')
    <input type="hidden" name="version" value="{{ $version }}">
    @if($selectedPeriod)<input type="hidden" name="billing_period" value="{{ $selectedPeriod }}">@endif
    @if($student->current_enrollment_id)
        <p>Program / Stage: {{ $selectedPeriod ? $stageChoices[$selectedPeriod] : \App\Billing\EnrollmentAttribution::options($student)[implode(':', \App\Billing\EnrollmentAttribution::current($student))] }}</p>
    @endif
    <label>Description <textarea name="paymentplandesc" rows="2">{{ $plan?->paymentplandesc }}</textarea></label>
    <div class="table-scroll">
    <table>
        <thead><tr><th>Payment #</th><th>Due date</th><th>Amount</th><th>Notes</th><th>Actions</th></tr></thead>
        <tbody id="schedule-rows">
        @foreach($payments as $payment)
            <tr data-payment-id="{{ $payment->id }}">
                <td class="payment-number">{{ $loop->iteration }}</td>
                <td><input type="date" data-payment-field="paymentduedate" value="{{ $payment->paymentduedate?->format('Y-m-d') }}" aria-label="Payment due date" required></td>
                <td><input data-payment-field="paymentamount" value="{{ $payment->paymentamount }}" aria-label="Payment amount" inputmode="decimal" required></td>
                <td><input data-payment-field="paymentnotes" value="{{ $payment->paymentnotes }}" aria-label="Payment notes" maxlength="255"></td>
                <td><button type="button" data-move-payment="-1">Up</button><button type="button" data-move-payment="1">Down</button><button type="button" data-insert-payment>Insert before</button><button type="button" data-remove-payment>Delete</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <button type="button" data-add-payment>Add Payment</button>
    <button type="button" data-clear-plan>Erase Plan</button>
    <button>Save Payment Plan</button>
    <span class="form-status" role="status">No changes to save</span>
</form>
<template id="payment-row-template">
    <tr><td class="payment-number"></td>
        <td><input type="date" data-payment-field="paymentduedate" aria-label="Payment due date" required></td>
        <td><input data-payment-field="paymentamount" aria-label="Payment amount" inputmode="decimal" required></td>
        <td><input data-payment-field="paymentnotes" aria-label="Payment notes" maxlength="255"></td>
        <td><button type="button" data-move-payment="-1">Up</button><button type="button" data-move-payment="1">Down</button><button type="button" data-insert-payment>Insert before</button><button type="button" data-remove-payment>Delete</button></td>
    </tr>
</template>
<p class="hint">Saving replaces this learner's schedule atomically, in the displayed row order, as in Django. Scheduled-payment IDs are regenerated; non-edited invoice-date and percentage values are retained.</p>
</div>
@endif
