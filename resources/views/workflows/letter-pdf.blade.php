<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0.8in 0.9in; }
    body { font-family: "DejaVu Serif", serif; font-size: 12pt; line-height: 1.5; color: #222; }
    .logo { width: 2.6in; margin-bottom: 0.4in; }
    .letter { white-space: pre-wrap; }
</style>
</head>
<body>
<img class="logo" src="{{ $pdf ? public_path('images/cgms-logo.png') : asset('images/cgms-logo.png') }}" alt="The Center for Guided Montessori Studies">
<div class="letter">{{ $text }}</div>
</body>
</html>
