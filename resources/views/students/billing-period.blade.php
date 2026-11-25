@php
    $periodOptions = \App\Billing\EnrollmentAttribution::options($student);
    $selectedPeriod = isset($periodRecord) && $periodRecord?->exists
        ? \App\Billing\EnrollmentAttribution::key($periodRecord)
        : implode(':', \App\Billing\EnrollmentAttribution::current($student));
    if (config('billing.concurrent_stages') && !(isset($periodRecord) && $periodRecord?->exists)
        && $student->currentEnrollment?->mode === 'staged'
        && $student->currentEnrollment->stages->count() > 1) {
        $selectedPeriod = '';
    }
@endphp
@if($periodOptions)
    <select name="billing_period" @if(isset($periodForm)) form="{{ $periodForm }}" @endif aria-label="Program enrollment / stage" @disabled($readOnly ?? false)>
        @if(!$selectedPeriod)<option value="">{{ config('billing.concurrent_stages') && !(isset($periodRecord) && $periodRecord?->exists) ? 'Choose a program / stage' : 'Legacy / not assigned' }}</option>@endif
        @foreach($periodOptions as $value => $label)<option value="{{ $value }}" @selected($selectedPeriod === $value)>{{ $label }}</option>@endforeach
    </select>
@endif
