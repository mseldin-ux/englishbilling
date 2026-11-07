<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\CourseCompletion;
use App\Models\CourseCompletionInvitation;
use App\Models\FormData;
use App\Models\GradedItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\Settings;
use App\Workflows\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Program $program;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['workflows.portal_url' => 'https://portal.example.org']);
        $this->program = new Program;
        $this->program->forceFill(['program' => 'EL I Full', 'programdesc' => 'EL I Full', 'type' => 'cert', 'servicedays' => 100])->save();
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Ada', 'last_name' => 'Learner', 'cohort' => 'EL-2026',
            'emailaddr' => 'ada@example.org, ada.work@example.org', 'streetaddress' => '1 Main St', 'city' => 'Hartford',
            'state' => 'CT', 'zipcode' => '06101', 'country' => 'USA', 'program_id' => $this->program->id, 'balance' => '0.00'])->save();
        $this->director = User::factory()->create(['access_level' => 'records', 'email' => 'director@example.org', 'name' => 'Jane Director']);
    }

    private function complete(array $overrides = []): array
    {
        return [
            'student_id' => $this->student->id, 'program_id' => $this->program->id,
            'program_complete' => '1', 'coursework_complete' => '1', 'residency_complete' => '1',
            'residency_location' => 'Hartford, CT', 'residency_date' => '2026-06-15',
            'practicum_passed_date' => '2026-08-01', 'graduation_date' => '2026-09-10',
            'director_signature' => 'Jane Director', ...$overrides,
        ];
    }

    private function submitGraduation(?User $user = null): FormData
    {
        $this->actingAs($user ?? $this->director);
        $this->post('/forms/graduation-initiation', [...$this->complete(), 'action' => 'submit'])->assertRedirect();

        return FormData::latest('id')->firstOrFail();
    }

    private function lockForm(FormData $form): void
    {
        $token = $this->get('/forms/'.$form->id)->assertOk()->viewData('editLock')['token'];
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->withCredentials();
        $this->withHeader('X-Student-Lock', $token);
    }

    private function step(string $key): WorkflowStep
    {
        return WorkflowStep::where('step', $key)->firstOrFail();
    }

    private function token(): string
    {
        return CourseCompletionInvitation::firstOrFail()->token();
    }

    private function completion(array $overrides = []): array
    {
        return ['replaces_previous' => '0', 'cohort' => 'EL-2026', 'instructor' => 'Maria M', 'certificate_name' => 'Ada B. Learner',
            'mailing_address' => "1 Main St\nHartford, CT 06101", 'address_in_us' => '1', 'survey_completed' => '1',
            'expected_graduation_date' => '2026-09-10', 'employment_status' => 'montessori', 'additional_info' => '', ...$overrides];
    }

    private function sheet(): void
    {
        $item = GradedItem::create(['program_id' => $this->program->id, 'itemorder' => 1, 'gradeitem' => 'Philosophy', 'clockhours' => 30]);
        $this->actingAs($this->director);
        $this->post('/forms/final-grades', ['studentid' => $this->student->id, 'program_id' => $this->program->id,
            'grades' => [$item->id => 'A'], 'gradenotes' => '', 'gradername' => 'Jane Director', 'gradedate' => '2026-09-10',
            'action' => 'submit'])->assertRedirect();
    }

    private function answer(string $key, array $answers, ?string $note = null): TestResponse
    {
        return $this->post('/workflows/steps/'.$this->step($key)->id.'/complete', ['answers' => $answers, 'note' => $note]);
    }

    private function yes(string ...$fields): array
    {
        return array_fill_keys($fields, 'yes');
    }

    private function workflowRun(): WorkflowRun
    {
        return WorkflowRun::where('workflow', 'course-completion')->firstOrFail();
    }

    private function configure(): void
    {
        Settings::put('graduate_info_recipients', 'registrar@example.org, records@example.org', null);
        Settings::put('alumni_recipients', 'alumni@example.org, marketing@example.org', null);
        Settings::put('employer_survey_url', 'https://survey.example.org/employer', null);
        $this->program->forceFill(['director_team_email' => 'el-directors@example.org'])->save();
    }

    private function pdf(string $name = 'credential.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** Submits the Graduation Initiation form, the learner's international CC form and the Final Grades. */
    private function throughFinalGrades(): void
    {
        $this->configure();
        $this->actingAs($this->director);
        $this->post('/forms/graduation-initiation', [...$this->complete(['instructor_email' => 'maria@example.org']), 'action' => 'submit'])->assertRedirect();
        $this->post('/course-completion/'.$this->token(), $this->completion(['address_in_us' => '0', 'mailing_address' => '5 Rue X, Paris, France']))->assertRedirect();
        $this->sheet();
    }

    public function test_submitting_starts_the_workflow_and_waits_for_the_learner(): void
    {
        $form = $this->submitGraduation();

        $run = $this->workflowRun();
        $this->assertSame(['active', 'await-course-completion', 25], [$run->status, $run->current_step, $run->steps()->count()]);
        $this->assertSame(['done', 'done', 'waiting'], [$this->step('invite-learner')->status, $this->step('remind-director')->status,
            $this->step('await-course-completion')->status]);
        $this->assertSame(['ada@example.org', 'ada.work@example.org'], $this->step('invite-learner')->recipients);
        $token = $this->token();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $this->assertDatabaseMissing('course_completion_invitations', ['token_hash' => $token]);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.org') && $mail->hasTo('ada.work@example.org')
            && str_contains($mail->textBody, 'https://portal.example.org/course-completion/'.$token));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('director@example.org')
            && $mail->subjectLine === 'Final Grades needed for Ada Learner'
            && str_contains($mail->textBody, '/forms/final-grades/create?student='.$this->student->id.'&program='.$this->program->id));
        Mail::assertSentCount(2);

        // Edits after submission never start the workflow again.
        $this->lockForm($form);
        $this->put('/forms/'.$form->id, [...$this->complete(['residency_location' => 'Online']), 'revision' => 1, 'action' => 'draft'])->assertRedirect();
        Mail::assertSentCount(2);
        $this->assertDatabaseCount('workflow_runs', 1);

        $this->get('/forms/'.$form->id)->assertOk()->assertSee('Workflow')->assertSee('Adult Learner submits the Course Completion Form')
            ->assertSee(route('workflows.runs.show', $run), false);
        $this->get('/workflows')->assertOk()->assertSee('Course Completion');
        $this->get('/workflows/course-completion')->assertOk()->assertSee('Ada Learner');
        $this->get('/workflows/course-completion?waiting=finance')->assertOk()->assertDontSee('Ada Learner');
        $this->get('/workflows/runs/'.$run->id)->assertOk()->assertSee('Course Completion Form invitation')->assertSee('Graduation Initiation PDF');

        // Forms-level users see the summary but cannot open workflows.
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->actingAs(User::factory()->create(['access_level' => 'forms']));
        $this->get('/forms/'.$form->id)->assertOk()->assertSee('Adult Learner submits the Course Completion Form')
            ->assertDontSee(route('workflows.runs.show', $run), false);
        $this->get('/workflows/runs/'.$run->id)->assertForbidden();

    }

    public function test_drafts_do_not_start_the_workflow_until_submitted(): void
    {
        $this->actingAs($this->director);
        $this->post('/forms/graduation-initiation', ['student_id' => $this->student->id, 'action' => 'draft'])->assertRedirect();
        $form = FormData::firstOrFail();
        $this->assertDatabaseCount('workflow_runs', 0);
        Mail::assertNothingSent();

        $this->lockForm($form);
        $this->put('/forms/'.$form->id, [...$this->complete(), 'revision' => 1, 'action' => 'submit'])->assertRedirect();
        $this->assertDatabaseCount('workflow_steps', 25);
        Mail::assertSentCount(2);
    }

    public function test_full_graduation_workflow(): void
    {
        $this->throughFinalGrades();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('financeteam@example.org') === false
            && $mail->hasTo('financeteam@cgms.edu') && str_contains($mail->textBody, '5 Rue X, Paris, France')
            && str_contains($mail->subjectLine, 'International'));
        $this->assertSame('done', $this->step('await-final-grades')->status);
        $this->assertSame('finance-clearance', $this->workflowRun()->current_step);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('financeteam@cgms.edu') && str_contains($mail->subjectLine, 'clearance'));

        // Records users cannot clear finances; billing users can.
        $this->get('/workflows/tasks')->assertOk()->assertDontSee('Ada Learner');
        $this->answer('finance-clearance', ['cleared' => 'yes'])->assertForbidden();
        $finance = User::factory()->create(['access_level' => 'billing_no_reports']);
        $this->actingAs($finance)->get('/workflows/tasks')->assertOk()->assertSee('Finance')->assertSee('Ada Learner');
        $this->answer('finance-clearance', [])->assertSessionHasErrors('answers.cleared');
        $this->answer('finance-clearance', ['cleared' => 'yes'])->assertRedirect('/workflows/runs/'.$this->workflowRun()->id);
        $this->assertSame($finance->id, $this->step('finance-clearance')->completed_by);

        // A "No" answer pauses the run and needs a note; anyone authorized can resume.
        $this->actingAs($this->director);
        $this->get('/workflows/tasks')->assertSee('Records staff')->assertSee('Ada Learner');
        $answers = $this->yes('record_reviewed', 'documents_merged', 'letter_finished', 'transcript_finished');
        $this->answer('records-review', [...$answers, 'letter_finished' => 'no'])->assertSessionHasErrors('note');
        $this->answer('records-review', [...$answers, 'letter_finished' => 'no'], 'Letter is still with the director.')->assertRedirect();
        $run = $this->workflowRun();
        $this->assertSame('paused', $run->status);
        $this->assertStringContainsString('Letter is still with the director.', $run->pause_note);
        $this->assertSame('waiting', $this->step('records-review')->status);
        $this->get('/workflows/tasks')->assertSee('Needs attention')->assertSee('Letter is still with the director.');
        $this->answer('records-review', $answers)->assertSessionHasErrors('stage');
        $this->post('/workflows/runs/'.$run->id.'/resume', ['note' => 'Letter done'])->assertRedirect();
        $this->assertSame('active', $this->workflowRun()->status);
        $this->answer('records-review', $answers)->assertRedirect();

        // Ordering the credential sends the verified information, the transcript and letter, and the employer stage waits for an address.
        $this->program->forceFill(['employer_survey_url' => 'https://survey.example.org/el-employer'])->save();
        $this->answer('credential-ready', ['ready' => 'yes'])->assertRedirect();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('registrar@example.org') && $mail->hasTo('records@example.org')
            && str_contains($mail->textBody, 'Ada B. Learner'));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.org') && count($mail->files) === 2
            && collect($mail->files)->every(fn ($file) => $file['mime'] === 'application/pdf' && str_starts_with($file['data'], '%PDF')));
        $this->assertSame(['employer-survey', 'waiting'], [$this->workflowRun()->current_step, $this->step('employer-survey')->status]);
        $this->answer('employer-survey', ['employer_email' => 'boss@school.example.org', 'skip' => '1'])->assertSessionHasErrors('answers.skip');
        $this->answer('employer-survey', ['employer_email' => 'boss@school.example.org'])->assertRedirect();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('boss@school.example.org')
            && str_contains($mail->textBody, 'https://survey.example.org/el-employer'));
        $this->assertNull($this->student->fresh()->sponsor_email);

        // The learner is marked graduated, then admin and alumni are told.
        $student = $this->student->fresh();
        $this->assertSame([1, now()->toDateString()], [(int) $student->graduated, substr((string) $student->actualdategrad, 0, 10)]);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('adminteam@cgms.edu') && str_contains($mail->subjectLine, 'status'));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('alumni@example.org') && $mail->hasTo('marketing@example.org')
            && str_contains($mail->textBody, '5 Rue X, Paris, France') && str_contains($mail->textBody, 'ada@example.org'));

        $this->answer('credential-check', $this->yes('accurate', 'scanned'))->assertRedirect();
        $this->answer('credential-upload', ['credential' => UploadedFile::fake()->create('x.pdf', 10, 'text/plain')])->assertSessionHasErrors('answers.credential');
        $this->answer('credential-upload', ['credential' => $this->pdf()])->assertRedirect();
        $upload = $this->step('credential-upload');
        $this->get('/workflows/steps/'.$upload->id.'/files/credential')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/workflows/steps/'.$upload->id.'/replace-file', ['field' => 'credential', 'file' => $this->pdf('better.pdf')])->assertRedirect();
        $this->assertSame('better.pdf', $upload->fresh()->data['answers']['credential']['name']);

        // The packet checklist must be fully ticked, then the final email goes out with the scan and CCs.
        $this->answer('mailing-packet', ['original' => '1'])->assertSessionHasErrors('answers.mailer');
        $this->answer('mailing-packet', array_fill_keys(['original', 'montessori_letter', 'director_letter', 'transcript', 'labels', 'mailer'], '1'))->assertRedirect();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.org') && $mail->hasCc('el-directors@example.org')
            && $mail->hasCc('maria@example.org') && collect($mail->files)->pluck('name')->contains(fn ($name) => str_contains($name, 'redential'))
            && str_contains($mail->textBody, '5 Rue X, Paris, France'));
        $this->assertSame('scheduled', $this->step('correction-wait')->status);

        // Five calendar days later admin is told the wait is over.
        $this->travel(4)->days();
        Workflows::runPending();
        $this->assertSame('scheduled', $this->step('correction-wait')->status);
        $this->travel(1)->days();
        $this->travel(1)->minutes();
        Workflows::runPending();
        $this->assertSame(['done', 'done', 'no-corrections'], [$this->step('correction-wait')->status, $this->step('notify-wait-over')->status, $this->workflowRun()->current_step]);

        $this->answer('no-corrections', $this->yes('none_received'))->assertRedirect();
        $this->answer('packet-mailed', ['mailed' => '1'])->assertSessionHasErrors('answers.mailed_on');
        $this->answer('packet-mailed', ['mailed' => '1', 'mailed_on' => '2026-09-20', 'tracking' => '1Z999'])->assertRedirect();
        $this->answer('final-checks', $this->yes('file_complete', 'status_updated'))->assertRedirect();

        $run = $this->workflowRun();
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame([], $run->steps()->whereNotIn('status', ['done', 'skipped'])->pluck('step')->all());
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('adminteam@cgms.edu') && str_contains($mail->subjectLine, 'complete'));
        $this->get('/workflows/runs/'.$run->id)->assertOk()->assertSee('Complete')->assertSee('1Z999')->assertSee('better.pdf')->assertSee('boss@school.example.org');
        $this->get('/workflows/course-completion?status=completed')->assertOk()->assertSee('Ada Learner');

        // Completing the graduation workflow starts the one-year Employment Follow-up for the same form.
        $followUp = WorkflowRun::where('workflow', 'employment-follow-up')->firstOrFail();
        $wait = $followUp->steps()->where('step', 'wait-one-year')->firstOrFail();
        $this->assertSame(['active', 'wait-one-year', 'scheduled', $run->subject_id],
            [$followUp->status, $followUp->current_step, $wait->status, $followUp->subject_id]);
        $this->assertSame($run->completed_at->addYear()->toDateString(), $wait->available_at->toDateString());
        $this->get('/workflows/runs/'.$run->id)->assertSee('Employment Follow-up workflow (active)');
        $this->get('/workflows/runs/'.$followUp->id)->assertOk()->assertSee('One year after graduation')->assertSee('Course Completion workflow (completed)');
    }

    public function test_employment_follow_up_emails_the_program_form_one_year_later(): void
    {
        $this->actingAs($this->director);
        $this->post('/forms/graduation-initiation', [...$this->complete(), 'action' => 'submit'])->assertRedirect();
        $form = FormData::firstOrFail();
        Workflows::trigger(Workflows::completedTrigger('course-completion'), $form->id);
        Workflows::trigger(Workflows::completedTrigger('course-completion'), $form->id);
        $this->assertSame(1, WorkflowRun::where('workflow', 'employment-follow-up')->count());
        $run = WorkflowRun::where('workflow', 'employment-follow-up')->firstOrFail();
        Workflows::advance($run->id);
        $step = fn (string $key) => $run->steps()->where('step', $key)->firstOrFail();

        $this->travel(1)->years();
        $this->travel(-1)->minutes();
        Workflows::runPending();
        $this->assertSame('scheduled', $step('wait-one-year')->status);
        Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail) => str_contains($mail->textBody, 'Employment Question Form'));

        // No program or default link yet: the stage fails until an admin enters one.
        $this->travel(2)->minutes();
        Workflows::runPending();
        $this->assertSame(['done', 'failed'], [$step('wait-one-year')->status, $step('send-employment-question-form')->status]);
        $this->assertStringContainsString('Employment Question Form link', $step('send-employment-question-form')->error);

        $admin = User::factory()->create(['is_admin' => true]);
        $settings = [];
        foreach (Settings::DEFINITIONS as $key => $definition) {
            $settings[$key] = Settings::get($key);
        }
        $this->actingAs($admin)->put('/admin/workflow-settings', ['settings' => [...$settings, 'employment_form_url' => 'https://forms.example.org/default'],
            'programs' => [$this->program->id => ['director_team_email' => '', 'course_completion_survey_url' => '', 'employer_survey_url' => '',
                'employment_form_url' => 'https://forms.gle/vuKxTpzX2ELpHygh7']]])->assertRedirect('/admin/workflow-settings');
        $this->assertSame('https://forms.gle/vuKxTpzX2ELpHygh7', $this->program->fresh()->employment_form_url);
        $this->assertSame(['done', 'completed'], [$step('send-employment-question-form')->status, $run->fresh()->status]);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.org')
            && str_contains($mail->textBody, 'https://forms.gle/vuKxTpzX2ELpHygh7') && ! str_contains($mail->textBody, 'forms.example.org'));
        // A completed follow-up starts nothing further.
        $this->assertSame(2, WorkflowRun::count());
    }

    public function test_employment_follow_up_uses_the_default_link_when_the_program_has_none(): void
    {
        Settings::put('employment_form_url', 'https://forms.example.org/default', null);
        $this->actingAs($this->director);
        $this->post('/forms/graduation-initiation', [...$this->complete(), 'action' => 'submit'])->assertRedirect();
        Workflows::trigger(Workflows::completedTrigger('course-completion'), FormData::firstOrFail()->id);
        Workflows::runPending();
        $this->travel(1)->years();
        $this->travel(1)->minutes();
        Workflows::runPending();
        $this->assertSame('completed', WorkflowRun::where('workflow', 'employment-follow-up')->value('status'));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.org') && str_contains($mail->textBody, 'https://forms.example.org/default'));
    }

    public function test_admin_can_send_back_skip_and_cancel(): void
    {
        $this->throughFinalGrades();
        $this->actingAs(User::factory()->create(['access_level' => 'staff']));
        $this->answer('finance-clearance', ['cleared' => 'yes'])->assertRedirect();
        $this->actingAs($this->director);
        $this->answer('records-review', $this->yes('record_reviewed', 'documents_merged', 'letter_finished', 'transcript_finished'))->assertRedirect();
        $run = $this->workflowRun();

        // Only admins may skip, send back or cancel.
        $this->post('/workflows/steps/'.$this->step('credential-ready')->id.'/skip', ['note' => 'x', 'confirm' => '1'])->assertForbidden();
        $this->post('/workflows/runs/'.$run->id.'/send-back', ['step' => 'finance-clearance', 'note' => 'x', 'confirm' => '1'])->assertForbidden();
        $this->post('/workflows/runs/'.$run->id.'/cancel', ['note' => 'x', 'confirm' => '1'])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $sent = Mail::sent(TemplatedMail::class)->count();
        $this->post('/workflows/runs/'.$run->id.'/send-back', ['step' => 'request-finance-clearance', 'note' => 'Balance changed', 'confirm' => '1'])->assertRedirect();
        $this->assertSame('finance-clearance', $this->workflowRun()->current_step);
        $this->assertSame(['upcoming', 'upcoming'], [$this->step('records-review')->status, $this->step('credential-ready')->status]);
        $this->assertSame($sent + 1, Mail::sent(TemplatedMail::class)->count());
        $this->answer('finance-clearance', ['cleared' => 'yes'])->assertRedirect();

        $this->post('/workflows/steps/'.$this->step('records-review')->id.'/skip', ['note' => 'Reviewed on paper', 'confirm' => '1'])->assertRedirect();
        $this->assertSame(['skipped', 'credential-ready'], [$this->step('records-review')->status, $this->workflowRun()->current_step]);
        $this->get('/workflows/runs/'.$run->id)->assertOk()->assertSee('Reviewed on paper');

        $this->post('/workflows/runs/'.$run->id.'/cancel', ['note' => 'Withdrew', 'confirm' => '1'])->assertRedirect();
        $this->assertSame('cancelled', $this->workflowRun()->status);
        $this->assertSame('cancelled', $this->step('credential-ready')->status);
        $this->assertNotNull(CourseCompletionInvitation::firstOrFail()->revoked_at);
        $this->answer('credential-ready', ['ready' => 'yes'])->assertSessionHasErrors();
    }

    public function test_missing_settings_and_addresses_fail_until_fixed(): void
    {
        $this->student->forceFill(['emailaddr' => 'not an email'])->save();
        $this->director->forceFill(['email' => null])->save();
        $this->submitGraduation();

        // The invitation is required, so the run stops; the optional reminder fails without blocking.
        $invite = $this->step('invite-learner');
        $this->assertSame(['failed', 'failed'], [$invite->status, $this->step('remind-director')->status]);
        $this->assertNull($invite->available_at);
        $this->assertSame('invite-learner', $this->workflowRun()->current_step);
        Mail::assertNothingSent();
        Workflows::runPending();
        $this->assertSame('failed', $invite->fresh()->status);
        $this->get('/workflows/tasks')->assertSee('Needs attention');

        $this->student->forceFill(['emailaddr' => 'ada@example.org'])->save();
        $this->post('/workflows/steps/'.$invite->id.'/retry', [])->assertSessionHasErrors('confirm');
        $this->post('/workflows/steps/'.$invite->id.'/retry', ['confirm' => '1'])->assertSessionHas('status', 'Email sent.');
        $this->assertSame('done', $invite->fresh()->status);
        $this->assertSame('await-course-completion', $this->workflowRun()->current_step);

        // Resending a sent invitation reuses the same link.
        $token = $this->token();
        $this->post('/workflows/steps/'.$invite->id.'/retry', ['confirm' => '1'])->assertSessionHas('status', 'Email sent.');
        $this->assertSame($token, $this->token());
        $this->assertDatabaseCount('course_completion_invitations', 1);

        // An unset recipient list stops the run until an admin saves the settings.
        $this->post('/course-completion/'.$token, $this->completion())->assertRedirect();
        $this->assertSame('skipped', $this->step('notify-finance-international')->status);
        $this->sheet();
        $this->actingAs(User::factory()->create(['access_level' => 'staff']));
        $this->answer('finance-clearance', ['cleared' => 'yes'])->assertRedirect();
        $this->actingAs($this->director);
        $this->answer('records-review', $this->yes('record_reviewed', 'documents_merged', 'letter_finished', 'transcript_finished'))->assertRedirect();
        $this->answer('credential-ready', ['ready' => 'yes'])->assertRedirect();
        $this->assertSame('failed', $this->step('send-verified-info')->status);
        $this->assertStringContainsString('Workflow Settings', $this->step('send-verified-info')->error);

        $this->get('/admin/workflow-settings')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/workflow-settings')->assertOk()->assertSee('Verified graduate information recipients')->assertSee('EL I Full');
        $settings = ['finance_email' => 'financeteam@cgms.edu', 'admin_team_email' => 'adminteam@cgms.edu', 'graduate_info_recipients' => 'bad address',
            'alumni_recipients' => '', 'employer_survey_url' => '', 'course_completion_survey_url' => 'https://www.surveymonkey.com/r/KSY8TKJ', 'correction_wait_days' => 5];
        $this->put('/admin/workflow-settings', ['settings' => $settings])->assertSessionHasErrors('settings.graduate_info_recipients');
        $this->put('/admin/workflow-settings', ['settings' => $settings,
            'programs' => [$this->program->id => ['director_team_email' => '', 'course_completion_survey_url' => 'not a link', 'employer_survey_url' => '']],
        ])->assertSessionHasErrors(["programs.{$this->program->id}.course_completion_survey_url"]);
        $this->put('/admin/workflow-settings', ['settings' => [...$settings, 'graduate_info_recipients' => 'registrar+grads@example.org,dean@example.org',
            'admin_team_email' => 'mseldin+macte@cgms.edu,mseldin+imc@cgms.edu'],
            'programs' => [$this->program->id => ['director_team_email' => 'team+el@example.org', 'course_completion_survey_url' => ' https://survey.example.org/el ', 'employer_survey_url' => '']],
            'stages' => ['course-completion' => ['credential-ready' => ['title' => 'Order the credential?', 'instructions' => '', 'labels' => ['ready' => 'Order it now?']]]],
        ])->assertRedirect('/admin/workflow-settings');
        $this->assertSame('registrar+grads@example.org, dean@example.org', Settings::get('graduate_info_recipients'));
        $this->assertSame('mseldin+macte@cgms.edu, mseldin+imc@cgms.edu', Settings::get('admin_team_email'));
        $this->assertSame(['team+el@example.org', 'https://survey.example.org/el', null],
            [$this->program->fresh()->director_team_email, $this->program->fresh()->course_completion_survey_url, $this->program->fresh()->employer_survey_url]);
        $this->assertSame('done', $this->step('send-verified-info')->status);
        $this->get('/workflows/runs/'.$this->workflowRun()->id)->assertSee('Order the credential?')->assertSee('Order it now?');
        // The alumni list is still empty, so the run stops there after the graduate is emailed.
        $this->assertSame('employer-survey', $this->workflowRun()->current_step);
        $this->answer('employer-survey', ['skip' => '1'])->assertRedirect();
        $this->assertSame('skipped', $this->step('employer-survey')->status);
        $this->assertSame(['done', 'notify-alumni'], [$this->step('mark-graduated')->status, $this->workflowRun()->current_step]);
        $this->assertSame('failed', $this->step('notify-alumni')->status);
    }

    public function test_marking_graduated_waits_for_an_editing_lock(): void
    {
        $this->throughFinalGrades();
        $this->actingAs(User::factory()->create(['access_level' => 'staff']));
        $this->answer('finance-clearance', ['cleared' => 'yes'])->assertRedirect();
        $this->actingAs($this->director);
        $this->answer('records-review', $this->yes('record_reviewed', 'documents_merged', 'letter_finished', 'transcript_finished'))->assertRedirect();
        $this->student->forceFill(['sponsor_email' => 'hr@school.example.org'])->save();
        $other = User::factory()->create(['access_level' => 'staff']);
        DB::table('staff_sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $other->id, 'last_activity' => now()->timestamp]);
        DB::table('student_edit_locks')->insert(['student_id' => $this->student->id, 'session_id' => str_repeat('s', 40),
            'token' => str_repeat('t', 64), 'acquired_at' => now()->timestamp, 'last_activity' => now()->timestamp]);

        $this->answer('credential-ready', ['ready' => 'yes'])->assertRedirect();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('hr@school.example.org'));
        $step = $this->step('mark-graduated');
        $this->assertSame('scheduled', $step->status);
        $this->assertSame(0, (int) $this->student->fresh()->graduated);

        DB::table('student_edit_locks')->delete();
        $this->travel(6)->minutes();
        Workflows::runPending();
        $this->assertSame('done', $step->fresh()->status);
        $this->assertSame(1, (int) $this->student->fresh()->graduated);
    }

    public function test_a_later_international_submission_notifies_finance_again(): void
    {
        $this->submitGraduation();
        $token = $this->token();
        $this->post('/course-completion/'.$token, $this->completion())->assertRedirect();
        $this->assertSame('skipped', $this->step('notify-finance-international')->status);
        Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('financeteam@cgms.edu') && str_contains($mail->subjectLine, 'International'));

        $this->post('/course-completion/'.$token, $this->completion(['replaces_previous' => '1', 'address_in_us' => '0', 'mailing_address' => '9 High St, London']))->assertRedirect();
        $this->assertSame('done', $this->step('notify-finance-international')->status);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('financeteam@cgms.edu') && str_contains($mail->textBody, '9 High St, London'));
    }

    public function test_deleting_the_graduation_form_cancels_the_workflow_and_withdraws_the_link(): void
    {
        $form = $this->submitGraduation();
        $token = $this->token();
        $this->get('/course-completion/'.$token)->assertOk();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->lockForm($form);
        $this->delete('/forms/'.$form->id, ['confirm' => '1'])->assertRedirect('/forms');

        $this->assertSame('cancelled', $this->workflowRun()->status);
        $this->assertSame('cancelled', $this->step('await-course-completion')->status);
        $this->assertSame('done', $this->step('invite-learner')->status);
        $this->assertNotNull(CourseCompletionInvitation::firstOrFail()->revoked_at);
        $this->flushHeaders();
        auth()->logout();
        $this->get('/course-completion/'.$token)->assertStatus(410)->assertSee('no longer active');
    }

    public function test_portal_needs_no_login_prefills_and_requires_the_survey(): void
    {
        $this->submitGraduation();
        $token = $this->token();
        auth()->logout();

        $response = $this->get('/course-completion/'.$token)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('https://www.surveymonkey.com/r/KSY8TKJ')->assertDontSee('Logout');
        $this->program->forceFill(['course_completion_survey_url' => 'https://survey.example.org/el-completion'])->save();
        $this->get('/course-completion/'.$token)->assertSee('https://survey.example.org/el-completion')->assertDontSee('KSY8TKJ');
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.org') && str_contains($mail->textBody, 'KSY8TKJ'));
        $this->assertSame('Ada Learner', $response->viewData('values')['certificate_name']);
        $this->assertSame("1 Main St\nHartford, CT 06101\nUSA", $response->viewData('values')['mailing_address']);
        $this->assertSame('2026-09-10', $response->viewData('values')['expected_graduation_date']);
        $this->assertNotNull(CourseCompletionInvitation::firstOrFail()->last_opened_at);

        $this->post('/course-completion/'.$token, $this->completion(['survey_completed' => '0']))->assertSessionHasErrors('survey_completed');
        $this->post('/course-completion/'.$token, $this->completion(['employment_status' => '']))->assertSessionHasErrors('employment_status');
        $this->post('/course-completion/'.$token, $this->completion(['employment_status' => 'retired']))->assertSessionHasErrors('employment_status');
        $this->post('/course-completion/'.$token, $this->completion(['certificate_name' => ' ', 'mailing_address' => str_repeat('x', 257)]))
            ->assertSessionHasErrors(['certificate_name', 'mailing_address']);
        $this->assertDatabaseCount('course_completions', 0);

        $this->post('/course-completion/'.$token, $this->completion())->assertRedirect('/course-completion/'.$token);
        $this->get('/course-completion/'.$token)->assertSee('Thank you');
        $this->post('/course-completion/'.$token, $this->completion(['replaces_previous' => '1', 'address_in_us' => '0',
            'mailing_address' => '5 Rue X, Paris, France', 'employment_status' => 'not_employed']))->assertRedirect();
        $this->assertDatabaseCount('course_completions', 2);
        $latest = CourseCompletion::latest('id')->firstOrFail();
        $this->assertTrue($latest->replaces_previous);
        $this->assertFalse($latest->address_in_us);
        $this->assertSame(['not_employed', 'Not employed'], [$latest->employment_status, $latest->employmentLabel()]);
        $this->assertNull($latest->additional_info);
        $this->assertSame('5 Rue X, Paris, France', $this->get('/course-completion/'.$token)->viewData('values')['mailing_address']);
        $page = $this->get('/course-completion/'.$token)->assertSee('Employed in the Montessori Field')->getContent();
        $this->assertMatchesRegularExpression('/value="not_employed"\s+checked>/', $page);

        $this->get('/course-completion/'.str_repeat('a', 64))->assertNotFound();
        $this->get('/course-completion/short')->assertNotFound();
        $this->travel(181)->days();
        $this->get('/course-completion/'.$token)->assertStatus(410);
        $this->post('/course-completion/'.$token, $this->completion())->assertStatus(410);
        $this->assertDatabaseCount('course_completions', 2);
    }

    public function test_staff_see_submissions_in_forms_list_detail_and_pdf(): void
    {
        $this->submitGraduation();
        $token = $this->token();
        $this->post('/course-completion/'.$token, $this->completion(['address_in_us' => '0']))->assertRedirect();
        $completion = CourseCompletion::firstOrFail();

        $this->actingAs(User::factory()->create(['access_level' => 'forms']));
        $this->get('/forms')->assertOk()->assertSee('Course Completion Form')->assertSee('Ada B. Learner')
            ->assertSee(route('forms.completion.show', $completion), false);
        $this->get('/forms?type=course-completion')->assertOk()->assertSee('Ada B. Learner');
        $this->get('/forms?type=course-completion&status=draft')->assertOk()->assertDontSee('Ada B. Learner');
        $this->get('/forms?q=Ada+B.')->assertOk()->assertSee('Ada B. Learner');
        $this->get('/forms/course-completion/'.$completion->id)->assertOk()->assertSee('Maria M')->assertSee('International address')
            ->assertSee('Are you currently:')->assertSee('Employed in the Montessori Field');
        $this->get('/forms/course-completion/'.$completion->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/email-templates')->assertForbidden();
    }

    public function test_every_stage_has_a_guide_with_how_it_completes_and_template_links(): void
    {
        foreach (Workflows::all() as $key => $workflow) {
            $this->assertSame([], array_values(array_diff(array_keys(Workflows::steps($key)), array_keys($workflow->guide()))), "Stages of {$key} without a guide");
            $this->assertSame([], array_values(array_diff(array_keys($workflow->guide()), array_keys(Workflows::steps($key)))), "Guide entries of {$key} for unknown stages");
        }

        $page = $this->actingAs($this->director)->get('/workflows/course-completion')->assertOk();
        $page->assertSee('data-stage-info="stage-guide-records-review"', false)
            ->assertSee('Stage 1 of 25: Final Grades reminder', false)
            ->assertSee('Every Yes/No question must be answered Yes.')
            ->assertSee('The final transcript is finished.')
            ->assertSee(route('templates.edit', 'graduate-congratulations'), false)
            ->assertSee(route('templates.edit', 'graduate-congratulations-letter'), false)
            ->assertSee('waits 5 days after the previous stage')
            ->assertSee('There is no valid employer email (sponsor_email)', false);
        $this->get('/workflows/employment-follow-up')->assertOk()
            ->assertSee('waits 1 year after the previous stage')
            ->assertSee(route('templates.edit', 'employment-question-form'), false);
    }

    public function test_templates_are_editable_by_records_and_up_and_validated(): void
    {
        $this->actingAs(User::factory()->create(['access_level' => 'forms']));
        $this->get('/forms')->assertDontSee('Email Templates');
        $this->get('/email-templates/course-completion-invitation')->assertForbidden();

        foreach (['records', 'staff', 'billing_no_reports'] as $level) {
            $this->actingAs(User::factory()->create(['access_level' => $level]));
            $this->get('/forms')->assertSee('Email Templates');
            $this->get('/email-templates')->assertOk()->assertSee('Final Grades');
        }
        $this->get('/email-templates/nope')->assertNotFound();
        $url = '/email-templates/course-completion-invitation';
        $this->get($url)->assertOk()->assertSee('{form_link}');
        $this->put($url, ['subject' => 'Hi {learner_first_name}', 'body' => 'No link here'])->assertSessionHasErrors();
        $this->put($url, ['subject' => 'Hi {bogus}', 'body' => '{form_link}'])->assertSessionHasErrors();
        $this->put($url, ['subject' => 'Hi', 'body' => 'Preview {form_link}', 'action' => 'preview'])->assertSessionHas('preview');
        $this->assertDatabaseMissing('email_templates', ['body' => 'Preview {form_link}']);
        $this->put($url, ['subject' => 'Hello {learner_first_name}', 'body' => "Please fill this in:\r\n{form_link}"])->assertRedirect($url);

        $this->submitGraduation();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->subjectLine === 'Hello Ada'
            && str_starts_with($mail->textBody, "Please fill this in:\nhttps://portal.example.org/course-completion/"));

        $this->put($url, ['subject' => 'x', 'body' => 'x', 'action' => 'reset'])->assertRedirect($url);
        $this->assertSame('Your CGMS Course Completion Form', DB::table('email_templates')->where('key', 'course-completion-invitation')->value('subject'));

        $this->get('/email-templates')->assertSee('PDF letter');
        $this->get('/email-templates/graduate-congratulations-letter')->assertOk()->assertSee('{learner_name}');
    }
}
