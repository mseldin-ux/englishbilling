@extends('layouts.app')
@section('content')
<div class="form-page">
<h2>{{ $definition->name }}</h2>
<p class="hint">{{ $definition->description }} | <a href="{{ route('templates.index') }}">All templates</a></p>
<p class="hint">@if($template->updated_by)Last edited {{ $template->updated_at->format('Y-m-d H:i') }} by {{ $template->editor?->name ?? 'a deleted user' }}.@else Using the original text.@endif</p>
<form method="post" action="{{ route('templates.update', $definition->key) }}">
    @csrf @method('PUT')
    <fieldset>
        <legend>{{ $definition->kind === 'letter' ? 'Letter (PDF)' : 'Email' }}</legend>
        <label>{{ $definition->kind === 'letter' ? 'PDF file name' : 'Subject' }}
            <input type="text" name="subject" maxlength="255" size="80" required value="{{ $subject }}">
        </label>
        <label>{{ $definition->kind === 'letter' ? 'Letter text (plain text, printed on CGMS letterhead)' : 'Body (plain text; web addresses become links automatically)' }}
            <textarea name="body" rows="20" required>{{ $body }}</textarea>
        </label>
    </fieldset>
    <button type="submit" name="action" value="preview">Preview</button>
    <button type="submit" name="action" value="save">Save Template</button>
    <button type="submit" name="action" value="reset" formnovalidate onclick="return confirm('Replace this template with the original text?')">Reset to Original Text</button>
</form>
<fieldset class="template-placeholders">
    <legend>Placeholders</legend>
    <p class="hint">Type a placeholder, including the braces, where the value should appear.
        @if($definition->required) Required in the body: @foreach($definition->required as $name)<code>{{ '{'.$name.'}' }}</code>@if(!$loop->last), @endif @endforeach.@endif</p>
    <table class="forms-table">
        @foreach($definition->placeholders as $name => $description)
            <tr><td><code>{{ '{'.$name.'}' }}</code></td><td>{{ $description }}</td></tr>
        @endforeach
    </table>
</fieldset>
<h3>{{ $previewing ? 'Preview of your unsaved changes' : 'Preview of the saved template' }} (sample values)</h3>
<p><strong>{{ $definition->kind === 'letter' ? 'File name' : 'Subject' }}:</strong> {{ $preview['subject'] }}</p>
<div class="template-preview">{{ $preview['text'] }}</div>
</div>
@endsection
