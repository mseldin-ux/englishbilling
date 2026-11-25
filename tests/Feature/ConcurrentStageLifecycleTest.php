<?php

namespace Tests\Feature;

use App\Billing\AttributableIncome;
use App\Billing\EarnedIncome;
use App\Billing\EnrollmentAccounting;
use App\Billing\Enrollments;
use App\Billing\InvoiceEmail;
use App\Billing\InvoicesReport;
use App\Billing\PdfRenderer;
use App\Billing\PrepaysReport;
use App\Billing\RecordVersion;
use App\Billing\StageBillingAllocations;
use App\Billing\StudentSearch;
use App\Models\FormData;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\PaymentPlan;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\StepFailed;
use App\Workflows\Steps\MarkGraduated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConcurrentStageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 19));
    }

    public function test_overlap_keeps_separate_stage_earnings_and_completion_does_not_graduate(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $stage1 = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $stage2 = $enrollment->stages()->where('stage', 2)->firstOrFail();
        foreach ([$stage1, $stage2] as $stage) {
            $fee = new StudentTransaction;
            $fee->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
                'stage_enrollment_id' => $stage->id, 'transactiontype' => 'Fee',
                'transactiondate' => $stage->started_on->format('Y-m-d'), 'processeddate' => $stage->started_on->format('Y-m-d'), 'transactionamount' => '1000.00'])->save();
        }
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-11']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('225.00', $report['calculated']['totalearned']);
        Enrollments::complete($student->fresh(), '2026-01-09', $stage2->id);
        $this->assertNull($stage1->fresh()->ended_on);
        $this->assertSame('completed', $stage2->fresh()->status);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame('active', EnrollmentAccounting::statusAt($enrollment->fresh(), '2026-01-09'));
        $this->assertFalse((bool) $student->fresh()->graduated);
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-10']);
        $this->assertSame('1090.00', $report['calculated']['totalearned']);
        $earlier = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-08']);
        $this->assertSame('120.00', $earlier['calculated']['totalearned']);
    }

    public function test_concurrent_completion_requires_an_owned_stage_and_duplicate_active_stage_is_rejected(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        foreach ([
            fn () => Enrollments::complete($student->fresh(), '2026-01-09'),
            fn () => Enrollments::complete($student->fresh(), '2026-01-09', 9999),
            fn () => Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-10']),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid stage operation should fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('enrollment', $exception->errors());
            }
        }
        $this->assertSame(2, $enrollment->stages()->whereNull('ended_on')->count());
    }

    public function test_concurrent_stages_remain_disabled_by_default(): void
    {
        [$student] = $this->stagedLearner();
        $this->expectException(ValidationException::class);
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
    }

    public function test_learner_status_changes_apply_to_both_active_stages(): void
    {
        config(['billing.concurrent_stages' => true]);
        foreach (['wd' => 'wd_date', 'sus' => 'sus_date', 'inactive' => 'inactive_date', 'onextension' => 'extension_start_date'] as $flag => $field) {
            [$student, $enrollment] = $this->stagedLearner();
            Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
            $this->changeStatus($student, [$flag => true, $field => '2026-01-10']);
            $stages = $enrollment->stages()->get();
            foreach ($stages as $stage) {
                $this->assertSame($flag, $stage->status);
                $this->assertSame($flag === 'onextension' ? null : '2026-01-10', $stage->ended_on?->toDateString());
                $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $stage, '2026-01-10')->isEqualTo(1));
            }
            $this->changeStatus($student, [$flag => false, $field => null]);
            $this->assertSame(2, $enrollment->stages()->where('status', 'active')->whereNull('ended_on')->count());
        }
    }

    public function test_graduation_requires_all_stages_closed_and_preserves_independent_completion(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        try {
            $this->changeStatus($student, ['graduated' => true, 'actualdategrad' => '2026-01-10']);
            $this->fail('Graduation should require closed stages.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }
        $this->assertFalse((bool) $student->fresh()->graduated);
        Enrollments::complete($student->fresh(), '2026-01-10', $first->id);
        Enrollments::complete($student->fresh(), '2026-01-12', $second->id);
        $this->assertFalse((bool) $student->fresh()->graduated);
        $this->assertSame('between_stages', EnrollmentAccounting::statusAt($enrollment->fresh(), '2026-01-12'));
        $this->changeStatus($student, ['graduated' => true, 'actualdategrad' => '2026-01-13']);
        $this->assertTrue((bool) $student->fresh()->graduated);
        $this->assertSame('graduated', $enrollment->fresh()->status);
        $this->assertSame('graduated', EnrollmentAccounting::statusAt($enrollment->fresh(), '2026-01-13'));
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $first->fresh(), '2026-01-09')->isEqualTo('2/25'));
    }

    public function test_overlapping_stages_preserve_learner_leave_and_share_its_service_exclusion(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $student->forceFill(['loa' => true, 'loa_date' => '2026-01-07', 'pif' => true, 'pifdate' => '2026-01-05'])->save();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $this->assertTrue((bool) $student->fresh()->loa);
        $this->assertTrue((bool) $student->fresh()->pif);
        DB::table('loas')->insert(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $enrollment->stages->first()->id, 'loa_start' => '2026-01-07', 'loa_end' => '2026-01-09']);
        $stages = $enrollment->stages()->orderBy('stage')->get();
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $stages[0], '2026-01-11')->isEqualTo('2/25'));
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $stages[1], '2026-01-11')->isEqualTo('3/40'));
    }

    public function test_invalid_managed_leave_identifies_the_learner(): void
    {
        [$student, $enrollment] = $this->stagedLearner();
        $leave = new LeaveOfAbsence;
        $leave->forceFill(['student_id' => $student->id, 'loa_start' => '2026-01-05', 'loa_end' => '2026-01-04'])->save();
        try {
            EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $enrollment->stages->first(), '2026-01-11');
            $this->fail('Invalid leave should be reported.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString("Leave {$leave->id}", $exception->errors()['report'][0]);
            $this->assertStringContainsString("learner {$student->id}", $exception->errors()['report'][0]);
            $this->assertArrayHasKey("report_student.{$student->id}", $exception->errors());
        }
    }

    public function test_new_leave_is_recorded_for_all_stages_without_rewriting_old_stage_leave(): void
    {
        config(['billing.concurrent_stages' => true]);
        $this->actingAs(User::factory()->create());
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $this->obtainEditingLock($student);
        $oldId = DB::table('loas')->insertGetId(['student_id' => $student->id,
            'enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $first->id,
            'loa_start' => '2026-01-02', 'loa_end' => '2026-01-03']);
        $url = "/students/{$student->id}/loas";
        $this->get($url)->assertOk()->assertSee('All programs and stages');
        $data = ['loa_start' => '2026-01-07', 'loa_end' => '2026-01-09'];
        $this->postJson($url, [...$data, 'billing_period' => $enrollment->id.':'.$first->id])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->postJson($url, $data)->assertOk();
        $this->assertDatabaseHas('loas', ['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => null, 'loa_start' => '2026-01-07 00:00:00']);
        $leave = LeaveOfAbsence::where('student_id', $student->id)->whereNull('stage_enrollment_id')->firstOrFail();
        $this->patchJson($url.'/'.$leave->id, ['version' => RecordVersion::of($leave),
            'billing_period' => $enrollment->id.':'.$first->id])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->assertDatabaseHas('loas', ['id' => $oldId, 'stage_enrollment_id' => $first->id]);
        $stages = $enrollment->stages()->orderBy('stage')->get();
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $stages[0], '2026-01-11')->isEqualTo('7/100'));
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $stages[1], '2026-01-11')->isEqualTo('3/40'));
    }

    public function test_stage_started_during_existing_leave_inherits_it_without_changing_original_stage(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        DB::table('loas')->insert(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $first->id, 'loa_start' => '2026-01-07', 'loa_end' => '2026-01-09']);
        $before = EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $first, '2026-01-11');
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-08']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->assertTrue($before->isEqualTo(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $first, '2026-01-11')));
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $second, '2026-01-11')->isEqualTo('1/20'));
    }

    public function test_correcting_a_learner_closure_date_updates_both_stages(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $this->changeStatus($student, ['wd' => true, 'wd_date' => '2026-01-10']);
        $this->changeStatus($student, ['wd_date' => '2026-01-11']);
        $this->assertSame(2, $enrollment->stages()->whereDate('ended_on', '2026-01-11')->count());
        $this->assertSame(2, $enrollment->events()->where('action', 'wd')->whereDate('effective_on', '2026-01-11')->count());
    }

    public function test_extension_applies_to_stages_started_before_or_after_its_effective_date(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $this->changeStatus($student, ['onextension' => true, 'extension_start_date' => '2026-01-10']);
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->assertSame('onextension', $second->status);
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $second, '2026-01-09')->isEqualTo('3/40'));
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $second, '2026-01-10')->isEqualTo(1));
        $this->changeStatus($student, ['extension_start_date' => '2026-01-05']);
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $second, '2026-01-06')->isEqualTo(1));
        $this->changeStatus($student, ['wd' => true, 'wd_date' => '2026-01-11']);
        $this->changeStatus($student, ['wd' => false, 'wd_date' => null]);
        $this->assertSame(2, $enrollment->stages()->where('status', 'onextension')->count());
    }

    public function test_status_date_cannot_be_backdated_across_a_completed_stage(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        Enrollments::complete($student->fresh(), '2026-01-12', $first->id);
        try {
            $this->changeStatus($student, ['wd' => true, 'wd_date' => '2026-01-10']);
            $this->fail('A learner-wide closure cannot precede a completed stage.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }
        $this->assertFalse((bool) $student->fresh()->wd);
        $this->assertNull($enrollment->stages()->where('stage', 2)->firstOrFail()->ended_on);
    }

    public function test_extension_date_correction_includes_stages_completed_during_extension(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->changeStatus($student, ['onextension' => true, 'extension_start_date' => '2026-01-09']);
        Enrollments::complete($student->fresh(), '2026-01-12', $second->id);
        $this->changeStatus($student, ['extension_start_date' => '2026-01-11']);
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $second->fresh(), '2026-01-10')->isEqualTo('1/10'));
        $this->assertTrue(EnrollmentAccounting::ratio($student->fresh(), $enrollment->fresh(), $second->fresh(), '2026-01-11')->isEqualTo(1));
    }

    public function test_backdated_stage_cannot_skip_learner_wide_closure_history(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $this->changeStatus($student, ['wd' => true, 'wd_date' => '2026-01-10']);
        $this->changeStatus($student, ['wd' => false, 'wd_date' => null]);
        try {
            Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-11']);
            $this->fail('A new stage cannot silently skip prior withdrawal history.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }
        $this->assertSame(1, $enrollment->stages()->count());
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-11-19']);
        $this->assertSame(2, $enrollment->stages()->count());
    }

    public function test_stage_completion_cannot_be_backdated_before_later_stage_status_activity(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $stage = $enrollment->stages->first();
        $this->changeStatus($student, ['onextension' => true, 'extension_start_date' => '2026-01-10']);
        try {
            Enrollments::complete($student->fresh(), '2026-01-09', $stage->id);
            $this->fail('Completion cannot precede recorded extension activity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }
        $this->assertNull($stage->fresh()->ended_on);
    }

    public function test_backdated_stage_cannot_skip_a_cleared_extension(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $this->changeStatus($student, ['onextension' => true, 'extension_start_date' => '2026-01-10']);
        $this->changeStatus($student, ['onextension' => false, 'extension_start_date' => null]);
        try {
            Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
            $this->fail('A new stage cannot silently skip a cleared extension.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }
        $this->assertSame(1, $enrollment->stages()->count());
    }

    public function test_completing_the_final_stage_does_not_graduate_the_learner(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        Enrollments::startStage($student->fresh(), ['stage' => 3, 'started_on' => '2026-01-06']);
        $final = $enrollment->stages()->where('stage', 3)->firstOrFail();
        Enrollments::complete($student->fresh(), '2026-01-10', $final->id);
        $this->assertFalse((bool) $student->fresh()->graduated);
        $this->assertSame('active', EnrollmentAccounting::statusAt($enrollment->fresh(), '2026-01-10'));
    }

    public function test_payment_plans_require_stage_selection_and_keep_each_schedule_separate(): void
    {
        config(['billing.concurrent_stages' => true]);
        $this->actingAs(User::factory()->create());
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        foreach ([$first->id => '600.00', $second->id => '400.00'] as $stageId => $amount) {
            $fee = new StudentTransaction;
            $fee->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
                'stage_enrollment_id' => $stageId, 'transactiontype' => 'Fee', 'transactionamount' => $amount])->save();
        }
        $template = new PaymentPlan;
        $template->forceFill(['paymentplan' => 'One payment', 'monthslist' => '1'])->save();
        $this->obtainEditingLock($student);
        $url = "/students/{$student->id}/schedule";
        $period1 = $enrollment->id.':'.$first->id;
        $period2 = $enrollment->id.':'.$second->id;
        $this->get($url)->assertOk()->assertSee('Choose a stage')->assertDontSee('Save Payment Plan');
        $generation = ['template_id' => $template->id, 'start_date' => '2026-01-06', 'app_fee' => '0.00'];
        $this->postJson($url.'/generate', $generation)->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->postJson($url.'/generate', [...$generation, 'billing_period' => $period2])
            ->assertOk()->assertJsonPath('payments.0.paymentamount', '400.00');
        $firstPage = $this->get($url.'?billing_period='.$period1)->assertOk();
        $secondPage = $this->get($url.'?billing_period='.$period2)->assertOk();
        $this->assertSame('600.00', $firstPage->viewData('fees'));
        $this->assertSame('400.00', $secondPage->viewData('fees'));
        $payload = ['version' => $firstPage->viewData('version'), 'billing_period' => $period1,
            'paymentplandesc' => 'Stage one', 'payments' => [
                ['paymentduedate' => '2026-12-01', 'paymentamount' => '600.00', 'paymentnotes' => 'First stage'],
            ]];
        $this->putJson($url, [...$payload, 'billing_period' => $period2])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson($url, $payload)->assertOk();
        $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson($url, [...$payload, 'version' => $secondPage->viewData('version'),
            'billing_period' => $period2, 'paymentplandesc' => 'Stage two',
            'payments' => [['paymentduedate' => '2026-12-01', 'paymentamount' => '400.00', 'paymentnotes' => 'Second stage']]])->assertOk();
        $this->assertDatabaseHas('studentpaymentplans', ['student_id' => $student->id,
            'stage_enrollment_id' => $first->id, 'paymentplandesc' => 'Stage one']);
        $this->assertDatabaseHas('studentpaymentplans', ['student_id' => $student->id,
            'stage_enrollment_id' => $second->id, 'paymentplandesc' => 'Stage two']);
        $this->get($url.'?billing_period='.$enrollment->id.':99999')->assertSessionHasErrors('billing_period');
        $detail = $this->get("/detail/{$student->id}")->assertOk()
            ->assertSee('data-planned-period="'.$period1.'"', false)
            ->assertSee('data-planned-period="'.$period2.'"', false);
        $this->assertSame(2, substr_count($detail->getContent(), 'No matching invoice.'));
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $first->id, 'nextdate' => '2026-12-01', 'amount' => '1000.00'])->save();
        StageBillingAllocations::replace($student, $invoice, [
            ['stage_enrollment_id' => $first->id, 'amount' => '600.00'],
            ['stage_enrollment_id' => $second->id, 'amount' => '400.00'],
        ]);
        $detail = $this->get("/detail/{$student->id}")->assertOk();
        $this->assertSame(2, substr_count($detail->getContent(), 'Found matching invoice.'));
    }

    public function test_split_fees_are_earned_by_stage_without_increasing_learner_totals(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $fee = new StudentTransaction;
        $fee->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $first->id, 'transactiontype' => 'Fee',
            'transactiondate' => '2026-01-06', 'processeddate' => '2026-01-06', 'transactionamount' => '1000.00'])->save();
        StageBillingAllocations::replace($student, $fee, [
            ['stage_enrollment_id' => $first->id, 'amount' => '600.00'],
            ['stage_enrollment_id' => $second->id, 'amount' => '400.00'],
        ]);
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-11']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('1000.00', $report['rows'][0]['invoiced']);
        $this->assertSame('110.00', $report['calculated']['totalearned']);
        $filters = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2027-01-01',
            'transstart' => '2026-01-01', 'dateasof' => '2026-01-11'];
        $earned = EarnedIncome::run($filters);
        $this->assertSame('1000.00', $earned['totals']['invoiced']);
        $this->assertSame('110.00', $earned['totals']['total earned']);
        $fee->forceFill(['processeddate' => '2026-01-11'])->save();
        $cutoff = EarnedIncome::run($filters);
        $this->assertSame('0.00', $cutoff['totals']['invoiced']);
        $this->assertSame('0.00', $cutoff['totals']['total earned']);
        $fee->forceFill(['processeddate' => '2026-01-12'])->save();
        $this->assertSame($cutoff['totals'], EarnedIncome::run($filters)['totals']);
        $fee->forceFill(['processeddate' => '2026-01-06'])->save();
        $this->assertSame('110.000000', PrepaysReport::run($filters)['rows'][0]['prorataowed']);
        $this->actingAs(User::factory()->create());
        $this->assertSame('600.00', $this->get("/students/{$student->id}/schedule?billing_period={$enrollment->id}:{$first->id}")->viewData('fees'));
        $this->assertSame('400.00', $this->get("/students/{$student->id}/schedule?billing_period={$enrollment->id}:{$second->id}")->viewData('fees'));
        Enrollments::complete($student->fresh(), '2026-01-09', $second->id);
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-11']);
        $this->assertSame('460.00', $report['calculated']['totalearned']);
        DB::table('transaction_stage_allocations')->where('transaction_id', $fee->id)
            ->where('stage_enrollment_id', $second->id)->update(['amount' => '399.99']);
        $this->expectException(ValidationException::class);
        AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-01-11']);
    }

    public function test_billing_allocations_are_atomic_versioned_and_keep_single_invoice_and_payment(): void
    {
        config(['billing.concurrent_stages' => true]);
        $sender = User::factory()->create();
        $this->actingAs($sender);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->obtainEditingLock($student);
        $this->mock(PdfRenderer::class)->shouldReceive('render')->once()->andReturn('%PDF-synthetic');
        $invoiceUrl = "/students/{$student->id}/invoices";
        $period = $enrollment->id.':'.$first->id;
        $splits = [['stage_enrollment_id' => $first->id, 'amount' => '600.00'],
            ['stage_enrollment_id' => $second->id, 'amount' => '400.00']];
        $invoiceData = ['billing_period' => $period, 'nextdate' => '2026-02-01', 'amount' => '1000.00',
            'invoicereference' => 'Synthetic', 'allocations' => $splits];
        $this->postJson($invoiceUrl, [...$invoiceData, 'billing_period' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->postJson($invoiceUrl, [...$invoiceData, 'allocations' => [
            ['stage_enrollment_id' => $first->id, 'amount' => '999.99']]])
            ->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $response = $this->postJson($invoiceUrl, $invoiceData)->assertOk();
        $invoice = Invoice::findOrFail($response->json('invoice_id'));
        $this->assertSame('1, 2', InvoiceEmail::variables($student->fresh(), $invoice, $sender)['stage']);
        $transactionUrl = "/students/{$student->id}/transactions";
        $payment = ['billing_period' => $period, 'invoice_id' => $invoice->id,
            'transactiondate' => '2026-01-07', 'processeddate' => '2026-01-07', 'transactiontype' => 'Payment', 'transactionamount' => '-250.00',
            'allocations' => [['stage_enrollment_id' => $first->id, 'amount' => '150.00'],
                ['stage_enrollment_id' => $second->id, 'amount' => '100.00']]];
        $this->postJson($transactionUrl, $payment)->assertOk();
        $transaction = StudentTransaction::firstOrFail();
        $this->get($invoiceUrl)->assertOk()->assertSee('stage_allocation_'.$second->id);
        $this->get($transactionUrl)->assertOk()->assertSee('stage_allocation_'.$second->id);
        $this->assertSame('-250.00', $student->fresh()->balance);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('studenttransactions', 1);
        $version = RecordVersion::of($invoice->fresh());
        $this->patchJson($invoiceUrl.'/'.$invoice->id, ['version' => $version, 'amount' => '999.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->assertSame('1000.00', $invoice->fresh()->amount);
        $this->patchJson($invoiceUrl.'/'.$invoice->id, ['version' => $version, 'allocations' => [
            ['stage_enrollment_id' => $first->id, 'amount' => '1000.00']]])
            ->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->assertDatabaseCount('invoice_stage_allocations', 2);
        $paymentVersion = RecordVersion::of($transaction->fresh());
        $this->patchJson($transactionUrl.'/'.$transaction->id, ['version' => $paymentVersion,
            'allocations' => [['stage_enrollment_id' => $first->id, 'amount' => '200.00'],
                ['stage_enrollment_id' => $second->id, 'amount' => '50.00']]])->assertOk();
        $this->patchJson($transactionUrl.'/'.$transaction->id, ['version' => $paymentVersion,
            'transactionnotes' => 'stale'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->deleteJson($transactionUrl.'/'.$transaction->id,
            ['version' => RecordVersion::of($transaction->fresh())])->assertOk();
        $this->assertDatabaseCount('transaction_stage_allocations', 0);
    }

    public function test_staff_can_complete_each_stage_and_graduate_separately(): void
    {
        config(['billing.concurrent_stages' => true]);
        $this->actingAs(User::factory()->create());
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        $this->obtainEditingLock($student);
        $url = "/students/{$student->id}/enrollments";
        $post = fn (array $data) => $this->post($url, ['version' => RecordVersion::of($student->fresh()), ...$data]);
        $post(['action' => 'stage', 'stage' => 2, 'started_on' => '2026-01-06'])
            ->assertSessionHasErrors('accounts_settled');
        $post(['action' => 'stage', 'stage' => 2, 'started_on' => '2026-01-06', 'accounts_settled' => 1])
            ->assertSessionHasNoErrors();
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->get("/detail/{$student->id}")->assertOk()->assertSee('Stage to complete')
            ->assertSee('Stage 1')->assertSee('Stage 2');
        $this->assertStringContainsString('Stage 1', $student->fresh()->stageSummary());
        $this->assertStringContainsString('Stage 2', $student->fresh()->stageSummary());
        $this->obtainEditingLock($student);
        $post(['action' => 'graduate', 'effective_on' => '2026-01-10'])->assertSessionHasErrors('enrollment');
        $post(['action' => 'complete', 'stage_enrollment_id' => $second->id, 'effective_on' => '2026-01-10'])
            ->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->ended_on);
        $post(['action' => 'complete', 'stage_enrollment_id' => $first->id, 'effective_on' => '2026-01-12'])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) $student->fresh()->graduated);
        $this->get("/detail/{$student->id}")->assertOk()->assertSee('Graduate learner');
        $this->obtainEditingLock($student);
        $post(['action' => 'graduate', 'effective_on' => '2026-01-13'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $student->fresh()->graduated);
    }

    public function test_individual_stage_corrections_and_undo_preserve_other_active_stages(): void
    {
        config(['billing.concurrent_stages' => true]);
        $this->actingAs(User::factory()->create());
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-01-06']);
        $second = $enrollment->stages()->where('stage', 2)->firstOrFail();
        $this->obtainEditingLock($student);
        $url = "/students/{$student->id}/enrollments";
        $post = fn (array $data) => $this->post($url, ['version' => RecordVersion::of($student->fresh()),
            'enrollment_id' => $enrollment->id, 'reason' => 'Synthetic correction', ...$data]);
        $post(['action' => 'correct', 'stage_enrollment_id' => $second->id,
            'service_days' => 40, 'started_on' => '2026-01-04'])->assertSessionHasNoErrors();
        $post(['action' => 'correct', 'stage_enrollment_id' => $first->id,
            'service_days' => 100, 'started_on' => '2026-01-05'])->assertSessionHasNoErrors();
        $this->assertSame('2026-01-04', $enrollment->fresh()->started_on->toDateString());
        $this->assertSame('2026-01-04', $student->fresh()->start_date->toDateString());
        $post(['action' => 'complete', 'stage_enrollment_id' => $first->id, 'effective_on' => '2026-01-10'])
            ->assertSessionHasNoErrors();
        $post(['action' => 'undo', 'stage_enrollment_id' => $first->id, 'confirm_undo' => 1])
            ->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->ended_on);
        $this->assertNull($second->fresh()->ended_on);
        $post(['action' => 'cancel', 'stage_enrollment_id' => $second->id, 'effective_on' => '2026-01-11'])
            ->assertSessionHasNoErrors();
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame('active', EnrollmentAccounting::statusAt($enrollment->fresh(), '2026-01-11'));
        $post(['action' => 'resume', 'stage_enrollment_id' => $second->id, 'effective_on' => '2026-01-12'])
            ->assertSessionHasNoErrors();
        $this->assertNull($second->fresh()->ended_on);
        $this->assertNull($first->fresh()->ended_on);
    }

    public function test_graduation_workflow_rejects_active_stages_and_accepts_closed_stages(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        $form = FormData::create(['form_type' => 'graduation-initiation', 'student_id' => $student->id,
            'program_id' => $enrollment->program_id, 'enrollment_id' => $enrollment->id,
            'enrollment_key' => 'enrollment:'.$enrollment->id, 'data' => []]);
        $run = new WorkflowRun(['subject_id' => $form->id]);
        try {
            (new MarkGraduated)->reach($run, new WorkflowStep);
            $this->fail('An active stage must prevent graduation.');
        } catch (StepFailed $exception) {
            $this->assertStringContainsString('active stage', $exception->getMessage());
        }
        Enrollments::complete($student->fresh(), '2026-01-10', $first->id);
        $this->assertSame('done', (new MarkGraduated)->reach($run, new WorkflowStep)->kind);
        $this->assertTrue((bool) $student->fresh()->graduated);
    }

    public function test_search_and_invoice_status_filters_handle_overlapping_stages(): void
    {
        config(['billing.concurrent_stages' => true]);
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        Enrollments::startStage($student->fresh(), ['stage' => 3, 'started_on' => '2026-01-06']);
        $third = $enrollment->stages()->where('stage', 3)->firstOrFail();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $third->id, 'datesent' => '2026-01-07', 'amount' => '100.00'])->save();
        Enrollments::complete($student->fresh(), '2026-01-08', $third->id);
        $this->assertSame([$student->id], StudentSearch::query(['program_id' => $enrollment->program_id,
            'stage' => 3, 'stage_start_from' => '2026-01-06', 'stage_start_to' => '2026-01-06'])->pluck('id')->all());
        $this->assertSame([$student->id], StudentSearch::query(['stage' => 1,
            'stage_start_from' => '2026-01-01', 'stage_start_to' => '2026-01-01'])->pluck('id')->all());
        $filters = ['invoicesentbeforedate' => '2026-01-09', 'hidegraduated' => true];
        $this->assertSame('100.00', InvoicesReport::run($filters)['totals']['Total invoiced']);
        Enrollments::complete($student->fresh(), '2026-01-10', $first->id);
        $this->changeStatus($student, ['graduated' => true, 'actualdategrad' => '2026-01-11']);
        $this->assertSame('100.00', InvoicesReport::run($filters)['totals']['Total invoiced']);
        $this->assertSame('0.00', InvoicesReport::run([...$filters, 'invoicesentbeforedate' => '2026-01-12'])['totals']['Total invoiced']);
        $this->changeStatus($student, ['graduated' => false, 'actualdategrad' => null]);
        $this->assertSame('between_stages', EnrollmentAccounting::statusAt($enrollment->fresh(), '2026-11-19'));
        $this->assertSame('100.00', InvoicesReport::run([...$filters, 'invoicesentbeforedate' => '2026-11-19'])['totals']['Total invoiced']);
    }

    public function test_existing_financial_reports_remain_unchanged_when_later_overlap_is_added(): void
    {
        [$student, $enrollment] = $this->stagedLearner();
        $first = $enrollment->stages->first();
        $fee = new StudentTransaction;
        $fee->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $first->id, 'transactiontype' => 'Fee',
            'transactiondate' => '2026-01-02', 'processeddate' => '2026-01-02', 'transactionamount' => '1000.00'])->save();
        $incomeFilters = ['startdate' => '2026-01-01', 'enddate' => '2026-01-11'];
        $cohortFilters = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2027-01-01',
            'transstart' => '2026-01-01', 'dateasof' => '2026-01-11'];
        $before = [AttributableIncome::run($incomeFilters)['calculated'],
            EarnedIncome::run($cohortFilters)['totals'], PrepaysReport::run($cohortFilters)['totals']];
        config(['billing.concurrent_stages' => true]);
        Enrollments::startStage($student->fresh(), ['stage' => 2, 'started_on' => '2026-02-01']);
        $after = [AttributableIncome::run($incomeFilters)['calculated'],
            EarnedIncome::run($cohortFilters)['totals'], PrepaysReport::run($cohortFilters)['totals']];
        $this->assertSame($before, $after);
    }

    private function changeStatus(Student $student, array $changes): void
    {
        DB::transaction(function () use ($student, $changes) {
            $current = $student->fresh();
            $before = $current->getRawOriginal();
            $current->forceFill($changes)->save();
            Enrollments::syncStatus($current, $before);
        });
    }

    private function stagedLearner(): array
    {
        $program = new Program;
        $program->forceFill(['program' => 'Test Full', 'servicedays' => 100])->save();
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'balance' => '0.00'])->save();
        foreach ([1 => 100, 2 => 40, 3 => 100] as $stage => $days) {
            DB::table('program_stage_definitions')->insert(['program_id' => $program->id, 'stage' => $stage, 'service_days' => $days]);
        }
        $enrollment = Enrollments::start($student, ['program_id' => $program->id,
            'mode' => 'staged', 'stage' => 1, 'started_on' => '2026-01-01']);

        return [$student, $enrollment];
    }
}
