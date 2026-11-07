@use('App\Forms\FinalGrades', 'F')
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ F::TITLE }} — Sheet {{ $sheet->id }}</title>
<style>
    @page { margin: 0.6in; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #111; }
    .logo { width: 2.4in; }
    h1 { font-size: 15pt; margin: 12pt 0 4pt; }
    .meta { color: #555; font-size: 9pt; margin-bottom: 10pt; }
    .draft { color: #b00; font-weight: bold; font-size: 13pt; border: 2px solid #b00; padding: 3pt 8pt; display: inline-block; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
    th, td { text-align: left; vertical-align: top; padding: 4pt 6pt; border-bottom: 1px solid #ccc; }
    .header th { width: 30%; font-weight: normal; }
    .header td { font-weight: bold; }
    .grades thead th { border-bottom: 1.5px solid #555; }
    .grades .num { width: 0.4in; color: #555; }
    .grades .hours { width: 0.9in; }
    .grades .grade { width: 1.4in; font-weight: bold; }
    .notes { white-space: pre-wrap; border: 1px solid #ccc; padding: 6pt; min-height: 0.5in; }
    .signature { margin-top: 10pt; font-size: 9pt; color: #444; }
</style>
</head>
<body>
<img class="logo" src="{{ $pdf ? public_path('images/cgms-logo.png') : asset('images/cgms-logo.png') }}" alt="The Center for Guided Montessori Studies">
<h1>{{ F::TITLE }}</h1>
<div class="meta">Grade sheet {{ $sheet->id }} — revision {{ $sheet->revision }} — {{ $sheet->status === 'submitted' ? 'Submitted' : 'Draft' }}
    @if($sheet->submitted_at) {{ $sheet->submitted_at->format('Y-m-d H:i') }} @endif</div>
@if($sheet->status !== 'submitted')<p class="draft">DRAFT — NOT SUBMITTED</p>@endif
<table class="header">
    <tr><th>{{ F::LABELS['studentid'] }}</th><td>{{ $values['student_name'] ?? '' }} (User ID {{ $values['studentid'] }}@if(!empty($values['student_cohort'])), Cohort {{ $values['student_cohort'] }}@endif)</td></tr>
    <tr><th>{{ F::LABELS['program_id'] }}</th><td>{{ $values['program_name'] ?? '' }}</td></tr>
</table>
@php $hours = $items->contains(fn ($item) => $item->clockhours !== null); @endphp
<table class="grades">
    <thead><tr><th class="num">#</th><th>Graded item</th>@if($hours)<th class="hours">Clock hours</th>@endif<th class="grade">Grade</th></tr></thead>
    <tbody>
    @foreach($items as $item)
        <tr><td class="num">{{ $item->itemorder }}</td><td>{{ $item->gradeitem }}</td>
            @if($hours)<td class="hours">{{ $item->clockhours }}</td>@endif
            <td class="grade">{{ $grades[$item->id] ?? '' }}</td></tr>
    @endforeach
    </tbody>
</table>
<p><strong>{{ F::LABELS['gradenotes'] }}</strong></p>
<div class="notes">{{ $values['gradenotes'] ?? '' }}</div>
<table class="header" style="margin-top: 12pt;">
    <tr><th>{{ F::LABELS['gradername'] }}</th><td>{{ $values['gradername'] ?? '' }}</td></tr>
    <tr><th>{{ F::LABELS['gradedate'] }}</th><td>{{ $values['gradedate'] ?? '' }}</td></tr>
</table>
@if($signer && $sheet->signed_at)
    <p class="signature">Signed electronically by login {{ $signer->name }} ({{ $signer->username }})
        on {{ $sheet->signed_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}.</p>
@endif
</body>
</html>
