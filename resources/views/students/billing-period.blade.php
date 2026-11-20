@php
    $periodOptions = \App\Billing\EnrollmentAttribution::options($student);
    $selectedPeriod = isset($periodRecord) && $periodRecord?->exists
        ? \App\Billing\EnrollmentAttribution::key($periodRecord)
        : implode(':', \App\Billing\EnrollmentAttribution::current($student));
@endphp
@if($periodOptions)
    <select name="billing_period" @if(isset($periodForm)) form="{{ $periodForm }}" @endif aria-label="Program enrollment / stage">
        @if(!$selectedPeriod)<option value="">Legacy / not assigned</option>@endif
        @foreach($periodOptions as $value => $label)<option value="{{ $value }}" @selected($selectedPeriod === $value)>{{ $label }}</option>@endforeach
    </select>
@endif
