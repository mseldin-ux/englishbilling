@use('App\Forms\GraduationInitiation', 'G')
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ G::TITLE }} — Form {{ $form->id }}</title>
<style>
    @page { margin: 0.6in; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #111; }
    .logo { width: 2.4in; }
    h1 { font-size: 15pt; margin: 12pt 0 4pt; }
    .meta { color: #555; font-size: 9pt; margin-bottom: 10pt; }
    .draft { color: #b00; font-weight: bold; font-size: 13pt; border: 2px solid #b00; padding: 3pt 8pt; display: inline-block; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 5pt 6pt; border-bottom: 1px solid #ccc; }
    th { width: 55%; font-weight: normal; }
    td { font-weight: bold; }
    .notice { margin-top: 18pt; font-style: italic; }
    .signature { margin-top: 10pt; font-size: 9pt; color: #444; }
</style>
</head>
<body>
<img class="logo" src="{{ $pdf ? public_path('images/cgms-logo.png') : asset('images/cgms-logo.png') }}" alt="The Center for Guided Montessori Studies">
<h1>{{ G::TITLE }}</h1>
<div class="meta">Form {{ $form->id }} — revision {{ $form->revision }} — {{ $form->status === 'submitted' ? 'Submitted' : 'Draft' }}
    @if($form->submitted_at) {{ $form->submitted_at->format('Y-m-d H:i') }} @endif</div>
@if($form->status !== 'submitted')<p class="draft">DRAFT — NOT SUBMITTED</p>@endif
<table>
    <tr><th>{{ G::LABELS['student_id'] }}</th><td>{{ $values['student_name'] ?? '' }} (User ID {{ $values['student_id'] ?? '' }}@if(!empty($values['student_cohort'])), Cohort {{ $values['student_cohort'] }}@endif)</td></tr>
    <tr><th>{{ G::LABELS['program_id'] }}</th><td>{{ $values['program_name'] ?? '' }}</td></tr>
    @foreach(G::QUESTIONS as $field => $question)
        <tr><th>{{ $loop->iteration }}) {{ $question }}</th><td>{{ !empty($values[$field]) ? 'Yes' : 'No' }}</td></tr>
    @endforeach
    <tr><th>{{ G::LABELS['residency_location'] }}</th><td>{{ $values['residency_location'] ?? '' }}</td></tr>
    <tr><th>{{ G::LABELS['residency_date'] }}</th><td>{{ $values['residency_date'] ?? '' }}</td></tr>
    <tr><th>{{ G::LABELS['practicum_passed_date'] }}</th><td>{{ $values['practicum_passed_date'] ?? '' }}</td></tr>
    <tr><th>{{ G::LABELS['graduation_date'] }}</th><td>{{ $values['graduation_date'] ?? '' }}</td></tr>
    <tr><th>{{ G::LABELS['instructor_email'] }}</th><td>{{ $values['instructor_email'] ?? '' }}</td></tr>
    <tr><th>{{ G::LABELS['director_signature'] }}</th><td>{{ $values['director_signature'] ?? '' }}</td></tr>
</table>
@if(!empty($values['signed_by']))
    <p class="signature">Signed electronically by login {{ $values['signed_by']['name'] }} ({{ $values['signed_by']['username'] }})
        on {{ \Carbon\CarbonImmutable::parse($values['signed_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}.</p>
@endif
<p class="notice">*{{ G::NOTICE }} *</p>
</body>
</html>
