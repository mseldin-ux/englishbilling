<?php

namespace Tests\Feature;

use App\Billing\AttributableIncome;
use App\Billing\EarnedIncome;
use App\Billing\EnrollmentAccounting;
use App\Billing\Enrollments;
use App\Billing\RecordVersion;
use App\Billing\StudentSearch;
use App\Forms\GraduationInitiation;
use App\Forms\ProgramEnrollment;
use App\Models\GradedItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgramCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 19));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function program(array $data): Program
    {
        $program = new Program;
        $program->forceFill($data)->save();

        return $program->fresh();
    }

    private function chain(): array
    {
        $certificate = $this->program(['program' => 'EC Full', 'programdesc' => 'Shared certificate',
            'servicedays' => 240, 'type' => 'cert', 'certleading' => true]);
        $common = ['staged' => true, 'certleading' => true, 'certificate_program_id' => $certificate->id];
        $third = $this->program([...$common, 'program' => 'EC practicum', 'servicedays' => 100, 'nextprog' => 0]);
        $second = $this->program([...$common, 'program' => 'EC residency', 'servicedays' => 40, 'nextprog' => $third->id]);
        $first = $this->program([...$common, 'program' => 'EC online', 'servicedays' => 100, 'nextprog' => $second->id]);

        return [$certificate, $first, $second, $third];
    }

    private function learner(Program $program): Student
    {
        $this->post('/students', ['first_name' => 'Catalog', 'last_name' => 'Learner',
            'program_id' => $program->id, 'start_date' => '2026-01-01',
            'pif' => true, 'pifdate' => '2026-01-01', 'graddate' => '2027-01-01'])
            ->assertSessionHasNoErrors()->assertRedirect();

        return Student::latest('id')->firstOrFail();
    }

    private function updateProgram(Program $program, array $data)
    {
        $program->refresh();

        return $this->put('/admin/programs/'.$program->id,
            [...$program->getAttributes(), 'version' => RecordVersion::of($program), ...$data]);
    }

    private function action(Student $student, array $data)
    {
        return $this->post('/students/'.$student->id.'/enrollments', [
            'version' => RecordVersion::of($student->fresh()), 'accounts_settled' => 1, ...$data,
        ]);
    }

    public function test_admin_catalog_editor_preserves_nullable_flags_and_rejects_stale_updates(): void
    {
        $this->get('/utilities')->assertOk()->assertSee('Manage programs and stage sequences')
            ->assertDontSee('Configure program stage service days');
        $this->get('/admin/programs/create')->assertOk();
        $this->post('/admin/programs', ['program' => 'PD course', 'servicedays' => 30, 'staged' => null,
            'certleading' => false, 'nextprog' => 0])->assertRedirect('/admin/programs')->assertSessionHasNoErrors();
        $pd = Program::firstOrFail();
        $this->assertNull($pd->staged);
        $this->assertFalse($pd->certleading);
        $this->assertNull($pd->nextprog);
        $version = RecordVersion::of($pd);
        $this->updateProgram($pd, ['programdesc' => 'Changed description'])->assertSessionHasNoErrors();
        $this->put('/admin/programs/'.$pd->id, ['version' => $version, 'program' => 'Stale'])
            ->assertSessionHasErrors('version');
        $this->assertSame('Changed description', $pd->fresh()->programdesc);
        $this->get('/admin/programs')->assertOk()->assertSee('PD course');
        $this->get('/admin/programs/'.$pd->id.'/edit')->assertOk()->assertSee('Shared certificate program');
        foreach (array_keys(User::LEVELS) as $level) {
            $this->actingAs(User::factory()->create(['access_level' => $level]));
            $this->get('/admin/programs')->assertForbidden();
            $this->post('/admin/programs', ['program' => 'Unauthorized'])->assertForbidden();
            $this->put('/admin/programs/'.$pd->id, ['program' => 'Unauthorized'])->assertForbidden();
        }
    }

    public function test_catalog_stages_can_overlap_and_finish_out_of_order_without_changing_agreements(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$certificate, $first, $second, $third] = $this->chain();
        $student = $this->learner($first);
        $this->obtainEditingLock($student);
        $enrollment = $student->currentEnrollment;
        $stage1 = $enrollment->stages->first();
        $this->action($student, ['action' => 'stage', 'program_id' => $second->id, 'started_on' => '2026-02-01'])
            ->assertSessionHasNoErrors();
        $stage2 = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->action($student, ['action' => 'stage', 'program_id' => $third->id, 'started_on' => '2026-02-15'])
            ->assertSessionHasNoErrors();
        $stage3 = $enrollment->stages()->where('stage', 3)->firstOrFail();
        $this->assertSame($certificate->id, $enrollment->program_id);
        $this->assertSame([100, 40, 100], $enrollment->stages()->orderBy('stage')->pluck('service_days')->all());
        $this->action($student, ['action' => 'complete', 'stage_enrollment_id' => $stage3->id, 'effective_on' => '2026-03-01'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($student->fresh()->graduated);
        $this->assertNull($stage1->fresh()->ended_on);
        $this->assertNull($stage2->fresh()->ended_on);
        $this->action($student, ['action' => 'complete', 'stage_enrollment_id' => $stage1->id, 'effective_on' => '2026-03-02'])
            ->assertSessionHasNoErrors();
        $this->action($student, ['action' => 'complete', 'stage_enrollment_id' => $stage2->id, 'effective_on' => '2026-03-03'])
            ->assertSessionHasNoErrors();
        $this->action($student, ['action' => 'graduate', 'effective_on' => '2026-03-04'])->assertSessionHasNoErrors();
        $this->assertTrue($student->fresh()->graduated);
        $this->assertSame($third->id, $student->fresh()->program_id);
    }

    public function test_catalog_rejects_cycles_merges_wrong_certificates_and_legacy_duration_changes(): void
    {
        [$certificate, $first, $second, $third] = $this->chain();
        $this->updateProgram($third, ['nextprog' => $first->id])->assertSessionHasErrors('program');
        $this->assertSame(0, $third->fresh()->nextprog);
        $this->post('/admin/programs', ['program' => 'Ambiguous predecessor', 'staged' => 1,
            'certleading' => 1, 'certificate_program_id' => $certificate->id, 'nextprog' => $second->id])
            ->assertSessionHasErrors('program');
        $this->assertDatabaseCount('programs', 4);
        $pd = $this->program(['program' => 'PD', 'certleading' => false]);
        $this->updateProgram($first, ['certificate_program_id' => $pd->id])->assertSessionHasErrors('program');
        $this->updateProgram($certificate, ['certleading' => false])->assertSessionHasErrors('program');
        DB::table('students')->insert(['program_id' => $certificate->id]);
        $this->updateProgram($certificate, ['servicedays' => 200])->assertSessionHasErrors('servicedays');
        $this->assertSame(240, $certificate->fresh()->servicedays);
    }

    public function test_selecting_a_staged_program_creates_enrollment_atomically_and_preserves_entered_fields(): void
    {
        [$certificate, $first, $second] = $this->chain();
        $student = $this->learner($first);
        $enrollment = $student->currentEnrollment;
        $stage = $enrollment->stages->sole();
        $this->assertTrue($enrollment->program_stages);
        $this->assertSame($certificate->id, $enrollment->program_id);
        $this->assertSame($first->id, $student->program_id);
        $this->assertSame($first->id, $stage->program_id);
        $this->assertSame($second->id, $stage->next_program_id);
        $this->assertSame(1, $stage->stage);
        $this->assertSame(100, $stage->service_days);
        $this->assertTrue($student->pif);
        $this->assertSame('2026-01-01', $student->pifdate->toDateString());
        $this->assertSame('2027-01-01', $student->graddate->toDateString());
        $this->assertDatabaseCount('studenttransactions', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->get('/detail/'.$student->id)->assertOk()->assertSee('EC online');
        $this->post('/students', ['program_id' => $first->id])->assertSessionHasErrors('start_date');
        $this->post('/students', ['program_id' => $first->id, 'start_date' => '2027-01-01'])
            ->assertSessionHasErrors('start_date');
        $first->servicedays = null;
        $first->save();
        $this->post('/students', ['program_id' => $first->id, 'start_date' => '2026-01-01'])
            ->assertSessionHasErrors('enrollment');
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('student_enrollments', 1);
        $this->assertDatabaseCount('student_stage_enrollments', 1);
        $whole = $this->learner($certificate);
        $this->assertNull($whole->current_enrollment_id);
    }

    public function test_staff_advance_explicitly_and_stage_catalog_edits_do_not_rewrite_agreed_history(): void
    {
        [$certificate, $first, $second, $third] = $this->chain();
        $student = $this->learner($first);
        $this->obtainEditingLock($student);
        $this->action($student, ['action' => 'stage', 'program_id' => $second->id, 'started_on' => '2026-02-01'])
            ->assertSessionHasErrors('enrollment');
        $this->patch('/students/'.$student->id, ['version' => RecordVersion::of($student->fresh()),
            'graduated' => 1, 'actualdategrad' => '2026-02-01'])->assertSessionHasErrors('enrollment');
        $this->action($student, ['action' => 'complete', 'effective_on' => '2026-04-11'])->assertSessionHasNoErrors();
        $this->assertFalse($student->fresh()->graduated);
        $this->assertDatabaseCount('student_stage_enrollments', 1);
        $this->get('/detail/'.$student->id)->assertOk()->assertSee('Enroll in next stage')->assertSee('EC residency');
        $this->obtainEditingLock($student);
        $this->updateProgram($first, ['servicedays' => 200, 'nextprog' => null])->assertSessionHasNoErrors();
        $stage = $student->fresh()->currentEnrollment->stages->sole();
        $this->assertSame(100, $stage->service_days);
        $this->assertSame($second->id, $stage->next_program_id);
        $this->action($student, ['action' => 'stage', 'program_id' => $third->id, 'started_on' => '2026-06-01'])
            ->assertSessionHasErrors('enrollment');
        $this->action($student, ['action' => 'stage', 'program_id' => $second->id,
            'accounts_settled' => 0, 'started_on' => '2026-06-01'])->assertSessionHasErrors('accounts_settled');
        $this->action($student, ['action' => 'stage', 'program_id' => $second->id, 'started_on' => '2026-06-01'])
            ->assertSessionHasNoErrors();
        $student->refresh();
        $this->assertSame($second->id, $student->program_id);
        $this->assertSame($certificate->id, $student->academicProgramId());
        $this->assertSame('2026-01-01', $student->start_date->toDateString());
        $this->action($student, ['action' => 'complete', 'effective_on' => '2026-07-11'])->assertSessionHasNoErrors();
        $this->action($student, ['action' => 'stage', 'started_on' => '2026-08-01'])->assertSessionHasNoErrors();
        $last = $student->fresh()->currentEnrollment->stages->last();
        $this->assertTrue($last->isFinal());
        $this->assertNull($last->next_program_id);
        $this->action($student, ['action' => 'complete', 'effective_on' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->assertTrue($student->fresh()->graduated);
        $this->assertSame('graduated', EnrollmentAccounting::statusAt($student->fresh()->currentEnrollment, '2026-11-01'));
        $this->assertDatabaseCount('student_enrollments', 1);
    }

    public function test_program_search_academic_forms_and_income_keep_the_same_certificate_scope_across_stages(): void
    {
        [$certificate, $first, $second] = $this->chain();
        $item = new GradedItem;
        $item->forceFill(['program_id' => $certificate->id, 'gradeitem' => 'Shared academic item'])->save();
        $student = $this->learner($first);
        $enrollment = $student->currentEnrollment;
        $stage = $enrollment->stages->sole();
        $fee = new StudentTransaction;
        $fee->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage->id,
            'transactiontype' => 'Fee', 'transactionamount' => '1000.00', 'transactiondate' => '2026-01-02', 'processeddate' => '2026-01-02'])->save();
        $this->post('/forms/final-grades', ['studentid' => $student->id, 'program_id' => $certificate->id])
            ->assertSessionHasNoErrors();
        Enrollments::complete($student->fresh(), '2026-04-11');
        Enrollments::startStage($student->fresh(), ['started_on' => '2026-06-01']);
        $stage2 = $enrollment->fresh()->stages->last();
        $fee2 = new StudentTransaction;
        $fee2->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage2->id,
            'transactiontype' => 'Fee', 'transactionamount' => '2000.00', 'transactiondate' => '2026-06-02', 'processeddate' => '2026-06-02'])->save();
        $this->assertSame('1500.00', AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-06-11'])['calculated']['totalearned']);
        foreach ([$certificate, $first, $second] as $program) {
            $this->assertSame([$student->id], StudentSearch::query(['program_id' => $program->id])->pluck('id')->all());
        }
        $this->assertSame(0, StudentSearch::query(['program_id' => $first->id, 'stage_start_from' => '2026-06-01'])->count());
        $this->assertSame(1, StudentSearch::query(['program_id' => $second->id, 'start_from' => '2026-06-01'])->count());
        $this->assertSame($enrollment->id, ProgramEnrollment::current($student->fresh(), $certificate->id));
        $this->post('/forms/final-grades', ['studentid' => $student->id, 'program_id' => $certificate->id])
            ->assertSessionHasErrors('program_id');
        $this->assertDatabaseCount('finalgrades', 1);
        $this->get('/forms/final-grades/create?student='.$student->id)->assertOk()
            ->assertViewHas('values', fn ($values) => $values['program_id'] === $certificate->id && $values['enrollment_id'] === $enrollment->id);
        $this->get('/forms/graduation-initiation/create?student='.$student->id)->assertOk()
            ->assertViewHas('values', fn ($values) => $values['program_id'] === $certificate->id && $values['enrollment_id'] === $enrollment->id);
    }

    public function test_certificate_flag_controls_forms_and_zero_next_program_completes_the_final_stage(): void
    {
        $certificate = $this->program(['program' => 'New certificate', 'certleading' => true]);
        $excluded = $this->program(['program' => 'Not a certificate', 'type' => 'cert', 'certleading' => false]);
        $stage = $this->program(['program' => 'Only stage', 'staged' => true, 'certleading' => true,
            'servicedays' => 10, 'nextprog' => 0, 'certificate_program_id' => $certificate->id]);
        $eligible = GraduationInitiation::programs()->modelKeys();
        $this->assertContains($certificate->id, $eligible);
        $this->assertNotContains($excluded->id, $eligible);
        $this->assertNotContains($stage->id, $eligible);
        $student = $this->learner($stage);
        $this->assertTrue($student->currentEnrollment->stages->sole()->isFinal());
        Enrollments::complete($student, '2026-01-11');
        $this->assertTrue($student->fresh()->graduated);
    }

    public function test_selecting_a_stage_on_an_unbilled_record_initializes_it_but_cannot_reassign_existing_finances(): void
    {
        [$certificate, $first] = $this->chain();
        $student = new Student;
        $student->forceFill(['first_name' => 'Unbilled', 'graddate' => '2027-01-01'])->save();
        $this->obtainEditingLock($student);
        $this->patchJson('/students/'.$student->id, ['version' => RecordVersion::of($student->fresh()),
            'program_id' => $first->id, 'start_date' => '2026-01-01'])->assertOk()
            ->assertJsonPath('message', 'All changes saved.');
        $this->assertTrue($student->fresh()->currentEnrollment->program_stages);
        $legacy = new Student;
        $legacy->forceFill(['program_id' => $certificate->id, 'start_date' => '2025-01-01', 'graddate' => '2027-01-01'])->save();
        $fee = new StudentTransaction;
        $fee->forceFill(['student_id' => $legacy->id, 'transactiontype' => 'Fee', 'transactionamount' => '1000.00',
            'transactiondate' => '2025-01-02', 'processeddate' => '2025-01-02'])->save();
        $this->obtainEditingLock($legacy);
        $this->patchJson('/students/'.$legacy->id, ['version' => RecordVersion::of($legacy->fresh()),
            'program_id' => $first->id, 'start_date' => '2026-01-01'])->assertUnprocessable()
            ->assertJsonValidationErrors('enrollment');
        $this->assertSame($certificate->id, $legacy->fresh()->program_id);
        $this->assertSame('2025-01-01', $legacy->fresh()->start_date->toDateString());
        $this->assertNull($fee->fresh()->enrollment_id);
    }

    public function test_exceptional_out_of_sequence_enrollment_requires_a_reason_and_preserves_the_shared_record(): void
    {
        [$certificate, $first, $second, $third] = $this->chain();
        $student = $this->learner($first);
        $this->obtainEditingLock($student);
        $this->action($student, ['action' => 'complete', 'effective_on' => '2026-04-11'])->assertSessionHasNoErrors();
        $data = ['action' => 'stage', 'program_id' => $second->id, 'started_on' => '2026-06-01', 'override_program_id' => $third->id];
        $this->action($student, $data)->assertSessionHasErrors('override_reason');
        $this->action($student, [...$data, 'override_reason' => 'Approved prior residency credit'])->assertSessionHasNoErrors();
        $enrollment = $student->fresh()->currentEnrollment;
        $this->assertSame($certificate->id, $enrollment->program_id);
        $this->assertCount(2, $enrollment->stages);
        $this->assertSame(3, $enrollment->stages->last()->stage);
        $this->assertTrue($enrollment->stages->last()->isFinal());
        $this->assertSame('Approved prior residency credit', $enrollment->events->last()->details['stage_program_override']['reason']);
    }

    public function test_shared_certificate_fields_are_live_links_and_submitted_overrides_are_ignored(): void
    {
        [$certificate] = $this->chain();
        $shared = ['type' => 'cert', 'shortname' => 'EC', 'director_team_email' => 'team@example.test',
            'course_completion_survey_url' => 'https://example.test/course',
            'employer_survey_url' => 'https://example.test/employer', 'employment_form_url' => 'https://example.test/employment'];
        $certificate->forceFill($shared)->save();
        $this->post('/admin/programs', ['program' => 'Linked stage', 'staged' => 1, 'certleading' => 1,
            'certificate_program_id' => $certificate->id, 'servicedays' => 10,
            ...array_fill_keys(Program::SHARED_FIELDS, str_repeat('invalid override ', 40))])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/programs');
        $stage = Program::latest('id')->firstOrFail();
        foreach ($shared as $field => $value) {
            $this->assertSame($value, $stage->$field);
            $this->assertSame($value, $stage->toArray()[$field]);
            $this->assertNull($stage->getRawOriginal($field));
        }
        $changed = ['type' => 'newtype', 'shortname' => null, 'director_team_email' => null,
            'course_completion_survey_url' => 'https://example.test/new-course',
            'employer_survey_url' => null, 'employment_form_url' => 'https://example.test/new-employment'];
        $certificate->forceFill($changed)->save();
        $stage->refresh();
        foreach ($changed as $field => $value) {
            $this->assertSame($value, $stage->$field);
        }
        $this->updateProgram($stage, ['shortname' => 'Ignored'])->assertSessionHasNoErrors();
        $this->assertNull($stage->fresh()->shortname);
        $this->assertNull($certificate->fresh()->shortname);
        $this->get('/admin/programs/'.$stage->id.'/edit')->assertOk()->assertSee('readonly', false)
            ->assertSee('https://example.test/new-course');
    }

    public function test_linking_and_unlinking_preserves_local_values_without_using_them_while_linked(): void
    {
        $certificate = $this->program(['program' => 'Certificate', 'certleading' => true, 'shortname' => 'Shared']);
        $stage = $this->program(['program' => 'Local', 'shortname' => 'Local value']);
        $this->updateProgram($stage, ['staged' => 1, 'certleading' => 1,
            'certificate_program_id' => $certificate->id, 'shortname' => 'Tampered'])->assertSessionHasNoErrors();
        $this->assertSame('Shared', $stage->fresh()->shortname);
        $this->assertSame('Local value', $stage->fresh()->getRawOriginal('shortname'));
        $this->updateProgram($stage, ['staged' => 0, 'certificate_program_id' => null, 'shortname' => 'Editable again'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Editable again', $stage->fresh()->shortname);
        $this->assertSame('Shared', $certificate->fresh()->shortname);
    }

    public function test_workflow_settings_cannot_override_linked_program_fields(): void
    {
        [$certificate, $stage] = $this->chain();
        $this->get('/admin/workflow-settings')->assertOk()
            ->assertDontSee('name="programs['.$stage->id.'][director_team_email]"', false);
        $this->put('/admin/workflow-settings', ['settings' => ['correction_wait_days' => 5],
            'programs' => [$stage->id => ['director_team_email' => 'override@example.test']]])
            ->assertSessionHasErrors('programs.'.$stage->id);
        $this->assertNull($certificate->fresh()->director_team_email);
        $this->assertNull($stage->fresh()->getRawOriginal('director_team_email'));
    }

    public function test_catalog_enrollment_preserves_prior_financial_report_totals(): void
    {
        [$certificate, $first] = $this->chain();
        $student = new Student;
        $student->forceFill(['program_id' => $certificate->id, 'start_date' => '2025-01-01',
            'graddate' => '2027-01-01', 'balance' => '1000.00'])->save();
        $fee = new StudentTransaction;
        $fee->forceFill(['student_id' => $student->id, 'transactiontype' => 'Fee', 'transactionamount' => '1000.00',
            'transactiondate' => '2025-01-02', 'processeddate' => '2025-01-02'])->save();
        $filters = ['startdate' => '2025-01-01', 'enddate' => '2025-01-11'];
        $earnedFilters = ['startdate' => '2025-01-01', 'lastpossiblestartdate' => '2025-12-31',
            'transstart' => '2025-01-01', 'dateasof' => '2025-01-11'];
        $attributable = AttributableIncome::run($filters);
        $earned = EarnedIncome::run($earnedFilters);
        DB::transaction(fn () => Enrollments::start($student->fresh(),
            ['program_id' => $first->id, 'started_on' => '2026-01-01']));
        $this->assertSame($first->id, $student->fresh()->program_id);
        $this->assertSame($attributable['calculated'], AttributableIncome::run($filters)['calculated']);
        $this->assertSame($earned['totals'], EarnedIncome::run($earnedFilters)['totals']);
        $this->assertNotNull($fee->fresh()->enrollment_id);
        $this->assertNull($fee->fresh()->stage_enrollment_id);
    }
}
