<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class UtilitiesController extends Controller
{
    public function index()
    {
        $mailer = config('mail.default');

        return view('utilities.index', [
            'mailer' => $mailer,
            'transport' => config("mail.mailers.{$mailer}.transport"),
        ]);
    }

    public function testMail(Request $request)
    {
        $data = $request->validate([
            'sender' => ['required', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'recipient' => ['required', 'string', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'],
            'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $sent = Mail::raw($data['body'], function (Message $message) use ($data) {
                $message->from($data['sender'])->to($data['recipient'])->subject($data['subject']);
            });
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'mail' => 'The mail connection test failed. Check the server mail configuration, sender authorization, and application log for details.',
            ]);
        }

        if ($sent === null) {
            Log::warning('Mail connection test was cancelled before sending.', ['user_id' => $request->user()->id]);

            return back()->withInput()->withErrors(['mail' => 'The message was not sent. A mail event listener may have cancelled it.']);
        }

        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        $status = match ($transport) {
            'log' => 'Test message written to the configured mail log. No email was delivered because the mail transport is log.',
            'array' => 'Test message captured in memory only. No email was delivered because the mail transport is array.',
            default => 'The configured mailer accepted the test message. This does not confirm inbox delivery; check the recipient inbox and provider delivery logs. A failover mailer may have used a fallback transport.',
        };

        return redirect()->route('utilities.index')->withInput()->with('status', $status);
    }
}
