<?php

namespace App\Workflows;

use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
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
            .implode(' ', $rules);
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
        return isset($step->data['submitted_at']) ? Outcome::done() : Outcome::waiting();
    }

    public function context(WorkflowRun $run): array
    {
        return $this->context ? ($this->context)($run) : [];
    }
}
