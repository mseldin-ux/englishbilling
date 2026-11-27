@extends('layouts.app')
@section('content')
<div class="form-page">
<h2>Edit {{ $key }} document layout</h2>
<p><a href="{{ route('document-templates.index') }}">All document templates</a></p>
<p class="hint">Edit HTML and CSS using the placeholders below. The preview uses sample data. PDF rendering may differ from the browser; check the sample PDF before saving. External resources are not loaded by the PDF renderer.</p>
<p class="hint">Changes affect current HTML/PDF and new invoice PDFs; previously stored invoice PDFs remain unchanged. Template HTML is not executed as application code.</p>
<p class="hint">Removing placeholders or sections removes that information from the document, including financial details. Check the sample PDF before saving.</p>
<form method="post" action="{{ route('document-templates.update', $key) }}">
    @csrf @method('PUT')
    <label>HTML layout <textarea name="body" rows="28" maxlength="200000" required>{{ $body }}</textarea></label>
    <button name="action" value="preview">Preview</button>
    <button name="action" value="save">Save Template</button>
    <button name="action" value="reset" formnovalidate onclick="return confirm('Restore the original document layout?')">Reset to Original Layout</button>
</form>
<details>
    <summary>Available placeholders</summary>
    <p>Include the braces. Values are escaped; the row, details, note, and style placeholders insert preformatted content.</p>
    <table class="forms-table">
    @foreach(\App\Billing\DocumentTemplates::PLACEHOLDERS as $name => $description)
        <tr><td><code>{{ '{'.$name.'}' }}</code></td><td>{{ $description }}</td></tr>
    @endforeach
    </table>
</details>
<h3>Sample preview</h3>
<p><a href="{{ route('document-templates.preview-pdf', $key) }}" target="_blank" rel="noopener">Open sample PDF</a> (click Preview first to include unsaved changes)</p>
<iframe title="Document preview" sandbox srcdoc="{{ $preview }}" style="width: 100%; min-height: 700px; border: 1px solid #aaa"></iframe>
</div>
@endsection
