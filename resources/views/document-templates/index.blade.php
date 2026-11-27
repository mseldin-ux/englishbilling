@extends('layouts.app')
@section('content')
<h2>Invoice and statement document templates</h2>
<p>Changes affect HTML previews, current PDFs, and newly created invoice PDFs. Previously stored invoice PDFs never change.</p>
<ul>
@foreach(\App\Billing\DocumentTemplates::KEYS as $key)
    <li><a href="{{ route('document-templates.edit', $key) }}">Edit {{ $key }} layout</a>
        @if($templates->has($key))
            — Last edited {{ $templates[$key]->updated_at->format('Y-m-d H:i') }} by {{ $templates[$key]->editor?->name ?? 'a deleted user' }}
        @else — Original layout @endif
    </li>
@endforeach
</ul>
<p><a href="{{ route('utilities.index') }}">Utilities</a></p>
@endsection
