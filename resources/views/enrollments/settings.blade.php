@extends('layouts.app')
@section('content')
<h2>Program stage service days</h2>
<p>Leave unconfigured stages blank. Staged enrollment requires a positive duration for the selected stage.
    Changes affect future stage enrollments only; agreed durations are retained in history.</p>
<form method="post" action="{{ route('enrollments.settings.save') }}">
    @csrf @method('PUT')
    <table><thead><tr><th>Full Certificate program</th>
        @foreach(\App\Billing\Enrollments::STAGES as $number => $label)<th>{{ $number }} — {{ $label }}</th>@endforeach
    </tr></thead><tbody>
    @foreach($programs as $program)
        <tr><td>{{ $program->program }}</td>
        @foreach(\App\Billing\Enrollments::STAGES as $number => $label)
            <td><input type="number" min="1" max="10000" name="days[{{ $program->id }}][{{ $number }}]"
                aria-label="{{ $program->program }} stage {{ $number }} service days"
                value="{{ old('days.'.$program->id.'.'.$number, ($definitions[$program->id] ?? collect())->firstWhere('stage', $number)?->service_days) }}"></td>
        @endforeach</tr>
    @endforeach
    </tbody></table>
    <button>Save stage defaults</button>
</form>
@endsection
