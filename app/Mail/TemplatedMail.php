<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TemplatedMail extends Mailable
{
    /** @param  array<int, array{name: string, data: string, mime?: string}>  $files */
    public function __construct(public string $subjectLine, public string $textBody, public string $htmlBody, public array $files = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody, text: 'emails.plain', with: ['textBody' => $this->textBody]);
    }

    public function attachments(): array
    {
        return array_map(fn (array $file) => Attachment::fromData(fn () => $file['data'], $file['name'])
            ->withMime($file['mime'] ?? 'application/pdf'), $this->files);
    }
}
