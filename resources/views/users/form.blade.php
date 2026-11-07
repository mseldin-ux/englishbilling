@extends('layouts.app')
@section('content')
<h2>{{ $staffUser->exists ? 'Edit user' : 'Create user' }}</h2>
<form method="post" action="{{ $staffUser->exists ? route('users.update', $staffUser) : route('users.store') }}">
    @csrf
    @if($staffUser->exists) @method('PUT') @endif
    <label>Username <input name="username" value="{{ old('username', $staffUser->username) }}" maxlength="150" required></label>
    <label>Name <input name="name" value="{{ old('name', $staffUser->name) }}" required></label>
    <label>Email (optional) <input type="email" name="email" value="{{ old('email', $staffUser->email) }}"></label>
    <label>Password {{ $staffUser->exists ? '(leave blank to keep current password)' : '(at least 12 characters)' }}
        <input type="password" name="password" autocomplete="new-password" @required(!$staffUser->exists)>
    </label>
    <label>Confirm password <input type="password" name="password_confirmation" autocomplete="new-password"></label>
    <label>User level
        <select name="level" required>
            @foreach(['admin' => 'Admin', ...\App\Models\User::LEVELS] as $level => $label)
                <option value="{{ $level }}" @selected(old('level', $staffUser->exists ? $staffUser->level() : 'staff') === $level)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <p>Staff can use billing and reports. Billing Staff (No Reports) can use billing but not reports or global payment-plan templates.
        The records level can view, search, copy, create, and edit only the approved Access student fields, and use forms.
        The forms level can only view, create, and edit forms.
        Admins have full access, including user management and lock recovery.</p>
    <button type="submit">Save</button>
    <a href="{{ route('users.index') }}">Cancel</a>
</form>
@endsection
