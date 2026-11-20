<?php

namespace Tests\Feature;

use App\Billing\AttributableIncome;
use App\Billing\EarnedIncome;
use App\Billing\EnrollmentAccounting;
use App\Billing\Enrollments;
use App\Billing\InvoicesReport;
use App\Billing\PrepaysReport;
use App\Billing\RecordVersion;
use App\Billing\StudentSearch;
use App\Models\FormData;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransaction;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\StepFailed;
use App\Workflows\Steps\MarkGraduated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 19));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->program = new Program;
        $this->program->forceFill(['program' => 'EC Full', 'servicedays' => 100])->save();
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Synthetic', 'last_name' => 'Stages', 'balance' => '0.00'])->save();
        foreach ([1 => 100, 2 => 40, 3 => 100] as $stage => $days) {
            DB::table('program_stage_definitions')->insert(['program_id' => $this->program->id, 'stage' => $stage, 'service_days' => $days]);
        }
    }

    private function start(string $date = '2026-01-01', string $mode = 'staged'): StudentEnrollment
    {
        DB::transaction(fn () => Enrollments::start($this->student->fresh(), [
            'program_id' => $this->program->id, 'mode' => $mode, 'started_on' => $date, 'stage' => 1,
        ]));

        return $this->student->fresh()->currentEnrollment;
    }

    private function fee(StudentEnrollment $enrollment, string $amount, string $date, ?int $stageId = null, ?string $recognition = null): StudentTransaction
    {
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $this->student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $stageId, 'recognition' => $recognition,
            'transactiontype' => 'Fee', 'transactionamount' => $amount,
            'transactiondate' => $date, 'processeddate' => $date])->save();

        return $transaction;
    }

    private function action(array $data)
    {
        return $this->post("/students/{$this->student->id}/enrollments", [
            'version' => RecordVersion::of($this->student->fresh()), 'accounts_settled' => 1, ...$data,
        ]);
    }

    public function test_lifecycle_requires_configuration_and_settlement_prevents_overlap_and_keeps_history(): void
    {
        $this->obtainEditingLock($this->student);
        DB::table('program_stage_definitions')->delete();
        $this->action(['action' => 'program', 'program_id' => $this->program->id,
            'mode' => 'staged', 'stage' => 1, 'started_on' => '2026-01-01'])->assertSessionHasErrors('enrollment');
        $this->assertDatabaseCount('student_enrollments', 0);
        $this->put('/admin/stage-settings', ['days' => [$this->program->id => [1 => 100, 2 => 40, 3 => 80]]])->assertRedirect();
        $this->action(['action' => 'program', 'program_id' => $this->program->id,
            'mode' => 'staged', 'stage' => 1, 'started_on' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_stage_enrollments', ['stage' => 1, 'service_days' => 100]);
        $this->action(['action' => 'stage', 'stage' => 2, 'started_on' => '2026-02-01'])->assertSessionHasErrors('enrollment');
        $this->action(['action' => 'complete', 'effective_on' => '2026-04-11'])->assertSessionHasNoErrors();
        $this->assertSame('Stage 1 done', $this->student->fresh()->stageSummary());
        $this->action(['action' => 'stage', 'stage' => 2, 'started_on' => '2026-04-01'])->assertSessionHasErrors('enrollment');
        $this->action(['action' => 'stage', 'stage' => 2, 'started_on' => '2026-06-01', 'accounts_settled' => 0])->assertSessionHasErrors('accounts_settled');
        $this->action(['action' => 'stage', 'stage' => 2, 'started_on' => '2026-06-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-01-01', $this->student->fresh()->start_date->format('Y-m-d'));
        $this->assertDatabaseCount('student_stage_enrollments', 2);
        $this->put('/admin/stage-settings', ['days' => [$this->program->id => [1 => 200]]])->assertRedirect();
        $this->assertDatabaseHas('student_stage_enrollments', ['stage' => 1, 'service_days' => 100]);
        $this->action(['action' => 'complete', 'effective_on' => '2026-07-11'])->assertSessionHasNoErrors();
        $this->action(['action' => 'stage', 'stage' => 3, 'started_on' => '2026-08-01'])->assertSessionHasNoErrors();
        $this->action(['action' => 'complete', 'effective_on' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->assertTrue($this->student->fresh()->graduated);
        $this->assertSame('Graduated', $this->student->fresh()->stageSummary());
        $this->assertDatabaseCount('enrollment_events', 7);
    }

    public function test_period_report_counts_completed_program_and_stage_plus_current_stage_in_one_row(): void
    {
        $first = $this->start('2025-01-01', 'whole');
        $this->fee($first, '500.00', '2026-01-02');
        Enrollments::complete($this->student->fresh(), '2026-01-03');
        $enrollment = $this->start('2026-01-04');
        $stage = $enrollment->stages->first();
        $this->fee($enrollment, '1000.00', '2026-01-05', $stage->id);
        Enrollments::complete($this->student->fresh(), '2026-04-14');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $stage2 = $enrollment->stages()->reorder('id', 'desc')->first();
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(),
            $enrollment->fresh()->load('events'), $stage2, '2026-06-11')->isEqualTo('1/4'));
        $this->fee($enrollment, '2000.00', '2026-06-02', $stage2->id);
        $this->fee($first, '999.00', '2025-12-01');
        $this->fee($enrollment, '888.00', '2026-06-11', $stage2->id);
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-06-11']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('3500.00', $report['rows'][0]['invoiced']);
        $this->assertSame('2000.000000', $report['rows'][0]['earnedincome']);
        $this->assertSame('2000.00', $report['calculated']['totalearned']);
        $lateCorrection = $this->fee($first, '50.00', '2026-06-03');
        $lateCorrection->transactiontype = 'Fee Adjustment';
        $lateCorrection->save();
        $report = AttributableIncome::run(['startdate' => '2026-06-01', 'enddate' => '2026-06-11']);
        $this->assertSame('450.000000', $report['rows'][0]['earnedincome']);
    }

    public function test_completion_is_as_of_report_date_and_application_fees_are_immediate(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        $this->fee($enrollment, '1000.00', '2026-01-02', $stage->id);
        $this->fee($enrollment, '100.00', '2026-01-02', $stage->id, 'application');
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-11']);
        $this->assertSame('200.00', $report['calculated']['totalearned']);
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-05-01']);
        $this->assertSame('1100.00', $report['calculated']['totalearned']);
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-05-01']);
        $this->assertSame('1100.00', $report['calculated']['totalearned']);
    }

    public function test_service_clips_and_unions_loas_and_extension_settles_without_ending_academic_stage(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        DB::table('loas')->insert([
            ['student_id' => $this->student->id, 'loa_start' => '2025-12-01', 'loa_end' => '2026-01-03'],
            ['student_id' => $this->student->id, 'loa_start' => '2026-01-02', 'loa_end' => '2026-01-05'],
            ['student_id' => $this->student->id, 'loa_start' => '2026-02-01', 'loa_end' => null],
        ]);
        $ratio = EnrollmentAccounting::ratio($this->student->fresh()->load('loas'), $enrollment, $stage, '2026-01-11');
        $this->assertTrue($ratio->isEqualTo('3/50'));
        $this->obtainEditingLock($this->student);
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'onextension' => true,
            'extension_start_date' => '2026-01-10',
        ])->assertOk();
        $enrollment->refresh()->load('events');
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment, $stage, '2026-01-10')->isEqualTo(1));
        $this->assertNull($stage->fresh()->ended_on);
        $this->assertSame(100, $stage->fresh()->service_days);
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'onextension' => false,
        ])->assertOk();
        $this->assertSame('active', $stage->fresh()->status);
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage->fresh(), '2026-01-11')->isEqualTo(1));
    }

    public function test_returning_learner_preserves_legacy_results_and_legacy_links(): void
    {
        $this->student->forceFill(['program_id' => $this->program->id, 'start_date' => '2025-01-01',
            'graddate' => '2027-01-01'])->save();
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $this->student->id, 'transactiondate' => '2025-01-02',
            'transactiontype' => 'Fee', 'transactionamount' => '1000.00'])->save();
        $filters = ['startdate' => '2025-01-01', 'enddate' => '2025-01-11'];
        $before = AttributableIncome::run($filters);
        $cohortFilters = ['startdate' => '2025-01-01', 'lastpossiblestartdate' => '2025-12-31',
            'transstart' => '2025-01-01', 'dateasof' => '2025-01-11'];
        $earnedBefore = EarnedIncome::run($cohortFilters);
        $prepaysBefore = PrepaysReport::run($cohortFilters);
        $enrollment = $this->start('2026-01-01');
        $this->fee($enrollment, '2000.00', '2026-01-02', $enrollment->stages->first()->id);
        $after = AttributableIncome::run($filters);
        $this->assertSame($before, $after);
        $earnedAfter = EarnedIncome::run($cohortFilters);
        $this->assertSame($earnedBefore['totals'], $earnedAfter['totals']);
        foreach (['invoiced', 'earned', 'unearned', 'pct'] as $field) {
            $this->assertSame($earnedBefore['rows'][0][$field], $earnedAfter['rows'][0][$field], $field);
        }
        $this->assertSame($prepaysBefore, PrepaysReport::run($cohortFilters));
        $this->assertNotNull($transaction->fresh()->enrollment_id);
        $this->assertSame('legacy', StudentEnrollment::find($transaction->fresh()->enrollment_id)->mode);
        $this->assertSame('2026-01-01', $this->student->fresh()->start_date->format('Y-m-d'));
    }

    public function test_new_entries_default_to_current_stage_and_cross_learner_or_invoice_attribution_is_rejected(): void
    {
        $enrollment = $this->start();
        $this->obtainEditingLock($this->student);
        $data = ['transactiondate' => '2026-01-02', 'transactiontype' => 'Fee', 'transactionamount' => '100.00'];
        $this->postJson("/students/{$this->student->id}/transactions", $data)->assertOk();
        $this->assertDatabaseHas('studenttransactions', ['student_id' => $this->student->id,
            'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $enrollment->stages->first()->id]);
        $this->postJson("/students/{$this->student->id}/transactions", [...$data, 'billing_period' => '999:999'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->postJson("/students/{$this->student->id}/transactions", [...$data, 'billing_period' => $enrollment->id.':'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        DB::table('invoices')->insert(['id' => 50, 'student_id' => $this->student->id, 'enrollment_id' => null]);
        $this->postJson("/students/{$this->student->id}/transactions", [...$data, 'invoice_id' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->get("/detail/{$this->student->id}")->assertOk()->assertSee('Enrollment and stages')->assertSee('Application fee (immediate)');
    }

    public function test_historical_program_and_stage_searches_return_one_learner_and_roles_cannot_bypass_locks(): void
    {
        $enrollment = $this->start();
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $this->assertSame([$this->student->id], StudentSearch::query(['program_id' => $this->program->id,
            'stage' => 1, 'stage_start_from' => '2026-01-01', 'stage_start_to' => '2026-01-01'])->pluck('id')->all());
        $this->assertSame(0, StudentSearch::query(['stage' => 1, 'stage_start_from' => '2026-06-01'])->count());
        $this->assertSame(1, StudentSearch::query(['start_from' => '2026-06-01', 'start_to' => '2026-06-01'])->count());
        $this->assertSame(0, StudentSearch::query(['stage' => 1, 'start_from' => '2026-06-01', 'start_to' => '2026-06-01'])->count());
        $this->postJson("/students/{$this->student->id}/enrollments", [
            'action' => 'complete', 'effective_on' => '2026-07-11',
        ])->assertStatus(423);
        $this->actingAs(User::factory()->create(['access_level' => 'records']));
        $this->get("/records/{$this->student->id}/edit")->assertOk()->assertSee('Current stage (read only)')->assertSee('Stage 2');
        $this->post("/students/{$this->student->id}/enrollments", [])->assertForbidden();
        $this->put('/admin/stage-settings', [])->assertForbidden();
    }

    public function test_earned_and_prepays_reports_combine_completed_and_current_stages(): void
    {
        $enrollment = $this->start();
        $this->fee($enrollment, '1000.00', '2026-01-02', $enrollment->stages->first()->id);
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $this->fee($enrollment, '2000.00', '2026-06-02', $enrollment->stages()->reorder('id', 'desc')->first()->id);
        $filters = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2027-01-01', 'dateasof' => '2026-06-11'];
        $report = EarnedIncome::run($filters);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('1500.000000', $report['rows'][0]['earned']);
        $prepays = PrepaysReport::run([...$filters, 'transstart' => '2026-01-01']);
        $this->assertCount(1, $prepays['rows']);
        $this->assertSame('1500.000000', $prepays['rows'][0]['prorataowed']);
    }

    public function test_corrections_are_audited_reject_overlaps_and_invalidate_old_versions(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $this->obtainEditingLock($this->student);
        $version = RecordVersion::of($this->student->fresh());
        $this->action(['action' => 'correct', 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $stage->id, 'service_days' => 120, 'ended_on' => '2026-06-02',
            'reason' => 'Synthetic overlap correction'])->assertSessionHasErrors('enrollment');
        $this->assertSame(100, $stage->fresh()->service_days);
        $this->action(['action' => 'correct', 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $stage->id, 'service_days' => 120, 'ended_on' => '2026-04-12',
            'reason' => 'Corrected service duration and completion date'])->assertSessionHasNoErrors();
        $this->assertSame('2026-04-12', $stage->fresh()->completed_on->format('Y-m-d'));
        $this->assertSame(120, $stage->fresh()->service_days);
        $this->assertNotSame($version, RecordVersion::of($this->student->fresh()));
        $this->assertDatabaseHas('enrollment_events', ['action' => 'corrected']);
        $this->assertTrue(DB::table('enrollment_events')->where('action', 'completed')->whereDate('effective_on', '2026-04-12')->exists());
        $this->action(['action' => 'complete', 'effective_on' => '2026-07-11', 'version' => $version])
            ->assertSessionHasErrors('version');
    }

    public function test_new_stage_has_its_own_schedule_and_retains_previous_stage_payments(): void
    {
        $enrollment = $this->start();
        $stage1 = $enrollment->stages->first();
        $this->fee($enrollment, '1000.00', '2026-01-02', $stage1->id);
        $this->obtainEditingLock($this->student);
        $response = $this->get("/students/{$this->student->id}/schedule")->assertOk();
        $this->assertSame('0.00', $response->viewData('appFee'));
        $this->putJson("/students/{$this->student->id}/schedule", [
            'version' => $response->viewData('version'), 'paymentplandesc' => 'Stage 1',
            'payments' => [['paymentduedate' => '2026-01-02', 'paymentamount' => '1000.00', 'paymentnotes' => 'First stage']],
        ])->assertOk();
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $stage2 = $enrollment->stages()->reorder('id', 'desc')->first();
        $this->fee($enrollment, '2000.00', '2026-06-02', $stage2->id);
        $response = $this->get("/students/{$this->student->id}/schedule")->assertOk();
        $this->assertNull($response->viewData('plan'));
        $this->assertSame('2000.00', $response->viewData('fees'));
        $this->assertSame('2026-06-01', $response->viewData('startDate')->format('Y-m-d'));
        $this->assertSame('0.00', $response->viewData('appFee'));
        $this->fee($enrollment, '50.00', '2026-06-02', $stage2->id, 'application');
        $this->assertSame('50.00', $this->get("/students/{$this->student->id}/schedule")->viewData('appFee'));
        $this->putJson("/students/{$this->student->id}/schedule", [
            'version' => $response->viewData('version'), 'paymentplandesc' => 'Stage 2',
            'payments' => [['paymentduedate' => '2026-06-02', 'paymentamount' => '2000.00', 'paymentnotes' => 'Second stage']],
        ])->assertOk();
        $this->assertDatabaseCount('studentpaymentplans', 2);
        $this->assertDatabaseHas('anticipatedpayment', ['stage_enrollment_id' => $stage1->id, 'paymentamount' => 1000]);
        $this->assertDatabaseHas('anticipatedpayment', ['stage_enrollment_id' => $stage2->id, 'paymentamount' => 2000]);
    }

    public function test_payment_is_not_settlement_and_suspension_date_corrections_are_as_of(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        $this->obtainEditingLock($this->student);
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'pif' => true, 'pifdate' => '2026-01-02',
        ])->assertOk();
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage, '2026-01-11')->isEqualTo('1/10'));
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'sus' => true, 'sus_date' => '2026-01-21',
        ])->assertOk();
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage, '2026-01-20')->isEqualTo('19/100'));
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage, '2026-01-21')->isEqualTo(1));
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'sus_date' => '2026-01-31',
        ])->assertOk();
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage, '2026-01-21')->isEqualTo('1/5'));
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage, '2026-01-31')->isEqualTo(1));
        $this->assertDatabaseHas('enrollment_events', ['action' => 'corrected']);
    }

    public function test_future_return_enrollments_do_not_change_historical_cohort_membership(): void
    {
        $this->student->forceFill(['program_id' => $this->program->id, 'start_date' => '2024-01-01',
            'graddate' => '2024-12-31'])->save();
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $this->student->id, 'transactiondate' => '2024-01-02',
            'transactiontype' => 'Fee', 'transactionamount' => '1000.00'])->save();
        $filters = ['startdate' => '2025-01-01', 'lastpossiblestartdate' => '2027-01-01',
            'transstart' => '2025-01-01', 'dateasof' => '2025-06-01'];
        $before = EarnedIncome::run($filters);
        $enrollment = $this->start('2026-01-01');
        $this->fee($enrollment, '2000.00', '2026-07-01', $enrollment->stages->first()->id);
        $this->assertSame($before, EarnedIncome::run($filters));
        $this->assertSame([], PrepaysReport::run($filters)['rows']);
        $current = EarnedIncome::run([...$filters, 'dateasof' => '2026-06-01']);
        $this->assertCount(1, $current['rows']);
        $this->assertSame('1000.00', $current['totals']['invoiced']);
        $this->assertSame('1000.00', $current['totals']['total earned']);
    }

    public function test_graduation_workflow_records_current_enrollment_without_reprocessing_unchanged_status(): void
    {
        $enrollment = $this->start(mode: 'whole');
        $this->student->refresh()->forceFill(['onextension' => true, 'extension_start_date' => '2026-01-10'])->save();
        $form = FormData::create(['form_type' => 'graduation-initiation', 'student_id' => $this->student->id,
            'program_id' => $this->program->id, 'enrollment_id' => $enrollment->id, 'enrollment_key' => 'enrollment:'.$enrollment->id, 'data' => []]);
        $run = new WorkflowRun;
        $run->subject_id = $form->id;
        $outcome = (new MarkGraduated)->reach($run, new WorkflowStep);
        $this->assertSame('done', $outcome->kind);
        $this->assertSame('graduated', $enrollment->fresh()->status);
        $this->assertDatabaseHas('enrollment_events', ['enrollment_id' => $enrollment->id, 'action' => 'graduated']);
    }

    public function test_withdrawn_stage_can_be_corrected_without_settlement_checkbox_and_reopened(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        $this->obtainEditingLock($this->student);
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'wd' => true, 'wd_date' => '2026-01-21',
        ])->assertOk();
        $post = fn (array $data) => $this->post("/students/{$this->student->id}/enrollments", [
            'version' => RecordVersion::of($this->student->fresh()), 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $stage->id, 'reason' => 'Synthetic correction', ...$data,
        ]);
        $post(['action' => 'correct', 'service_days' => 120, 'ended_on' => '2026-01-22'])->assertSessionHasNoErrors();
        $this->assertSame('2026-01-22', $stage->fresh()->ended_on->format('Y-m-d'));
        $this->assertSame('2026-01-22', $enrollment->fresh()->ended_on->format('Y-m-d'));
        $this->assertSame('2026-01-22', $this->student->fresh()->wd_date->format('Y-m-d'));
        $post(['action' => 'reopen'])->assertSessionHasNoErrors();
        $this->assertNull($stage->fresh()->ended_on);
        $this->assertNull($enrollment->fresh()->ended_on);
        $this->assertFalse($this->student->fresh()->wd);
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage->fresh(), '2026-01-23')->isEqualTo(1));
    }

    public function test_cancellation_retains_billing_and_stops_service_without_forcing_settlement(): void
    {
        $enrollment = $this->start('2026-11-01');
        $stage = $enrollment->stages->first();
        $fee = $this->fee($enrollment, '1000.00', '2026-11-02', $stage->id);
        $this->obtainEditingLock($this->student);
        $this->post("/students/{$this->student->id}/enrollments", [
            'version' => RecordVersion::of($this->student->fresh()), 'action' => 'cancel',
            'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage->id,
            'effective_on' => '2026-11-11', 'reason' => 'Cancelled purchase; billing retained for staff review',
        ])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $stage->fresh()->status);
        $this->assertSame('cancelled', $enrollment->fresh()->status);
        $this->assertNull($stage->fresh()->completed_on);
        $this->assertSame('1000.00', $fee->fresh()->transactionamount);
        $this->assertSame($stage->id, $fee->fresh()->stage_enrollment_id);
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage->fresh(), '2026-11-06')->isEqualTo('1/20'));
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage->fresh(), '2026-11-19')->isEqualTo('1/10'));
        $this->get("/detail/{$this->student->id}")->assertOk()
            ->assertSee('Program start: 2026-11-01')->assertSee('Program end: 2026-11-11')
            ->assertSee('Resume study')->assertSee('Undo closure')->assertSee('Edit stage');
    }

    public function test_historical_stage_cannot_be_reopened_over_a_later_stage(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $this->obtainEditingLock($this->student);
        $this->post("/students/{$this->student->id}/enrollments", [
            'version' => RecordVersion::of($this->student->fresh()), 'action' => 'reopen',
            'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage->id, 'reason' => 'Must reject overlap',
        ])->assertSessionHasErrors('enrollment');
        $this->assertSame('completed', $stage->fresh()->status);
    }

    public function test_completed_programs_and_stages_still_allow_transaction_crud(): void
    {
        foreach (['staged', 'whole'] as $mode) {
            $this->student = new Student;
            $this->student->forceFill(['first_name' => 'Synthetic', 'last_name' => 'Closed '.$mode, 'balance' => '0.00'])->save();
            $enrollment = $this->start('2026-01-01', $mode);
            $stageId = $enrollment->stages->first()?->id;
            $fee = $this->fee($enrollment, '1000.00', '2026-01-02', $stageId);
            Enrollments::complete($this->student->fresh(), '2026-04-11');
            $this->obtainEditingLock($this->student);
            $this->patchJson("/students/{$this->student->id}/transactions/{$fee->id}", [
                'version' => RecordVersion::of($fee->fresh()), 'transactionamount' => '1100.00',
            ])->assertOk();
            $this->postJson("/students/{$this->student->id}/transactions", [
                'transactiondate' => '2026-04-12', 'transactiontype' => 'Payment', 'transactionamount' => '-100.00',
            ])->assertOk();
            $this->deleteJson("/students/{$this->student->id}/transactions/{$fee->id}", [
                'version' => RecordVersion::of($fee->fresh()),
            ])->assertOk();
            $this->assertDatabaseMissing('studenttransactions', ['id' => $fee->id]);
        }
    }

    public function test_stage_configuration_is_in_utilities_not_each_learner_detail(): void
    {
        $this->get('/utilities')->assertOk()->assertSee('Configure program stage service days');
        $this->get("/detail/{$this->student->id}")->assertOk()->assertDontSee('Configure program stage service days');
    }

    public function test_graduation_workflow_refuses_an_earlier_enrollment_form(): void
    {
        $form = FormData::create(['form_type' => 'graduation-initiation', 'student_id' => $this->student->id,
            'program_id' => $this->program->id, 'data' => [], 'created_at' => now()->subDay()]);
        $this->start(mode: 'whole');
        $run = new WorkflowRun;
        $run->subject_id = $form->id;
        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('earlier enrollment');
        (new MarkGraduated)->reach($run, new WorkflowStep);
    }

    public function test_stage_ledger_exposes_current_stage_fees_instead_of_all_historical_fees(): void
    {
        $enrollment = $this->start();
        $this->fee($enrollment, '9000.00', '2026-01-02', $enrollment->stages->first()->id);
        Enrollments::complete($this->student->fresh(), '2026-04-11');
        Enrollments::startStage($this->student->fresh(), ['stage' => 2, 'started_on' => '2026-06-01']);
        $stage = $enrollment->stages()->reorder('id', 'desc')->first();
        $this->fee($enrollment, '1000.00', '2026-06-02', $stage->id);
        $this->get("/detail/{$this->student->id}")->assertOk()
            ->assertSee('data-fees="10000.00"', false)->assertSee('data-current-fees="1000.00"', false);
    }

    public function test_resume_preserves_past_service_and_unions_closed_gaps_with_leave(): void
    {
        $enrollment = $this->start();
        $stage = $enrollment->stages->first();
        DB::table('loas')->insert(['student_id' => $this->student->id, 'loa_start' => '2026-01-05', 'loa_end' => '2026-01-15']);
        $this->obtainEditingLock($this->student);
        $base = ['enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage->id, 'reason' => 'Synthetic lifecycle check'];
        $this->action([...$base, 'action' => 'cancel', 'effective_on' => '2026-01-11'])->assertSessionHasNoErrors();
        $ratio = fn ($date) => EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), $stage->fresh(), $date);
        $this->assertTrue($ratio('2026-02-01')->isEqualTo('1/25'));
        $this->action([...$base, 'action' => 'resume', 'effective_on' => '2026-01-10'])->assertSessionHasErrors('enrollment');
        $this->action([...$base, 'action' => 'resume', 'effective_on' => '2026-03-01'])->assertSessionHasNoErrors();
        $this->assertTrue($ratio('2026-02-01')->isEqualTo('1/25'));
        $this->assertTrue($ratio('2026-03-11')->isEqualTo('7/50'));
        $this->action([...$base, 'action' => 'cancel', 'effective_on' => '2026-03-11'])->assertSessionHasNoErrors();
        $this->action([...$base, 'action' => 'resume', 'effective_on' => '2026-04-01'])->assertSessionHasNoErrors();
        $this->assertTrue($ratio('2026-04-11')->isEqualTo('6/25'));
    }

    public function test_undo_closure_reverses_only_its_settlement_and_retains_billing_and_audit(): void
    {
        $enrollment = $this->start(mode: 'whole');
        $fee = $this->fee($enrollment, '1000.00', '2026-01-02');
        Enrollments::complete($this->student->fresh(), '2026-01-11');
        $this->obtainEditingLock($this->student);
        $data = ['action' => 'undo', 'enrollment_id' => $enrollment->id, 'reason' => 'Completion entered by mistake'];
        $this->action($data)->assertSessionHasErrors('confirm_undo');
        $this->action([...$data, 'confirm_undo' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($this->student->fresh()->graduated);
        $this->assertNull($enrollment->fresh()->ended_on);
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), null, '2026-01-21')->isEqualTo('1/5'));
        $this->assertNotNull($enrollment->events()->where('action', 'completed')->first()->voided_at);
        $this->assertDatabaseHas('enrollment_events', ['action' => 'closure_undone']);
        $this->assertSame('1000.00', $fee->fresh()->transactionamount);
    }

    public function test_undo_completion_preserves_an_earlier_independent_extension_settlement(): void
    {
        $enrollment = $this->start(mode: 'whole');
        $this->obtainEditingLock($this->student);
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'onextension' => true, 'extension_start_date' => '2026-01-05',
        ])->assertOk();
        Enrollments::complete($this->student->fresh(), '2026-01-11');
        $this->action(['action' => 'undo', 'enrollment_id' => $enrollment->id,
            'reason' => 'Graduation was premature', 'confirm_undo' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($this->student->fresh()->onextension);
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), null, '2026-01-21')->isEqualTo(1));
    }

    public function test_clearing_extension_does_not_reopen_graduation_and_ajax_returns_fresh_controls(): void
    {
        $enrollment = $this->start(mode: 'whole');
        $this->obtainEditingLock($this->student);
        foreach ([['onextension' => true, 'extension_start_date' => '2026-01-05'],
            ['graduated' => true, 'actualdategrad' => '2026-01-11'], ['onextension' => false]] as $edit) {
            $response = $this->patchJson("/students/{$this->student->id}", [
                'version' => RecordVersion::of($this->student->fresh()), ...$edit,
            ])->assertOk();
        }
        $this->assertSame('graduated', $enrollment->fresh()->status);
        $this->assertSame('2026-01-11', $enrollment->fresh()->ended_on->format('Y-m-d'));
        $this->assertTrue($this->student->fresh()->graduated);
        $this->assertStringContainsString('Resume study', $response->json('enrollmentHtml'));
        $this->assertStringContainsString(RecordVersion::of($this->student->fresh()), $response->json('enrollmentHtml'));
    }

    public function test_invoice_status_filters_use_enrollment_status_at_report_end_not_latest_learner_status(): void
    {
        $enrollment = $this->start(mode: 'whole');
        DB::table('invoices')->insert(['student_id' => $this->student->id, 'enrollment_id' => $enrollment->id,
            'amount' => '100.00', 'datesent' => '2026-01-02']);
        Enrollments::complete($this->student->fresh(), '2026-01-11');
        $filters = ['invoicesentafterdate' => '2026-01-01', 'invoicesentbeforedate' => '2026-01-10', 'hidegraduated' => true];
        $before = InvoicesReport::run($filters);
        $this->assertSame('100.00', $before['totals']['Total invoiced']);
        $ended = [...$filters, 'invoicesentbeforedate' => '2026-01-11'];
        $this->assertSame('0.00', InvoicesReport::run($ended)['totals']['Total invoiced']);
        $this->start('2026-02-01', 'whole');
        $this->assertSame($before, InvoicesReport::run($filters));
        $this->assertSame('0.00', InvoicesReport::run($ended)['totals']['Total invoiced']);
    }

    public function test_legacy_filtered_invoice_totals_stay_unchanged_when_a_return_enrollment_starts(): void
    {
        $this->student->forceFill(['program_id' => $this->program->id, 'start_date' => '2025-01-01',
            'graduated' => true, 'actualdategrad' => '2025-04-11', 'inactive' => true, 'inactive_date' => '2025-03-01'])->save();
        DB::table('invoices')->insert(['student_id' => $this->student->id, 'amount' => '100.00', 'datesent' => '2025-02-01']);
        foreach (['hidegraduated', 'hideinactive'] as $flag) {
            $filters[$flag] = ['invoicesentbeforedate' => '2025-12-31', $flag => true];
            $before[$flag] = InvoicesReport::run($filters[$flag]);
        }
        $this->start();
        foreach ($filters as $flag => $filter) {
            $this->assertSame($before[$flag], InvoicesReport::run($filter));
        }
    }

    public function test_inactive_invoice_filter_retains_closed_period_status_after_resume(): void
    {
        $enrollment = $this->start(mode: 'whole');
        DB::table('invoices')->insert(['student_id' => $this->student->id, 'enrollment_id' => $enrollment->id,
            'amount' => '100.00', 'datesent' => '2026-01-02']);
        $this->obtainEditingLock($this->student);
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()), 'inactive' => true, 'inactive_date' => '2026-01-11',
        ])->assertOk();
        $filters = ['invoicesentbeforedate' => '2026-02-01', 'hideinactive' => true];
        $before = InvoicesReport::run($filters);
        $this->assertSame('0.00', $before['totals']['Total invoiced']);
        $this->action(['action' => 'resume', 'enrollment_id' => $enrollment->id, 'effective_on' => '2026-03-01',
            'reason' => 'Returned to study'])->assertSessionHasNoErrors();
        $this->assertSame($before, InvoicesReport::run($filters));
        $this->assertSame('100.00', InvoicesReport::run([...$filters, 'invoicesentbeforedate' => '2026-03-01'])['totals']['Total invoiced']);
    }

    public function test_undo_cancellation_restores_a_preceding_valid_completion_and_settlement(): void
    {
        $enrollment = $this->start(mode: 'whole');
        Enrollments::complete($this->student->fresh(), '2026-01-11');
        $this->obtainEditingLock($this->student);
        $data = ['enrollment_id' => $enrollment->id, 'reason' => 'Synthetic correction'];
        $this->action([...$data, 'action' => 'cancel', 'effective_on' => '2026-01-20'])->assertSessionHasNoErrors();
        $this->action([...$data, 'action' => 'resume', 'effective_on' => '2026-01-15'])->assertSessionHasErrors('enrollment');
        $this->action([...$data, 'action' => 'undo', 'confirm_undo' => 1])->assertSessionHasNoErrors();
        $this->assertSame('graduated', $enrollment->fresh()->status);
        $this->assertSame('2026-01-11', $enrollment->fresh()->ended_on->format('Y-m-d'));
        $this->assertTrue($this->student->fresh()->graduated);
        $this->assertTrue(EnrollmentAccounting::ratio($this->student->fresh(), $enrollment->fresh(), null, '2026-01-21')->isEqualTo(1));
    }

    public function test_refreshed_enrollment_forms_cannot_graduate_the_refreshed_legacy_learner(): void
    {
        $form = FormData::create(['form_type' => 'graduation-initiation', 'student_id' => $this->student->id,
            'program_id' => $this->program->id, 'enrollment_key' => 'refresh:99', 'data' => []]);
        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('discarded by a staging refresh');
        (new MarkGraduated)->reach(new WorkflowRun(['subject_id' => $form->id]), new WorkflowStep);
    }
}
