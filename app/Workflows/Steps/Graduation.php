<?php

namespace App\Workflows\Steps;

use App\Models\CourseCompletion;
use App\Models\CourseCompletionInvitation;
use App\Models\FinalGrade;
use App\Models\FormData;
use App\Models\Program;
use App\Models\Student;
use App\Models\WorkflowRun;
use App\Workflows\EmailStep;
use App\Workflows\StepFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/** Lookups shared by the stages of a run started by a Graduation Initiation form. */
final class Graduation
{
    /** Placeholders available in every Course Completion workflow template. */
    public const PLACEHOLDERS = [
        'learner_first_name' => 'Learner first name',
        'learner_name' => 'Learner full name (student record)',
        'learner_email' => 'Learner email address(es) on the student record',
        'program' => 'Program description',
        'cohort' => 'Cohort (student record)',
        'graduation_date' => 'Graduation date from the Graduation Initiation form',
        'director_name' => 'Director signature typed on the Graduation Initiation form',
        'certificate_name' => 'Name for the certificate (latest Course Completion Form)',
        'mailing_address' => 'Certificate mailing address (latest Course Completion Form)',
        'address_in_us' => 'Whether the mailing address is in the United States (Yes/No)',
        'expected_graduation_date' => 'Expected graduation date (latest Course Completion Form)',
        'instructor' => 'Instructor named on the latest Course Completion Form',
        'employment_status' => 'Current employment (latest Course Completion Form)',
        'workflow_link' => 'Link to this learner\'s workflow page (staff login required)',
        'today' => 'Today\'s date',
    ];

    private function __construct(public readonly WorkflowRun $run, public readonly FormData $form, public readonly Student $student) {}

    public static function for(WorkflowRun $run): self
    {
        $form = FormData::find($run->subject_id)
            ?? throw new StepFailed('The Graduation Initiation form was deleted, so this workflow cannot continue.');
        $student = Student::find($form->student_id) ?? throw new StepFailed('The Adult Learner record no longer exists.');

        return new self($run, $form, $student);
    }

    public function program(): ?Program
    {
        return Program::find($this->form->program_id);
    }

    public function programName(): string
    {
        return $this->program()?->programdesc ?? ($this->form->data['program_name'] ?? '');
    }

    /** The learner's most recent Course Completion submission for this run. */
    public function completion(): ?CourseCompletion
    {
        $invitations = CourseCompletionInvitation::where('workflow_run_id', $this->run->id)->pluck('id');

        return CourseCompletion::whereIn('invitation_id', $invitations)->latest('submitted_at')->latest('id')->first();
    }

    public function requireCompletion(): CourseCompletion
    {
        return $this->completion() ?? throw new StepFailed('The Adult Learner has not submitted the Course Completion Form.');
    }

    /** The submitted Final Grades sheet for the learner and the form's program. */
    public function gradeSheet(): ?FinalGrade
    {
        return FinalGrade::where('studentid', $this->student->id)->where('program_id', $this->form->program_id)
            ->where('status', 'submitted')->first();
    }

    /** @return array<int, string> */
    public function learnerEmails(): array
    {
        return EmailStep::addresses($this->student->emailaddr);
    }

    /** @return array{name: string, data: string, mime: string}|null */
    public function credentialFile(): ?array
    {
        $file = $this->run->step('credential-upload')?->data['answers']['credential'] ?? null;
        if (! is_array($file) || ! Storage::disk('local')->exists($file['path'])) {
            return null;
        }

        return ['name' => 'credential-'.trim(preg_replace('/[^A-Za-z0-9]+/', '-', $this->student->displayName()), '-').'.pdf',
            'data' => Storage::disk('local')->get($file['path']), 'mime' => 'application/pdf'];
    }

    /** @return array<string, string> */
    public function variables(): array
    {
        $completion = $this->completion();
        $pending = '(Course Completion Form not yet submitted)';

        return [
            'learner_first_name' => $this->student->first_name ?: $this->student->displayName(),
            'learner_name' => $this->student->displayName(),
            'learner_email' => implode(', ', $this->learnerEmails()),
            'program' => $this->programName(),
            'cohort' => $this->student->cohort ?: '(none)',
            'graduation_date' => self::date($this->form->data['graduation_date'] ?? null),
            'director_name' => $this->form->data['director_signature'] ?? '',
            'certificate_name' => $completion?->certificate_name ?? $pending,
            'mailing_address' => $completion?->mailing_address ?? $pending,
            'address_in_us' => $completion ? ($completion->address_in_us ? 'Yes' : 'No') : $pending,
            'expected_graduation_date' => $completion ? self::date($completion->expected_graduation_date) : $pending,
            'instructor' => $completion?->instructor ?? $pending,
            'employment_status' => $completion ? $completion->employmentLabel() : $pending,
            'workflow_link' => self::staffLink('workflows.runs.show', $this->run->id),
            'today' => now()->format('F j, Y'),
        ];
    }

    public static function date(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('F j, Y') : '(not set)';
    }

    public static function staffLink(string $route, mixed $parameters = []): string
    {
        return config('workflows.staff_url').route($route, $parameters, false);
    }

    /** The student record's postal address as lines. */
    public static function address(Student $student): ?string
    {
        $cityLine = trim(implode(', ', array_filter([$student->city, trim(($student->state ?? '').' '.($student->zipcode ?? ''))])));
        $lines = array_filter(array_map(fn ($value) => trim((string) $value), [$student->streetaddress, $student->address_line_2, $cityLine, $student->country]));

        return $lines === [] ? null : mb_substr(implode("\n", $lines), 0, 256);
    }
}
