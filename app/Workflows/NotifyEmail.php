<?php

namespace App\Workflows;

use App\Models\WorkflowRun;
use Closure;

/** An email stage configured in the workflow definition. Each closure receives the WorkflowRun. */
class NotifyEmail extends EmailStep
{
    public function __construct(
        private TemplateDefinition $definition,
        private Closure $to,
        private Closure $variables,
        private ?Closure $cc = null,
        private ?Closure $attach = null,
        private ?Closure $skip = null,
        private bool $required = true,
        private string $missing = 'No valid email address is on file for the recipient.',
        private ?Closure $context = null,
        private ?Closure $record = null,
        private array $letters = [],
    ) {}

    public function templates(): array
    {
        return [$this->definition, ...$this->letters];
    }

    protected function record(WorkflowRun $run): array
    {
        return $this->record ? ($this->record)($run) : [];
    }

    public function template(): TemplateDefinition
    {
        return $this->definition;
    }

    public function required(): bool
    {
        return $this->required;
    }

    protected function recipients(WorkflowRun $run): array
    {
        return ($this->to)($run);
    }

    protected function variables(WorkflowRun $run): array
    {
        return ($this->variables)($run);
    }

    protected function cc(WorkflowRun $run): array
    {
        return $this->cc ? ($this->cc)($run) : [];
    }

    protected function attachments(WorkflowRun $run): array
    {
        return $this->attach ? ($this->attach)($run) : [];
    }

    protected function skipReason(WorkflowRun $run): ?string
    {
        return $this->skip ? ($this->skip)($run) : null;
    }

    protected function noRecipients(): string
    {
        return $this->missing;
    }

    public function context(WorkflowRun $run): array
    {
        return $this->context ? ($this->context)($run) : [];
    }
}
