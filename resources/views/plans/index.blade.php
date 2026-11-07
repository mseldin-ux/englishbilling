@extends('layouts.app')
@section('content')
<h2>Payment Plan Templates</h2>
<p>All authenticated staff can manage templates. Month numbers are relative to enrollment: 1,7,13 means enrollment, six months later, and twelve months later.</p>
@foreach($plans as $plan)
<fieldset>
    <legend>{{ $plan->paymentplan }} (ID {{ $plan->id }})</legend>
    <form method="post" action="{{ route('plans.update', $plan) }}" data-dirty>
        @csrf @method('PATCH')
        <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($plan) }}">
        <label>Name <input name="paymentplan" value="{{ $plan->paymentplan }}" required maxlength="255"></label>
        <label>Description <textarea name="paymentplandesc">{{ $plan->paymentplandesc }}</textarea></label>
        <label>Months <input name="monthslist" value="{{ $plan->monthslist }}" required maxlength="255"></label>
        <button>SAVE</button><span class="form-status" role="status"></span>
    </form>
    <form method="post" action="{{ route('plans.destroy', $plan) }}" data-confirm="Really delete this template? Existing student plans will not be changed.">
        @csrf @method('DELETE')
        <input type="hidden" name="version" value="{{ \App\Billing\RecordVersion::of($plan) }}">
        <button>DELETE</button>
    </form>
</fieldset>
@endforeach
<h3>Create Template</h3>
<form method="post" action="{{ route('plans.store') }}">
    @csrf
    <label>Name <input name="paymentplan" value="{{ old('paymentplan') }}" required maxlength="255"></label>
    <label>Description <textarea name="paymentplandesc">{{ old('paymentplandesc') }}</textarea></label>
    <label>Months <input name="monthslist" value="{{ old('monthslist', '1,7,13') }}" required maxlength="255"></label>
    <button>Create Template</button>
</form>
<hr>
<h3>Recalculate Balances</h3>
<form method="post" action="{{ route('balances.recalculate') }}" data-confirm="Recalculate all balances from the signed cent ledger?">
    @csrf
    <label><input type="checkbox" name="confirm" value="1" required> Recalculate all learner balances</label>
    <button>Recalculate Balances</button>
</form>
@endsection
