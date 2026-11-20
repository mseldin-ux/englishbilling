<?php

namespace App\Workflows;

use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\Steps\Graduation;
use Closure;

/**
 * A stage completed by staff: an offline task, a checklist, or an approval.
 * Titles, instructions and field labels can be overridden on the workflow settings page.
 */
class ManualStep extends Step implements AcceptsInput
{
    /** @param  array<string, array{type: string, label: string, required?: bool}>  $fields */
    public function __construct(
        private string $title,
        private string $role,
        private array $fields,
        private ?string $instructions = null,
        private ?Closure $context = null,
    ) {}

    public function title(): string
    {
        return Settings::stage($this->workflow, $this->key)['title'] ?? $this->title;
    }

    public function defaultTitle(): string
    {
        return $this->title;
    }

    public function defaultInstructions(): ?string
    {
        return $this->instructions;
    }

    /** @return array<string, array{type: string, label: string, required?: bool}> */
    public function defaultFields(): array
    {
        return $this->fields;
    }

    public function isApproval(): bool
    {
        return in_array('yesno', array_column($this->fields, 'type'), true);
    }

    public function polls(): bool
    {
        return $this->isApproval();
    }

    public function templates(): array
    {
        if (! $this->isApproval()) {
            return [];
        }

        return [new TemplateDefinition(
            key: "{$this->workflow}-{$this->key}-approval",
            name: $this->title().' approval reminder',
            description: 'Sent once when this approval becomes active, to its configured recipients or team default.',
            placeholders: [...Graduation::PLACEHOLDERS, 'stage_title' => 'Approval stage', 'approval_link' => 'Link to the approval form (staff login required)'],
            required: ['approval_link'],
            subject: 'Approval needed: {learner_name} — {stage_title}',
            body: "Hello,\n\n{learner_name} ({program}) is ready for {stage_title}.\n\nPlease review and complete the approval:\n{approval_link}\n\nThe CGMS Admin Team",
        )];
    }

    public function role(): string
    {
        return $this->role;
    }

    public function waitingOn(): string
    {
        return $this->role;
    }

    public function howCompleted(): string
    {
        $who = $this->role === 'finance' ? 'Finance (or any user with billing access)' : 'Records staff (Records level and up)';
        $types = array_column($this->fields, 'type');
        $rules = array_filter([
            in_array('yesno', $types, true) ? 'Every Yes/No question must be answered Yes. Answering No pauses the workflow with your note until someone resumes it.' : null,
            in_array('check', $types, true) ? 'Every checkbox must be ticked.' : null,
            in_array('file', $types, true) ? 'The file must be uploaded.' : null,
        ]);

        return "Completed by {$who} on the learner's workflow page: open the learner from this list, answer the questions below, and click Complete. "
            .implode(' ', $rules).($this->isApproval()
                ? ' A reminder with a link to this approval is emailed once when it becomes active, using its configured recipients or team default on Workflow Settings.' : '');
    }

    public function instructions(): ?string
    {
        return Settings::stage($this->workflow, $this->key)['instructions'] ?? $this->instructions;
    }

    public function fields(WorkflowRun $run): array
    {
        $labels = Settings::stage($this->workflow, $this->key)['labels'] ?? [];
        $fields = $this->fields;
        foreach ($fields as $name => $field) {
            $fields[$name]['label'] = $labels[$name] ?? $field['label'];
        }

        return $fields;
    }

    public function canComplete(User $user): bool
    {
        return $this->role === 'finance' ? $user->canAccessBilling() : $user->canUseRecordsWorkspace();
    }

    public function checkInput(WorkflowRun $run, array $answers): array
    {
        return [];
    }

    public function reach(WorkflowRun $run, WorkflowStep $step): Outcome
    {
        if (isset($step->data['submitted_at'])) {
            return Outcome::done();
        }
        if (! $this->isApproval() || isset($step->data['approval_notification'])) {
            return Outcome::waiting();
        }
        $override = Settings::stage($this->workflow, $this->key)['recipients'] ?? '';
        $recipients = $override !== '' ? EmailStep::addresses($override)
            : Settings::emails($this->role === 'finance' ? 'finance_email' : 'records_email');
        $notification = new NotifyEmail(
            $this->templates()[0],
            fn () => $recipients,
            fn () => [...Graduation::for($run)->variables(), 'stage_title' => $this->title(),
                'approval_link' => Graduation::staffLink('workflows.runs.show', $run->id).'#current-stage'],
            missing: 'No approval recipients are configured. An admin must set the approval recipients or team default on Workflow Settings.',
        );
        $outcome = $notification->reach($run, $step);

        return Outcome::waiting(data: ['approval_notification' => [...$outcome->email, 'sent_at' => now()->toIso8601String()]]);
    }

    public function context(WorkflowRun $run): array
    {
        return $this->context ? ($this->context)($run) : [];
    }
}
