@extends('layouts.app')
@section('content')
<h2>Create an AL</h2>
<p>Use this form to create a new student record.</p>
<form method="post" action="{{ route('students.store') }}" data-dirty>
    @csrf
    @include('students.fields')
    <button type="submit">Create Learner</button>
    <span class="form-status" role="status"></span>
</form>
@endsection
