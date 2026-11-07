<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'CGMS') — The Center for Guided Montessori Studies</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
</head>
<body class="portal">
<header class="portal-header">
    <img src="{{ asset('images/cgms-logo.png') }}" alt="The Center for Guided Montessori Studies" class="portal-logo">
</header>
<main class="portal-main">
    @yield('content')
</main>
</body>
</html>
