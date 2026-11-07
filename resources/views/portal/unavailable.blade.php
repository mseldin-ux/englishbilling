@extends('layouts.portal')
@section('title', 'Link unavailable')
@section('content')
<h1>This link is no longer active</h1>
@if($expired)
    <p>Your Course Completion Form link has expired.</p>
@else
    <p>This Course Completion Form link has been withdrawn.</p>
@endif
<p>Please contact the CGMS Admin Team at <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a> and we will send you a new link.</p>
@endsection
