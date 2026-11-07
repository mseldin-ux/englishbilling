<?php

namespace App\Workflows;

use App\Mail\TemplatedMail;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\Mail;

/**
 * A stage that sends one email built from an editable template.
 */
abstract class EmailStep extends Step
{
    abstract public function template(): TemplateDefinition;

    /** @return array<int, string> */
    abstract protected function recipients(WorkflowRun $run): array;

    /** @return array<string, string|null> */
    abstract protected function variables(WorkflowRun $run): array;

    /** @return array<int, string> */
    protected function cc(WorkflowRun $run): array
    {
        return [];
    }

    /** @return array<int, array{name: string, data: string, mime?: string}> */
    protected function attachments(WorkflowRun $run): array
    {
        return [];
    }

    /** A reason to skip sending, or null to send. */
    protected function skipReason(WorkflowRun $run): ?string
    {
        return null;
    }

    protected function noRecipients(): string
    {
        return 'No valid email address is on file for the recipient.';
    }

    public function title(): string
    {
        return Settings::stage($this->workflow, $this->key)['title'] ?? $this->template()->name;
    }

    public function howCompleted(): string
    {
        return 'Automatic: the system sends this email as soon as the stages before it are finished. '.($this->required()
            ? 'If it cannot be sent (for example, a missing email address or setting), the stage fails with an explanation and the workflow waits. Fix the problem and click Retry; a missing Workflow Settings value is retried automatically when it is saved.'
            : 'This email is optional: if it cannot be sent, the workflow continues without it.');
    }

    public function templates(): array
    {
        return [$this->template()];
    }

    public function reach(WorkflowRun $run, WorkflowStep $step): Outcome
    {
        if (($reason = $this->skipReason($run)) !== null) {
            return Outcome::skipped($reason);
        }

        return $this->send($run, $this->record($run));
    }

    /** Extra details stored with the stage when the email is sent. */
    protected function record(WorkflowRun $run): array
    {
        return [];
    }

    protected function send(WorkflowRun $run, array $data = []): Outcome
    {
        $recipients = $this->recipients($run);
        if ($recipients === []) {
            throw new StepFailed($this->noRecipients());
        }
        $cc = array_values(array_udiff($this->cc($run), $recipients, 'strcasecmp'));
        $files = $this->attachments($run);
        $message = EmailTemplates::render($this->template()->key, $this->variables($run));
        $mail = Mail::to($recipients);
        if ($cc !== []) {
            $mail->cc($cc);
        }
        $mail->send(new TemplatedMail($message['subject'], $message['text'], $message['html'], $files));

        return Outcome::done([...$data, 'cc' => $cc, 'attachments' => array_column($files, 'name')],
            ['recipients' => $recipients, 'subject' => $message['subject'], 'body' => $message['text']]);
    }

    /**
     * Splits a stored address list such as "a@x.org, b@y.org" into valid, unique addresses.
     *
     * @return array<int, string>
     */
    public static function addresses(?string $value): array
    {
        $addresses = [];
        foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $address) {
            $address = trim($address, " \t<>\"'");
            if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[strtolower($address)] = $address;
            }
        }

        return array_values($addresses);
    }
}
