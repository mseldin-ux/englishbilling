@extends('layouts.app')
@section('content')
<h2>Log in</h2>
<form method="post" action="{{ url('/login') }}">
    @csrf
    <label>Username <input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">Log in</button>
</form>
@endsection
