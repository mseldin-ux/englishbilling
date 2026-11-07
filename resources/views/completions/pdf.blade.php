<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Course Completion Form — Submission {{ $completion->id }}</title>
<style>
    @page { margin: 0.6in; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #111; }
    .logo { width: 2.4in; }
    h1 { font-size: 15pt; margin: 12pt 0 4pt; }
    .meta { color: #555; font-size: 9pt; margin-bottom: 10pt; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 5pt 6pt; border-bottom: 1px solid #ccc; }
    th { width: 50%; font-weight: normal; }
    td { font-weight: bold; }
</style>
</head>
<body>
<img class="logo" src="{{ $pdf ? public_path('images/cgms-logo.png') : asset('images/cgms-logo.png') }}" alt="The Center for Guided Montessori Studies">
<h1>Course Completion Form</h1>
<div class="meta">Submission {{ $completion->id }} — entered by the Adult Learner</div>
<table>
    @include('completions.rows')
</table>
</body>
</html>
