@extends('layouts.app')
@section('content')
<h2>Create an AL</h2>
<p>Use this form to create a new student record.</p>
<p>Choosing a staged program automatically enrolls the learner in that stage when you create the record. Enter its start date; no separate enrollment step is needed.</p>
<form method="post" action="{{ route('students.store') }}" data-dirty>
    @csrf
    @include('students.fields')
    <button type="submit">Create Learner</button>
    <span class="form-status" role="status"></span>
</form>
@endsection
