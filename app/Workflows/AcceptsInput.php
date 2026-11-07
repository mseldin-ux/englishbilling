<?php

namespace App\Workflows;

use App\Models\User;
use App\Models\WorkflowRun;

/**
 * A stage staff complete on the run page. Field types:
 *  - yesno: Yes continues; No pauses the run and requires a note
 *  - check: must be ticked
 *  - text, textarea, date, email: optional unless 'required' => true
 *  - file: a PDF upload (replacing overwrites the stored file)
 */
interface AcceptsInput
{
    /** 'finance' (billing staff and admins) or 'records' (records level and up). */
    public function role(): string;

    /** @return array<string, array{type: string, label: string, required?: bool}> */
    public function fields(WorkflowRun $run): array;

    public function instructions(): ?string;

    public function canComplete(User $user): bool;

    /**
     * Checks that span fields, after each field's own rules pass.
     *
     * @return array<string, string> field => message
     */
    public function checkInput(WorkflowRun $run, array $answers): array;
}
