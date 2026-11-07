<?php

namespace App\Workflows\Steps;

use App\Models\FinalGrade;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Workflows\EmailStep;
use App\Workflows\StepFailed;
use App\Workflows\TemplateDefinition;

class RemindDirectorFinalGrades extends EmailStep
{
    public function template(): TemplateDefinition
    {
        return new TemplateDefinition(
            key: 'final-grades-reminder',
            name: 'Final Grades reminder (to the director)',
            description: 'Sent to the login that submitted the Graduation Initiation form, using the email address on that user account.',
            placeholders: [...Graduation::PLACEHOLDERS,
                'director_login_name' => 'Name on the director\'s login',
                'final_grades_link' => 'Link to the learner\'s Final Grades form (staff login required)',
                'graduation_form_link' => 'Link to the submitted Graduation Initiation form (staff login required)',
            ],
            required: ['final_grades_link'],
            subject: 'Final Grades needed for {learner_name}',
            body: <<<'TEXT'
Dear {director_name},

Thank you for submitting the Adult Learner Graduation Initiation form for {learner_name} ({program}, cohort {cohort}).

Please fill out the Final Grades form for this Adult Learner, or forward this email to the instructor so that they can complete it:

{final_grades_link}

Certificates are ordered on the 10th of each month. Please complete the Final Grades form by the 10th of the month for the certificate to be ordered.

Thank you,
The CGMS Admin Team
TEXT,
        );
    }

    protected function recipients(WorkflowRun $run): array
    {
        $director = $this->director($run);
        $addresses = self::addresses($director->email);
        if ($addresses === []) {
            throw new StepFailed("The login that submitted the form ({$director->name}, {$director->username}) has no email address. An admin can add one under Users, then click Retry.");
        }

        return $addresses;
    }

    /** The reminder is a courtesy; if it cannot be sent the workflow still continues. */
    public function required(): bool
    {
        return false;
    }

    protected function variables(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);
        $form = $graduation->form;
        $sheet = FinalGrade::where('studentid', $form->student_id)->where('program_id', $form->program_id)->value('id');

        return [...$graduation->variables(),
            'director_login_name' => $this->director($run)->name,
            'final_grades_link' => $sheet ? Graduation::staffLink('forms.grades.edit', $sheet)
                : Graduation::staffLink('forms.grades.create', ['student' => $form->student_id, 'program' => $form->program_id]),
            'graduation_form_link' => Graduation::staffLink('forms.edit', $form->id)];
    }

    private function director(WorkflowRun $run): User
    {
        return User::find(Graduation::for($run)->form->submitted_by)
            ?? throw new StepFailed('The login that submitted the Graduation Initiation form no longer exists.');
    }
}
