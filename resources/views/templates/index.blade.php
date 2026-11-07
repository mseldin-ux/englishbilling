@extends('layouts.app')
@section('content')
<h2>Email Templates</h2>
<p class="hint">These emails (and PDF letters attached to them) are sent automatically by workflows. Editing a template affects emails sent from now on;
    emails already sent are not changed.</p>
<table class="forms-table">
    <thead><tr><th>Template</th><th>When it is sent</th><th>Subject</th><th>Last edited</th><th></th></tr></thead>
    <tbody>
    @foreach($templates as $row)
        <tr>
            <td>{{ $row['definition']->name }}@if($row['definition']->kind === 'letter') <span class="hint">(PDF letter)</span>@endif</td>
            <td>{{ $row['definition']->description }}</td>
            <td>{{ $row['template']->subject }}</td>
            <td>@if($row['template']->updated_by){{ $row['template']->updated_at->format('Y-m-d H:i') }} by {{ $row['template']->editor?->name ?? 'a deleted user' }}@else Original text @endif</td>
            <td><a href="{{ route('templates.edit', $row['definition']->key) }}">Edit</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
@endsection
