@extends('layouts.app')
@section('content')
<div class="form-page">
    <h2>Utilities</h2>
    <h3>Test mail connection</h3>
    <p>Configured mailer: <strong>{{ $mailer }}</strong> (transport: {{ $transport }}).</p>
    <p>This sends a plain-text message immediately using the application's current mail configuration.
        It does not change the default sender or any workflow settings. The sender must be authorized by your email provider.</p>
    @if(in_array($transport, ['log', 'array'], true))
        <p><strong>This transport does not deliver email.</strong> To test a real mail connection, configure a delivery transport such as SMTP on the server and rebuild the configuration cache.</p>
    @endif
    <form method="post" action="{{ route('utilities.mail') }}">
        @csrf
        <label>Sender
            <input type="email" name="sender" value="{{ old('sender', config('mail.from.address')) }}" maxlength="254" required>
        </label>
        <label>Recipient
            <input type="email" name="recipient" value="{{ old('recipient', auth()->user()->email) }}" maxlength="254" required>
        </label>
        <label>Subject
            <input type="text" name="subject" value="{{ old('subject', 'CGMS billing mail connection test') }}" maxlength="255" required>
        </label>
        <label>Mail body
            <textarea name="body" rows="8" maxlength="20000" required>{{ old('body', 'This is a test message from the CGMS billing system.') }}</textarea>
        </label>
        <button type="submit">Send Test Email</button>
    </form>
</div>
@endsection
