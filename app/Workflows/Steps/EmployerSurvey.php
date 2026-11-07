<?php

namespace App\Workflows\Steps;

use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\AcceptsInput;
use App\Workflows\EmailStep;
use App\Workflows\Outcome;
use App\Workflows\Settings;
use App\Workflows\StepFailed;
use App\Workflows\TemplateDefinition;

/**
 * Emails the employer survey to the address on the student record (sponsor_email). When there
 * is none, staff enter an address (kept only in the workflow) or choose to skip the survey.
 */
class EmployerSurvey extends EmailStep implements AcceptsInput
{
    /** @var array<int, string> */
    private array $to = [];

    public function template(): TemplateDefinition
    {
        return new TemplateDefinition(
            key: 'employer-graduate-survey',
            name: 'Employer Graduate Survey (to the employer)',
            description: 'Sent to the employer email on the student record (sponsor_email), or to an address entered by staff on the workflow page.',
            placeholders: [...Graduation::PLACEHOLDERS,
                'employer_contact' => 'Employer contact name on the student record (sponsor_contact)',
                'school' => 'School on the student record',
                'survey_link' => 'Employer Graduate Survey link (Workflow Settings)',
            ],
            required: ['survey_link'],
            subject: 'CGMS Employer Graduate Survey: {learner_name}',
            body: <<<'TEXT'
Dear {employer_contact},

{learner_name} has completed the {program} program at the Center for Guided Montessori Studies. Thank you for supporting their Montessori education.

We would be grateful if you could take a few minutes to tell us about their preparation by completing our short Employer Graduate Survey:

{survey_link}

Your feedback helps us improve our programs.

Warm regards,
The CGMS Admin Team
TEXT,
        );
    }

    public function title(): string
    {
        return Settings::stage($this->workflow, $this->key)['title'] ?? 'Employer Graduate Survey';
    }

    public function howCompleted(): string
    {
        return 'Automatic when the student record has an employer email (sponsor_email): the survey email is sent right away. '
            .'Otherwise the stage waits for Records staff to enter an employer email address on the learner\'s workflow page (the email is then sent), or to tick the box to skip the survey.';
    }

    public function role(): string
    {
        return 'records';
    }

    public function waitingOn(): string
    {
        return 'records';
    }

    public function instructions(): ?string
    {
        return Settings::stage($this->workflow, $this->key)['instructions']
            ?? 'There is no valid employer email (sponsor_email) on the student record. Enter the employer\'s email address to send the survey, or tick the box to skip it. An address entered here is kept with this workflow only; the student record is not changed.';
    }

    public function fields(WorkflowRun $run): array
    {
        $labels = Settings::stage($this->workflow, $this->key)['labels'] ?? [];

        return [
            'employer_email' => ['type' => 'email', 'label' => $labels['employer_email'] ?? 'Employer email address', 'required' => false],
            'skip' => ['type' => 'check', 'label' => $labels['skip'] ?? 'No employer email is available; skip the employer survey', 'required' => false],
        ];
    }

    public function canComplete(User $user): bool
    {
        return $user->canUseRecordsWorkspace();
    }

    public function checkInput(WorkflowRun $run, array $answers): array
    {
        $email = $answers['employer_email'] ?? null;
        if ($email && ! empty($answers['skip'])) {
            return ['skip' => 'Either enter an email address or skip the survey, not both.'];
        }

        return $email || ! empty($answers['skip']) ? [] : ['employer_email' => 'Enter the employer\'s email address, or tick the box to skip the survey.'];
    }

    public function reach(WorkflowRun $run, WorkflowStep $step): Outcome
    {
        $answers = $step->data['answers'] ?? [];
        if (! empty($answers['skip'])) {
            return Outcome::skipped('No employer email was available; staff skipped the survey.');
        }
        $entered = self::addresses($answers['employer_email'] ?? null);
        $this->to = $entered ?: self::addresses(Graduation::for($run)->student->sponsor_email);
        if ($this->to === []) {
            return Outcome::waiting('No employer email on the student record. Enter one or skip the survey.');
        }
        if (Settings::surveyUrl('employer_survey_url', Graduation::for($run)->program()) === '') {
            throw new StepFailed('No Employer Graduate Survey link is set for this program and there is no default link. An admin can enter one on the Workflow Settings page; the stage then continues automatically.');
        }

        return $this->send($run, ['source' => $entered ? 'entered by staff' : 'student record']);
    }

    protected function recipients(WorkflowRun $run): array
    {
        return $this->to;
    }

    protected function variables(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);

        return [...$graduation->variables(),
            'employer_contact' => trim((string) $graduation->student->sponsor_contact) ?: 'Colleague',
            'school' => trim((string) $graduation->student->school),
            'survey_link' => Settings::surveyUrl('employer_survey_url', $graduation->program())];
    }

    public function context(WorkflowRun $run): array
    {
        $student = Graduation::for($run)->student;

        return ['Employer email on record' => $student->sponsor_email ?: '(none)',
            'Employer contact' => $student->sponsor_contact ?: '(none)', 'School' => $student->school ?: '(none)'];
    }
}
