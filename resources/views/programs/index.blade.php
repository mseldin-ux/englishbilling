@extends('layouts.app')
@section('content')
<h2>Programs</h2>
<p><a href="{{ route('utilities.index') }}">Utilities</a> | <a href="{{ route('programs.create') }}">Create program</a></p>
<p>For staged enrollment, create one program per stage. Each uses its own service days and points to the next stage. All stages must select the same shared certificate program. Configure the complete sequence before enrolling learners.</p>
@if($hasLegacyStages)
    <p>Some existing learners still use the earlier stage setup. Their history is retained; <a href="{{ route('enrollments.settings') }}">legacy stage service-day settings</a> remain available for those enrollments only.</p>
@endif
<table>
    <thead><tr><th>ID</th><th>Program</th><th>Service days</th><th>Staged</th><th>Certificate leading</th><th>Next stage</th><th>Shared certificate program</th><th></th></tr></thead>
    <tbody>@foreach($programs as $program)
        <tr><td>{{ $program->id }}</td><td>{{ $program->program }}</td><td>{{ $program->servicedays }}</td>
            <td>{{ $program->staged ? 'Yes' : 'No' }}</td><td>{{ $program->isCertificateLeading() ? 'Yes' : 'No' }}</td>
            <td>{{ $programs->get($program->nextprog)?->program ?? 'None (final stage if staged)' }}</td>
            <td>{{ $programs->get($program->certificate_program_id)?->program }}</td>
            <td><a href="{{ route('programs.edit', $program) }}">Edit</a></td></tr>
    @endforeach</tbody>
</table>
@endsection
