<?php

namespace App\Workflows\Steps;

use App\Models\CourseCompletionInvitation;
use App\Models\WorkflowRun;
use App\Workflows\EmailStep;
use App\Workflows\Settings;
use App\Workflows\StepFailed;
use App\Workflows\TemplateDefinition;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class InviteLearnerToCourseCompletion extends EmailStep
{
    public function template(): TemplateDefinition
    {
        return new TemplateDefinition(
            key: 'course-completion-invitation',
            name: 'Course Completion Form invitation (to the Adult Learner)',
            description: 'Sent to every address in the learner\'s email field when a Graduation Initiation form is submitted.',
            placeholders: [...Graduation::PLACEHOLDERS,
                'form_link' => 'The learner\'s personal Course Completion Form link',
                'link_expires' => 'Date the link stops working',
                'survey_link' => 'Course Completion Survey link',
            ],
            required: ['form_link'],
            subject: 'Your CGMS Course Completion Form',
            body: <<<'TEXT'
Dear {learner_first_name},

Congratulations! Your level director has begun the graduation process for your {program} program.

Please complete your Course Completion Form so that we can order your certificate. It asks how you would like your name to appear on your certificate and where it should be mailed:

{form_link}

This link is personal to you; please do not share it. It remains active until {link_expires}, and you may use it again if you need to update your submission.

Before your certificate is ordered, please also complete the short Course Completion Survey. Answers are anonymous and are greatly appreciated by the faculty, director and administration team of CGMS:

{survey_link}

Certificates are ordered on the 10th of each month.

Warm regards,
The CGMS Admin Team
TEXT,
        );
    }

    protected function recipients(WorkflowRun $run): array
    {
        return Graduation::for($run)->learnerEmails();
    }

    protected function noRecipients(): string
    {
        return 'The Adult Learner has no valid email address on their student record. Fix the email address, then click Retry.';
    }

    protected function variables(WorkflowRun $run): array
    {
        $invitation = self::invitationFor($run);

        return [...Graduation::for($run)->variables(),
            'form_link' => $invitation->url(),
            'link_expires' => $invitation->expires_at->setTimezone(config('app.timezone'))->format('F j, Y'),
            'survey_link' => Settings::surveyUrl('course_completion_survey_url', $invitation->program)];
    }

    /** The run's invitation, created on first send and renewed if it has expired. */
    public static function invitationFor(WorkflowRun $run): CourseCompletionInvitation
    {
        $invitation = CourseCompletionInvitation::where('workflow_run_id', $run->id)->first();
        if ($invitation?->revoked_at) {
            throw new StepFailed('The Course Completion link was revoked because the Graduation Initiation form was deleted.');
        }
        $expires = now()->addDays(config('workflows.invitation_days', 180));
        if ($invitation === null) {
            $form = Graduation::for($run)->form;
            $token = Str::random(64);
            $invitation = CourseCompletionInvitation::create(['student_id' => $form->student_id, 'program_id' => $form->program_id,
                'formsdata_id' => $form->id, 'workflow_run_id' => $run->id, 'token_hash' => CourseCompletionInvitation::hash($token),
                'token_encrypted' => Crypt::encryptString($token), 'expires_at' => $expires]);
        } elseif (! $invitation->expires_at->isFuture()) {
            $invitation->forceFill(['expires_at' => $expires])->save();
        }

        return $invitation;
    }
}
