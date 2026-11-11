<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>English Billing System</title>
    <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if(auth()->check() && auth()->user()->canAccessBilling())
        <script src="{{ asset('js/billing.js') }}" defer></script>
    @endif
    @auth <script src="{{ asset('js/edit-locks.js') }}" defer></script> @endauth
</head>
<body>
<header>
    <h1>English Billing System</h1>
    @auth
        <nav>
            <a href="{{ route('home') }}">Home</a>
            @if(!auth()->user()->canAccessBilling() && auth()->user()->canUseRecordsWorkspace())
                | <a href="{{ route('workspace.index') }}">Student Records</a>
            @endif
            @if(auth()->user()->canAccessBilling())
            | <a href="{{ route('students.index', ['reset' => 1]) }}">Browse all ALs</a>
            | <button type="button" data-open="find-dialog">Find a List of ALs</button>
            | <a href="{{ route('students.create') }}">Create an AL</a>
            | <a href="{{ route('workspace.index') }}">Brief Records View</a>
            @if(auth()->user()->canManagePaymentPlans())
                | <a href="{{ route('plans.index') }}">Payment Plans</a>
            @endif
            @if(auth()->user()->canViewReports())
                | <a href="{{ route('reports.index') }}">Reports</a>
            @endif
            @endif
            | <a href="{{ route('forms.index') }}">Forms</a>
            @if(auth()->user()->canUseRecordsWorkspace())
                | <a href="{{ route('workflows.index') }}">Workflows</a>
                | <a href="{{ route('templates.index') }}">Email Templates</a>
            @endif
            @if(auth()->user()->is_admin)
                | <a href="{{ route('users.index') }}">Users</a>
                | <a href="{{ route('locks.index') }}">Editing Locks</a>
                | <a href="{{ route('workflows.settings.edit') }}">Workflow Settings</a>
                | <a href="{{ route('utilities.index') }}">Utilities</a>
            @endif
            | {{ auth()->user()->name }}
            <form method="post" action="{{ route('logout') }}" class="inline">
                @csrf <button type="submit">Log Out</button>
            </form>
        </nav>
    @endauth
</header>
@auth <p id="session-state" role="status"></p> @endauth
@if(auth()->check() && auth()->user()->canAccessBilling())
<dialog id="find-dialog">
    <h2>Find all records with...</h2>
    @include('students.search', ['filters' => session('billing_search', [])])
    <button type="button" data-close-dialog>Close</button>
</dialog>
@endif
<main>
    @if(session('status')) <p role="status">{{ session('status') }}</p> @endif
    @if($errors->any())
        <div role="alert">
            <ul>@foreach($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
