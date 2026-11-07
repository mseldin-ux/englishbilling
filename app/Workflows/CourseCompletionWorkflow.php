<?php

namespace App\Workflows;

use App\Billing\Ledger;
use App\Forms\FinalGrades;
use App\Models\CourseCompletion;
use App\Models\CourseCompletionInvitation;
use App\Models\FinalGrade;
use App\Models\FormData;
use App\Models\WorkflowRun;
use App\Workflows\Steps\EmployerSurvey;
use App\Workflows\Steps\Graduation;
use App\Workflows\Steps\InviteLearnerToCourseCompletion;
use App\Workflows\Steps\MarkGraduated;
use App\Workflows\Steps\RemindDirectorFinalGrades;
use Closure;

/**
 * The graduation pipeline started by a submitted Graduation Initiation form: from the learner's
 * Course Completion Form through finance clearance, records review, credential ordering and
 * mailing, to final checks. Stage keys are stored on runs; never rename them.
 */
class CourseCompletionWorkflow implements Workflow
{
    public const TRIGGER = 'graduation-initiation.submitted';

    public function key(): string
    {
        return 'course-completion';
    }

    public function title(): string
    {
        return 'Course Completion';
    }

    public function trigger(): string
    {
        return self::TRIGGER;
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
            'remind-director' => 'The workflow starts when a Level Director submits the Adult Learner Graduation Initiation form. This first stage emails the director who submitted it (the email address on their login), reminding them to fill out the Final Grades form or forward it to the instructor. The email includes a direct link to the learner\'s Final Grades form.',
            'invite-learner' => 'Emails the Adult Learner (the email address on their student record) a personal link to the Course Completion Form. No login is needed; the link stays active for 180 days and the learner can submit more than once.',
            'await-course-completion' => 'Waits for the Adult Learner to submit the Course Completion Form: their name as it should appear on the certificate, mailing address, whether the address is in the United States, cohort, instructor, survey confirmation, expected graduation date, current employment and any additional information.',
            'notify-finance-international' => 'If the learner\'s certificate mailing address is outside the United States, emails Finance (Finance team on Workflow Settings) with a copy of the Course Completion Form so they can arrange the international mailing payment. Skipped automatically when the address is in the United States. Sent again if a later submission changes the address.',
            'await-final-grades' => 'Waits for the Final Grades form for this learner and program to be submitted (not just saved as a draft) by the Level Director or instructor.',
            'request-finance-clearance' => 'Emails Finance (Finance team on Workflow Settings) asking them to review the learner\'s account and record whether their financial requirements are satisfied. The email includes the current balance and a link to this workflow.',
            'finance-clearance' => 'Finance confirms that the Adult Learner has satisfied their financial requirements, including any international mailing payment. The learner\'s billing details are shown with the question.',
            'records-review' => 'Records staff confirm the learner\'s record and graduation documents are in order: legal name, program/level, completion date, email and mailing address; merged verification documents; the Course Completion Letter; and the final transcript.',
            'credential-ready' => 'Approval: Records staff confirm the credential is ready to order. Answering Yes starts the graduation emails that follow.',
            'send-verified-info' => 'Emails the verified graduate information (certificate name, legal name, program, cohort, graduation date, email and mailing address) to the "Verified graduate information recipients" list on Workflow Settings.',
            'congratulate-graduate' => 'Emails the graduate a congratulations message with two PDFs attached: their final transcript (the Final Grades form) and a personalized congratulations letter on CGMS letterhead.',
            'employer-survey' => 'Emails the learner\'s employer a link to the Employer Graduate Survey (the program\'s link on Workflow Settings, or the default link). The employer address comes from the student record\'s employer email (sponsor_email).',
            'mark-graduated' => 'Marks the Adult Learner as Graduated in the student database and sets their actual graduation date to today.',
            'notify-admin-status-sheets' => 'Emails the admin team (Admin team on Workflow Settings) a reminder to change the learner\'s status to Graduated on the CGMS Adult Learner status sheets used for accreditation reporting and statistics.',
            'notify-alumni' => 'Emails the "Alumni coordinator and marketing" list on Workflow Settings asking them to add the graduate to the alumni list, with the graduate\'s name, email address and mailing address.',
            'credential-check' => 'Records staff confirm the credential has been checked for accuracy (name, credential level/program, dates, seals and other identifying information) and scanned into the learner\'s permanent file.',
            'credential-upload' => 'Records staff upload a PDF scan of the graduate\'s credential. It is attached to the final congratulations email and can be replaced later from the learner\'s workflow page.',
            'mailing-packet' => 'A checklist reminding Records staff to prepare the credential mailing packet: original credential, Maria Montessori letter, Director of Education letter, printed transcript, mailing labels and postage, and the waterproof mailer inside the cardboard package. The learner\'s mailing address is shown with the checklist.',
            'final-congratulations' => 'Emails the graduate a final congratulations message with a PDF copy of their credential attached. It says the original will be mailed within the next week, lists the mailing address on file and asks them to reply immediately if it has changed, and includes alumni information. It copies the program\'s Level Director team email(s) (Workflow Settings) and the instructor email from the Graduation Initiation form.',
            'correction-wait' => 'Waits a few days (the correction waiting period on Workflow Settings) so the graduate can report a wrong name or address before the credential is mailed.',
            'notify-wait-over' => 'Emails the admin team (Admin team on Workflow Settings) that the correction waiting period has ended.',
            'no-corrections' => 'Records staff confirm that the graduate reported no corrections during the waiting period.',
            'packet-mailed' => 'Records staff confirm the credential packet has been mailed to the confirmed address and record the mailing date, with optional tracking information. The mailing address on file is shown with the questions.',
            'final-checks' => 'Records staff confirm the graduate\'s file is complete (credential scan, transcript, completion letter and relevant communications) and that all CGMS status/reporting systems show the learner as Graduated.',
            'notify-complete' => 'Emails the admin team (Admin team on Workflow Settings) that the workflow is complete for this Adult Learner. The workflow is then marked Complete, and the one-year Employment Follow-up workflow starts.',
        ];
    }

    public function steps(): array
    {
        $learner = fn (WorkflowRun $run) => Graduation::for($run)->learnerEmails();
        $adminTeam = fn () => Settings::emails('admin_team_email');
        $noLearnerEmail = 'The Adult Learner has no valid email address on their student record. Fix the email address, then click Retry.';

        return [
            // The optional director reminder goes first so it is sent even if the learner's email address is unusable.
            'remind-director' => RemindDirectorFinalGrades::class,
            'invite-learner' => InviteLearnerToCourseCompletion::class,

            'await-course-completion' => new AwaitStep('Adult Learner submits the Course Completion Form', 'learner',
                fn (WorkflowRun $run) => ($completion = Graduation::for($run)->completion()) ? ['submission_id' => $completion->id] : null,
                fn (WorkflowRun $run) => $this->invitationContext($run)),

            'notify-finance-international' => $this->email('finance-international-mailing',
                'International mailing request (to Finance)',
                'Sent to Finance when the Adult Learner\'s certificate mailing address is outside the United States, with a copy of their Course Completion Form. Sent again if a later submission changes the address.',
                'International certificate mailing: {learner_name}',
                <<<'TEXT'
Hello Finance team,

{learner_name} ({program}) has asked for their certificate to be mailed outside the United States. Please contact them about the international mailing payment.

Course Completion Form submitted {submitted_at}:
Name on certificate: {certificate_name}
Mailing address:
{mailing_address}
Address in the United States: {address_in_us}
Cohort: {completion_cohort}
Instructor: {instructor}
Expected graduation date: {expected_graduation_date}
Currently: {employment_status}
Completed the Course Completion Survey: {survey_completed}
Replaces a previous submission: {replaces_previous}
Additional information: {additional_info}

Learner email: {learner_email}

View the submission: {submission_link}

The CGMS Admin Team
TEXT,
                fn () => Settings::emails('finance_email'),
                extra: $this->completionPlaceholders(),
                vars: fn (WorkflowRun $run) => $this->completionVariables($run),
                skip: fn (WorkflowRun $run) => Graduation::for($run)->requireCompletion()->address_in_us
                    ? 'The certificate mailing address is in the United States.' : null,
                missing: 'No Finance email address is set. An admin can enter it on the Workflow Settings page.',
                record: fn (WorkflowRun $run) => ['submission_id' => Graduation::for($run)->requireCompletion()->id]),

            'await-final-grades' => new AwaitStep('Final Grades submitted', 'director',
                fn (WorkflowRun $run) => ($sheet = Graduation::for($run)->gradeSheet()) ? ['sheet_id' => $sheet->id] : null,
                fn (WorkflowRun $run) => $this->gradesContext($run)),

            'request-finance-clearance' => $this->email('finance-clearance-request',
                'Finance clearance request (to Finance)',
                'Sent to Finance once the Course Completion Form and Final Grades are in, asking them to confirm the learner has met their financial requirements.',
                'Finance clearance needed: {learner_name}',
                <<<'TEXT'
Hello Finance team,

{learner_name} ({program}, cohort {cohort}) has completed their coursework and is ready for graduation.

Please review their account and record whether they have satisfied their financial requirements:

{workflow_link}

Current balance: {balance}
Certificate mailing address in the United States: {address_in_us}

The CGMS Admin Team
TEXT,
                fn () => Settings::emails('finance_email'),
                extra: ['balance' => 'Current account balance', 'billing_link' => 'Link to the learner\'s billing record (staff login required)'],
                vars: fn (WorkflowRun $run) => $this->financeVariables($run),
                missing: 'No Finance email address is set. An admin can enter it on the Workflow Settings page.'),

            'finance-clearance' => new ManualStep('Finance clearance', 'finance', [
                'cleared' => ['type' => 'yesno', 'label' => 'Has the Adult Learner satisfied their financial requirements?'],
            ], 'Review the learner\'s billing record. Answer Yes once all financial requirements, including any international mailing payment, are satisfied. Answer No to pause the workflow with a note.',
                fn (WorkflowRun $run) => $this->financeContext($run)),

            'records-review' => new ManualStep('Records review', 'records', [
                'record_reviewed' => ['type' => 'yesno', 'label' => 'The Adult Learner’s record has been reviewed for correct legal name, program/level, completion date, email, and mailing address.'],
                'documents_merged' => ['type' => 'yesno', 'label' => 'The verification/completion documents have been merged and saved in the Adult Learner’s file.'],
                'letter_finished' => ['type' => 'yesno', 'label' => 'The Course Completion Letter is finished.'],
                'transcript_finished' => ['type' => 'yesno', 'label' => 'The final transcript is finished.'],
            ], 'All items must be answered Yes to continue. Answering No pauses the workflow with your note.',
                fn (WorkflowRun $run) => $this->reviewContext($run)),

            'credential-ready' => new ManualStep('Credential ready to order', 'records', [
                'ready' => ['type' => 'yesno', 'label' => 'Is the credential ready to order?'],
            ], 'Answer Yes once the credential has been ordered. The verified graduate information is then emailed and the graduate is congratulated.'),

            'send-verified-info' => $this->email('verified-graduate-information',
                'Verified graduate information (to the configured list)',
                'Sent to the "Verified graduate information recipients" on the Workflow Settings page once the credential is ordered.',
                'Verified graduate information: {certificate_name}',
                <<<'TEXT'
The following graduate information has been verified and the credential has been ordered.

Name on certificate: {certificate_name}
Legal name on record: {learner_name}
Program/level: {program}
Cohort: {cohort}
Graduation date: {graduation_date}
Email: {learner_email}
Mailing address:
{mailing_address}

The CGMS Admin Team
TEXT,
                fn () => Settings::emails('graduate_info_recipients'),
                missing: 'No recipients are set for the verified graduate information. An admin can add them on the Workflow Settings page; the stage then continues automatically.'),

            'congratulate-graduate' => $this->email('graduate-congratulations',
                'Congratulations with transcript (to the graduate)',
                'Sent to the graduate with their final transcript (the Final Grades form) and the congratulations letter attached as PDFs.',
                'Congratulations, {learner_first_name}!',
                <<<'TEXT'
Dear {learner_first_name},

Congratulations on completing the {program} program at the Center for Guided Montessori Studies!

Attached are your final transcript and a letter from CGMS. Your credential has been ordered, and we will email you again when it is ready to be mailed.

With warm congratulations,
The CGMS Admin Team
TEXT,
                $learner,
                missing: $noLearnerEmail,
                attach: fn (WorkflowRun $run) => $this->transcriptAndLetter($run),
                letters: [new TemplateDefinition(
                    key: 'graduate-congratulations-letter',
                    name: 'Congratulations letter (PDF attached for the graduate)',
                    description: 'Printed on CGMS letterhead and attached to the congratulations email. The "subject" is used as the PDF file name.',
                    placeholders: Graduation::PLACEHOLDERS,
                    required: [],
                    subject: 'Congratulations {learner_name}',
                    body: <<<'TEXT'
{today}

Dear {certificate_name},

Congratulations on completing the {program} program!

Your dedication to Montessori education, through your coursework, residency and practicum, has prepared you to guide children with care and respect. The faculty, directors and administration team of the Center for Guided Montessori Studies are proud of all you have accomplished.

We look forward to hearing about your work with children, and we hope you will stay in touch as a member of the CGMS alumni community.

With warm regards,

The Center for Guided Montessori Studies
TEXT,
                    kind: 'letter',
                )]),

            'employer-survey' => EmployerSurvey::class,

            'mark-graduated' => MarkGraduated::class,

            'notify-admin-status-sheets' => $this->email('admin-status-sheets-reminder',
                'Status sheets reminder (to the admin team)',
                'Sent to the admin team after the learner is marked Graduated, as a reminder to update the accreditation status sheets.',
                'Update status sheets: {learner_name} has graduated',
                <<<'TEXT'
Hello Admin team,

{learner_name} ({program}, cohort {cohort}) has been marked Graduated in the CGMS Adult Learner database as of {today}.

Please change their status to Graduated on the CGMS Adult Learner status sheets used for accreditation reporting and statistics.

{workflow_link}
TEXT,
                $adminTeam, missing: 'No admin team email address is set. An admin can enter it on the Workflow Settings page.'),

            'notify-alumni' => $this->email('alumni-list-request',
                'Add to alumni list (to the alumni coordinator and marketing)',
                'Sent to the "Alumni coordinator and marketing" list on the Workflow Settings page.',
                'New CGMS graduate for the alumni list: {certificate_name}',
                <<<'TEXT'
Hello,

Please add this new graduate to the CGMS alumni list.

Name: {certificate_name}
Program: {program}
Email: {learner_email}
Mailing address:
{mailing_address}

Thank you,
The CGMS Admin Team
TEXT,
                fn () => Settings::emails('alumni_recipients'),
                missing: 'No alumni coordinator/marketing recipients are set. An admin can add them on the Workflow Settings page; the stage then continues automatically.'),

            'credential-check' => new ManualStep('Credential check', 'records', [
                'accurate' => ['type' => 'yesno', 'label' => 'Has the credential been checked carefully for accuracy: graduate name, credential level/program, dates, seals, and other identifying information?'],
                'scanned' => ['type' => 'yesno', 'label' => 'Has the credential been scanned and saved as a PDF/image in the Adult Learner’s permanent file?'],
            ], 'Both items must be answered Yes to continue.', fn (WorkflowRun $run) => $this->reviewContext($run)),

            'credential-upload' => new ManualStep('Upload the credential scan', 'records', [
                'credential' => ['type' => 'file', 'label' => 'Scanned credential (PDF, up to 20 MB)'],
            ], 'Upload a PDF of the graduate\'s credential. It is attached to the final congratulations email.'),

            'mailing-packet' => new ManualStep('Prepare the credential mailing packet', 'records', [
                'original' => ['type' => 'check', 'label' => 'Original credential'],
                'montessori_letter' => ['type' => 'check', 'label' => 'Copy of the Maria Montessori letter'],
                'director_letter' => ['type' => 'check', 'label' => 'Letter from the CGMS Director of Education'],
                'transcript' => ['type' => 'check', 'label' => 'Printed copy of the graduate’s transcript'],
                'labels' => ['type' => 'check', 'label' => 'Prepare and print mailing labels and postage.'],
                'mailer' => ['type' => 'check', 'label' => 'Place all items in a waterproof mailer, then place that mailer inside the cardboard mailing envelope/package.'],
            ], 'The mailing packet should include everything below. Tick each item once it is done.',
                fn (WorkflowRun $run) => ['Mailing address (latest Course Completion Form)' => Graduation::for($run)->completion()?->mailing_address ?? '(none)']),

            'final-congratulations' => $this->email('graduate-final-congratulations',
                'Final congratulations with credential (to the graduate)',
                'Sent to the graduate with a PDF copy of their credential attached, copying the program\'s Level Director team and the instructor email from the Graduation Initiation form. It must show the mailing address on file.',
                'Your CGMS credential is on its way, {learner_first_name}',
                <<<'TEXT'
Dear {learner_first_name},

Congratulations again on completing the {program} program! A PDF copy of your credential is attached. The original credential will be mailed within the next week to:

{mailing_address}

If this address has changed, or if anything on your credential is incorrect, please reply to this email immediately.

As a CGMS graduate you are now part of our alumni community. We will keep you informed about alumni news, continuing education and events, and we would love to hear about your work with children.

With warm congratulations,
The CGMS Admin Team
TEXT,
                $learner,
                required: ['mailing_address'],
                missing: $noLearnerEmail,
                cc: fn (WorkflowRun $run) => $this->finalCc($run),
                attach: fn (WorkflowRun $run) => [Graduation::for($run)->credentialFile()
                    ?? throw new StepFailed('The credential PDF is missing. Reopen the upload stage or replace the file, then click Retry.')]),

            'correction-wait' => new TimerStep('Correction waiting period', fn () => (int) Settings::get('correction_wait_days')),

            'notify-wait-over' => $this->email('correction-wait-over',
                'Correction waiting period over (to the admin team)',
                'Sent to the admin team when the waiting period after the final congratulations email ends.',
                'Waiting period complete: {learner_name}',
                <<<'TEXT'
Hello Admin team,

The correction waiting period for {learner_name} ({program}) is complete. If no corrections were received, the credential packet can be mailed to:

{mailing_address}

Please confirm on the workflow page:
{workflow_link}
TEXT,
                $adminTeam, missing: 'No admin team email address is set. An admin can enter it on the Workflow Settings page.'),

            'no-corrections' => new ManualStep('No corrections received', 'records', [
                'none_received' => ['type' => 'yesno', 'label' => 'Please confirm that no corrections were received during the waiting period.'],
            ], 'If the graduate reported a correction, answer No with a note; the workflow pauses until it is resolved.'),

            'packet-mailed' => new ManualStep('Credential packet mailed', 'records', [
                'mailed' => ['type' => 'check', 'label' => 'Confirm that the credential packet has been mailed to the confirmed address.'],
                'tracking' => ['type' => 'text', 'label' => 'Tracking information (optional)', 'required' => false],
                'mailed_on' => ['type' => 'date', 'label' => 'Date the packet was mailed', 'required' => true],
            ], null, fn (WorkflowRun $run) => ['Mailing address (latest Course Completion Form)' => Graduation::for($run)->completion()?->mailing_address ?? '(none)']),

            'final-checks' => new ManualStep('Final checks', 'records', [
                'file_complete' => ['type' => 'yesno', 'label' => 'Have you completed a final check that the graduate file contains the credential scan, transcript, completion letter, and relevant communications?'],
                'status_updated' => ['type' => 'yesno', 'label' => 'Have you confirmed that all CGMS status/reporting systems reflect Graduate status?'],
            ], 'Both items must be answered Yes to finish the workflow.'),

            'notify-complete' => $this->email('graduation-workflow-complete',
                'Workflow complete (to the admin team)',
                'Sent to the admin team when every stage of the workflow is finished.',
                'Graduation workflow complete: {learner_name}',
                <<<'TEXT'
Hello Admin team,

The graduation workflow for {learner_name} ({program}) is complete.

{workflow_link}
TEXT,
                $adminTeam, missing: 'No admin team email address is set. An admin can enter it on the Workflow Settings page.'),
        ];
    }

    /**
     * Called after the learner submits the Course Completion Form. If Finance was already handled
     * for an earlier submission and the new one is international with a different address, Finance
     * is notified again.
     */
    public static function completionSubmitted(CourseCompletion $completion): void
    {
        $run = WorkflowRun::find(CourseCompletionInvitation::whereKey($completion->invitation_id)->value('workflow_run_id'));
        if ($run === null || ! in_array($run->status, ['active', 'paused'], true)) {
            return;
        }
        $step = $run->step('notify-finance-international');
        if ($step && ! $completion->address_in_us && in_array($step->status, ['done', 'skipped'], true)) {
            $previous = CourseCompletion::find($step->data['submission_id'] ?? null);
            if ($step->status === 'skipped' || $previous === null || $previous->mailing_address !== $completion->mailing_address) {
                Workflows::retry($step, null);
            }
        }
        Workflows::signal($completion->student_id);
    }

    /** An email stage whose template has every common placeholder plus $extra. */
    private function email(string $key, string $name, string $description, string $subject, string $body, Closure $to,
        array $extra = [], array $required = [], ?Closure $vars = null, ?Closure $skip = null, ?Closure $cc = null,
        ?Closure $attach = null, string $missing = 'No valid email address is set for the recipient.', ?Closure $record = null,
        array $letters = []): NotifyEmail
    {
        return new NotifyEmail(
            new TemplateDefinition($key, $name, $description, [...Graduation::PLACEHOLDERS, ...$extra], $required, $subject, $body),
            $to,
            fn (WorkflowRun $run) => [...Graduation::for($run)->variables(), ...($vars ? $vars($run) : [])],
            cc: $cc, attach: $attach, skip: $skip, missing: $missing, record: $record, letters: $letters,
        );
    }

    /** @return array<string, string> */
    private function completionPlaceholders(): array
    {
        return ['submitted_at' => 'When the Course Completion Form was submitted', 'completion_cohort' => 'Cohort entered on the form',
            'survey_completed' => 'Whether the survey was completed', 'replaces_previous' => 'Whether it replaces a previous submission',
            'additional_info' => 'Additional information entered by the learner', 'submission_link' => 'Link to the submission (staff login required)'];
    }

    /** @return array<string, string> */
    private function completionVariables(WorkflowRun $run): array
    {
        $completion = Graduation::for($run)->requireCompletion();

        return ['submitted_at' => $completion->submitted_at->setTimezone(config('app.timezone'))->format('F j, Y g:i A'),
            'completion_cohort' => $completion->cohort, 'survey_completed' => $completion->survey_completed ? 'Yes' : 'No',
            'replaces_previous' => $completion->replaces_previous ? 'Yes' : 'No',
            'additional_info' => $completion->additional_info ?: '(none)',
            'submission_link' => Graduation::staffLink('forms.completion.show', $completion->id)];
    }

    /** @return array<string, string> */
    private function financeVariables(WorkflowRun $run): array
    {
        $student = Graduation::for($run)->student;

        return ['balance' => self::money(Ledger::balance($student->id)),
            'billing_link' => Graduation::staffLink('students.show', $student->id)];
    }

    private function invitationContext(WorkflowRun $run): array
    {
        $invitation = CourseCompletionInvitation::where('workflow_run_id', $run->id)->first();
        if ($invitation === null) {
            return ['Course Completion link' => 'Not created yet'];
        }

        return ['Course Completion link' => $invitation->revoked_at ? 'Withdrawn' : ($invitation->usable()
            ? 'Active until '.$invitation->expires_at->setTimezone(config('app.timezone'))->format('Y-m-d') : 'Expired'),
            'Last opened by the learner' => $invitation->last_opened_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i') ?? 'Never'];
    }

    private function gradesContext(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);
        $sheet = FinalGrade::where('studentid', $graduation->student->id)->where('program_id', $graduation->form->program_id)->first();

        return ['Final Grades' => $sheet
            ? [($sheet->status === 'submitted' ? 'Submitted' : 'Draft (not yet submitted)').' — open', route('forms.grades.edit', $sheet)]
            : ['Not started — start the form', route('forms.grades.create', ['student' => $graduation->student->id, 'program' => $graduation->form->program_id])]];
    }

    private function financeContext(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);
        $completion = $graduation->completion();

        return ['Current balance' => self::money(Ledger::balance($graduation->student->id)),
            'Billing record' => ['Open the learner\'s billing record', route('students.show', $graduation->student->id)],
            'International mailing' => $completion ? ($completion->address_in_us ? 'No (address in the United States)' : 'Yes — confirm the international mailing payment') : '(no Course Completion Form)'];
    }

    private function reviewContext(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);
        $completion = $graduation->completion();
        $sheet = $graduation->gradeSheet();

        return [
            'Legal name (student record)' => $graduation->student->displayName(),
            'Name for certificate (Course Completion Form)' => $completion?->certificate_name ?? '(none)',
            'Program/level' => $graduation->programName(),
            'Graduation date (Graduation Initiation)' => Graduation::date($graduation->form->data['graduation_date'] ?? null),
            'Expected graduation date (Course Completion Form)' => $completion ? Graduation::date($completion->expected_graduation_date) : '(none)',
            'Email (student record)' => $graduation->student->emailaddr ?: '(none)',
            'Address (student record)' => Graduation::address($graduation->student) ?? '(none)',
            'Mailing address (Course Completion Form)' => $completion?->mailing_address ?? '(none)',
            'Course Completion Form' => $completion ? ['Open submission '.$completion->id, route('forms.completion.show', $completion)] : '(none)',
            'Final Grades (transcript)' => $sheet ? ['Open grade sheet '.$sheet->id, route('forms.grades.edit', $sheet)] : '(not submitted)',
            'Graduation Initiation form' => ['Open form '.$graduation->form->id, route('forms.edit', $graduation->form->id)],
        ];
    }

    /** @return array<int, array{name: string, data: string, mime: string}> */
    private function transcriptAndLetter(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);
        $sheet = $graduation->gradeSheet() ?? throw new StepFailed('The Final Grades form (transcript) is not submitted for this program.');

        return [FinalGrades::pdf($sheet), EmailTemplates::letter('graduate-congratulations-letter', $graduation->variables())];
    }

    private static function money(string $amount): string
    {
        return (str_starts_with($amount, '-') ? '-$' : '$').number_format(abs((float) $amount), 2);
    }

    /** @return array<int, string> */
    private function finalCc(WorkflowRun $run): array
    {
        $graduation = Graduation::for($run);

        return [...EmailStep::addresses($graduation->program()?->director_team_email),
            ...EmailStep::addresses($graduation->form->data['instructor_email'] ?? null)];
    }
}
