<?php

namespace App\Workflows;

/**
 * A workflow is a named list of steps that runs once per record when its trigger fires.
 * Register new workflows in config/workflows.php.
 */
interface Workflow
{
    /** Stable identifier stored on each run; never rename once runs exist. */
    public function key(): string;

    public function title(): string;

    /** Event name passed to Workflows::trigger(), for example "graduation-initiation.submitted". */
    public function trigger(): string;

    /** Table name of the record that starts the workflow, for example "formsdata". */
    public function subjectType(): string;

    /** Learner the run concerns, used for listing and filtering. */
    public function studentId(int $subjectId): ?int;

    /** Program the run concerns, if any. */
    public function programId(int $subjectId): ?int;

    /**
     * Ordered stages keyed by a stable key. A run waits at the first unfinished stage.
     * Never rename a key once runs exist; add new stages with new keys.
     *
     * @return array<string, Step|class-string<Step>>
     */
    public function steps(): array;

    /**
     * Plain-language description of what each stage does, keyed like steps(), for the stage guide.
     *
     * @return array<string, string>
     */
    public function guide(): array;
}
