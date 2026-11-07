@extends('layouts.app')
@section('content')
<h2>Users</h2>
<p><a href="{{ route('users.create') }}">Create user</a></p>
<table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Actions</th></tr></thead>
    <tbody>
    @foreach($users as $staffUser)
        <tr>
            <td>{{ $staffUser->username }}</td><td>{{ $staffUser->name }}</td>
            <td>{{ $staffUser->levelLabel() }}</td>
            <td>
                <a href="{{ route('users.edit', $staffUser) }}">Edit</a>
                <form class="inline" method="post" action="{{ route('users.destroy', $staffUser) }}" onsubmit="return confirm('Really delete this user?')">
                    @csrf @method('DELETE')
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
{{ $users->links() }}
@endsection
