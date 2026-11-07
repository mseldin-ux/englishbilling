<?php

namespace App\Workflows;

use App\Models\FormData;
use App\Models\WorkflowRun;
use App\Workflows\Steps\Graduation;

/**
 * Starts when a Course Completion run completes, for the same Graduation Initiation form. One year
 * later it emails the graduate their program's Employment Question Form link. Kept separate so the
 * graduation workflow can complete when the graduate is done. Stage keys are stored on runs; never rename them.
 */
class EmploymentFollowUpWorkflow implements Workflow
{
    public function key(): string
    {
        return 'employment-follow-up';
    }

    public function title(): string
    {
        return 'Employment Follow-up';
    }

    public function trigger(): string
    {
        return Workflows::completedTrigger('course-completion');
    }

    public function subjectType(): string
    {
        return 'formsdata';
    }

    public function studentId(int $subjectId): ?int
    {
        return FormData::whereKey($subjectId)->value('student_id');
    }

    public function programId(int $subjectId): ?int
    {
        return FormData::whereKey($subjectId)->value('program_id');
    }

    public function guide(): array
    {
        return [
            'wait-one-year' => 'This workflow starts automatically when the learner\'s Course Completion workflow is complete. It then waits one calendar year (a graduation on February 29 waits until February 28).',
            'send-employment-question-form' => 'Emails the graduate a link to their program\'s Employment Question Form. The link is the program\'s Employment Question Form link on Workflow Settings, or the default link there when the program has none. If neither is set, the stage fails until a link is saved, then sends automatically. Sending this email completes the workflow.',
        ];
    }

    public function steps(): array
    {
        return [
            'wait-one-year' => new TimerStep('One year after graduation', fn () => 1, 'years'),

            'send-employment-question-form' => new NotifyEmail(
                new TemplateDefinition('employment-question-form', 'Employment Question Form (to the graduate, one year later)',
                    'Sent to the graduate one year after their Course Completion workflow completes, with their program\'s Employment Question Form link.',
                    [...Graduation::PLACEHOLDERS, 'employment_form_link' => 'The program\'s Employment Question Form link (Workflow Settings)'],
                    ['employment_form_link'],
                    'How is your Montessori work going, {learner_first_name}?',
                    <<<'TEXT'
Dear {learner_first_name},

It has been a year since you completed the {program} program at the Center for Guided Montessori Studies. Congratulations again!

We would love to hear where your Montessori training has taken you. Please take a few minutes to answer our short Employment Question Form:

{employment_form_link}

Your answers help us improve our programs and support our accreditation.

With warm regards,
The CGMS Admin Team
TEXT),
                fn (WorkflowRun $run) => Graduation::for($run)->learnerEmails(),
                function (WorkflowRun $run) {
                    $graduation = Graduation::for($run);
                    $link = Settings::surveyUrl('employment_form_url', $graduation->program());
                    if ($link === '') {
                        throw new StepFailed('No Employment Question Form link is set for this program and there is no default link. An admin can enter one on the Workflow Settings page; the stage then continues automatically.');
                    }

                    return [...$graduation->variables(), 'employment_form_link' => $link];
                },
                missing: 'The graduate has no valid email address on their student record. Fix the email address, then click Retry.',
            ),
        ];
    }
}
