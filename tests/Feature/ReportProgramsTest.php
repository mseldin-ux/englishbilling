<?php

namespace Tests\Feature;

use App\Billing\AttributableIncome;
use App\Billing\CollectionsReport;
use App\Billing\EarnedIncome;
use App\Billing\ExpectedIncome;
use App\Billing\InvoicesReport;
use App\Billing\PrepaysReport;
use App\Billing\ReceivablesReport;
use App\Billing\ReportPrograms;
use App\Billing\StageBillingAllocations;
use App\Billing\TransactionsReport;
use App\Models\AnticipatedPayment;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentStageEnrollment;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportProgramsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_existing_reports_exclude_unenrolled_learners_and_their_financial_activity(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 20));
        [$early, $elementary, $stage, $learner] = $this->fixtures();
        Student::where('first_name', 'Other')->update(['hasenrolled' => false]);
        $period = ['startdate' => '2026-01-01', 'enddate' => '2026-03-01', 'program_id' => $early->id];
        $cohort = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2026-03-01',
            'dateasof' => '2026-01-20', 'program_id' => $early->id];
        $reports = fn () => [
            TransactionsReport::run($period), ExpectedIncome::run($period),
            InvoicesReport::run(['program_id' => $early->id]),
            AttributableIncome::run($period), CollectionsReport::run(['program_id' => $early->id]),
            ReceivablesReport::run(['enddate' => '2026-01-21', 'program_id' => $early->id]),
            EarnedIncome::run($cohort), PrepaysReport::run([...$cohort, 'transstart' => '2026-01-01',
                'dateasof' => '2026-02-20', 'program_id' => $elementary->id]),
        ];
        foreach ($reports() as $report) {
            $this->assertNotEmpty($report['rows'] ?? $report['groups']);
        }
        $learner->forceFill(['hasenrolled' => false])->save();
        foreach ($reports() as $report) {
            $this->assertEmpty($report['rows'] ?? $report['groups']);
        }
        $this->assertSame([], TransactionsReport::run($period)['totals']);
        $this->assertSame('0.00', ExpectedIncome::run($period)['total']);
        $this->assertSame('0.00', ReceivablesReport::run(['enddate' => '2026-01-21'])['total']);
        $this->actingAs(User::factory()->create());
        foreach ([
            '/reports/attributable-income?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/expected-income?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/transactions?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/earned-income?startdate=2026-01-01&lastpossiblestartdate=2026-03-01&dateasof=2026-01-20',
            '/reports/collections?',
            '/reports/receivables?enddate=2026-01-21',
            '/reports/invoices?invoicesentafterdate=2026-01-01&invoicesentbeforedate=2026-03-01',
            '/reports/prepays?startdate=2026-01-01&lastpossiblestartdate=2026-03-01&transstart=2026-01-01&dateasof=2026-02-20',
        ] as $url) {
            $separator = str_ends_with($url, '?') ? '' : '&';
            $this->get($url)->assertOk()->assertDontSee('Synthetic');
            $csv = $this->get($url.$separator.'format=csv')->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Synthetic', $csv);
        }
        $learner->forceFill(['hasenrolled' => true])->save();
        foreach ($reports() as $report) {
            $this->assertNotEmpty($report['rows'] ?? $report['groups']);
        }
    }

    public function test_all_reports_filter_by_historical_overall_program_and_keep_learner_activity(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 20));
        [$early, $elementary, $stage, $learner] = $this->fixtures();
        $period = ['startdate' => '2026-01-01', 'enddate' => '2026-03-01', 'program_id' => $early->id];
        $transactions = TransactionsReport::run($period);
        $this->assertSame([$learner->id, $learner->id], array_column($transactions['rows'], 'student_id'));
        $this->assertSame('150.00', $transactions['totals']['Fee']);
        $this->assertSame('25.00', ExpectedIncome::run($period)['total']);
        $invoices = InvoicesReport::run(['invoicesentafterdate' => '2026-01-01',
            'invoicesentbeforedate' => '2026-03-01', 'program_id' => $early->id]);
        $this->assertSame([$learner->id], array_column($invoices['groups'], 'id'));
        $this->assertSame('100.00', $invoices['totals']['Total invoiced']);
        $attributable = AttributableIncome::run($period);
        $this->assertSame('150.00', $attributable['calculated']['invoiced']);
        $this->assertSame('Early Childhood; Elementary', $attributable['rows'][0]['program']);
        $collections = CollectionsReport::run(['program_id' => $early->id]);
        $this->assertSame([$learner->id], array_column($collections['rows'], 'id'));
        $this->assertSame('Early Childhood', $collections['rows'][0]['program']);
        $receivables = ReceivablesReport::run(['enddate' => '2026-01-21', 'program_id' => $early->id]);
        $this->assertSame([$learner->id], array_column($receivables['rows'], 'id'));
        $this->assertSame('100.00', $receivables['total']);
        $this->assertSame('Early Childhood', $receivables['rows'][0]['program']);
        $cohort = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2026-03-01',
            'dateasof' => '2026-01-20', 'program_id' => $early->id];
        $earned = EarnedIncome::run($cohort);
        $this->assertSame([(string) $learner->id], array_column($earned['rows'], 'id'));
        $this->assertSame('Early Childhood', $earned['rows'][0]['program']);
        $prepays = PrepaysReport::run([...$cohort, 'transstart' => '2026-01-01',
            'dateasof' => '2026-02-20', 'program_id' => $elementary->id]);
        $this->assertSame((string) $learner->id, $prepays['rows'][0]['id']);
        $this->assertSame('Elementary', $prepays['rows'][0]['program']);
        $this->assertSame(['Fee' => '350.00'], TransactionsReport::run(
            ['startdate' => '2026-01-01', 'enddate' => '2026-03-01']
        )['totals']);

        $this->actingAs(User::factory()->create());
        $page = $this->get('/reports')->assertOk()->assertSee('Early Childhood')->assertSee('Elementary');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertCount(8, $xpath->query('//main//form//select[@name="program_id"]'));
        $this->assertCount(0, $xpath->query('//main//form//select[@name="program_id"]/option[@value="'.$stage->id.'"]'));
        $this->get('/reports/transactions?program_id='.$stage->id)->assertSessionHasErrors('program_id');
        $this->get('/reports/transactions?program_id='.$early->id.'&startdate=2026-01-01&enddate=2026-03-01')
            ->assertOk()->assertSee('150.00');
        foreach ([
            '/reports/attributable-income?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/expected-income?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/transactions?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/earned-income?startdate=2026-01-01&lastpossiblestartdate=2026-03-01&dateasof=2026-01-20',
            '/reports/collections?',
            '/reports/receivables?enddate=2026-01-21',
            '/reports/invoices?invoicesentafterdate=2026-01-01&invoicesentbeforedate=2026-03-01',
            '/reports/prepays?startdate=2026-01-01&lastpossiblestartdate=2026-03-01&transstart=2026-01-01&dateasof=2026-02-20',
        ] as $url) {
            $programId = str_contains($url, '/prepays?') ? $elementary->id : $early->id;
            $separator = str_ends_with($url, '?') ? '' : '&';
            $this->get($url.$separator.'program_id='.$programId)->assertOk();
            $this->get($url.$separator.'program_id='.$programId.'&format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $unfiltered = $this->get($url)->assertOk()->viewData('report');
            $allPrograms = $this->get($url.$separator.'program_id=')->assertOk()->viewData('report');
            unset($unfiltered['filters'], $allPrograms['filters']);
            $this->assertSame($unfiltered, $allPrograms);
        }
        foreach ([
            '/reports/attributable-income?startdate=2026-01-01&enddate=2026-03-01',
            '/reports/earned-income?startdate=2026-01-01&lastpossiblestartdate=2026-03-01&dateasof=2026-01-20',
            '/reports/collections?',
            '/reports/receivables?enddate=2026-01-21',
            '/reports/prepays?startdate=2026-01-01&lastpossiblestartdate=2026-03-01&transstart=2026-01-01&dateasof=2026-02-20',
        ] as $url) {
            $separator = str_ends_with($url, '?') ? '' : '&';
            $programName = str_contains($url, '/prepays?') ? 'Elementary' : 'Early Childhood';
            $this->get($url)->assertOk()->assertSee('program')->assertSee($programName);
            $csv = $this->get($url.$separator.'format=csv')->assertOk()->streamedContent();
            $this->assertStringContainsString($programName, $csv);
            $this->assertStringContainsString('program', $csv);
        }
    }

    public function test_program_period_boundaries_and_incomplete_legacy_history(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 15));
        [$early, $elementary, $stage, $learner] = $this->fixtures();
        $legacy = new Student;
        $legacy->forceFill(['first_name' => 'Legacy', 'program_id' => $stage->id,
            'start_date' => '2026-01-01', 'balance' => '10.00'])->save();
        $report = CollectionsReport::run(['program_id' => $early->id]);
        $this->assertSame([$legacy->id], array_column($report['rows'], 'id'));
        $this->assertSame('Early Childhood (history incomplete)', $report['rows'][0]['program']);
        $this->assertContains($learner->id, array_column(CollectionsReport::run(['program_id' => $elementary->id])['rows'], 'id'));

        $jan = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-02-01']);
        $this->assertSame('Early Childhood', collect($jan['rows'])->firstWhere('id', (string) $learner->id)['program']);
        $feb = AttributableIncome::run(['startdate' => '2026-02-02', 'enddate' => '2026-03-01']);
        $this->assertSame('Elementary', $feb['rows'][0]['program']);

        $legacy->forceFill(['wd' => true, 'wd_date' => '2026-02-01'])->save();
        $this->assertSame([], CollectionsReport::run(['program_id' => $early->id])['rows']);
    }

    public function test_overlapping_stages_produce_one_program_and_do_not_duplicate_allocated_amounts(): void
    {
        config(['billing.concurrent_stages' => true]);
        $program = new Program;
        $program->forceFill(['program' => 'Early Childhood', 'servicedays' => 100])->save();
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'program_id' => $program->id,
            'start_date' => '2026-01-01', 'balance' => '150.00'])->save();
        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'program_id' => $program->id,
            'mode' => 'staged', 'started_on' => '2026-01-01']);
        $student->forceFill(['current_enrollment_id' => $enrollment->id])->save();
        $first = StudentStageEnrollment::create(['enrollment_id' => $enrollment->id, 'stage' => 1,
            'started_on' => '2026-01-01', 'service_days' => 100]);
        $second = StudentStageEnrollment::create(['enrollment_id' => $enrollment->id, 'stage' => 2,
            'started_on' => '2026-01-10', 'service_days' => 40]);
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment->id,
            'stage_enrollment_id' => $first->id, 'transactiondate' => '2026-01-11',
            'processeddate' => '2026-01-11', 'transactiontype' => 'Fee', 'transactionamount' => '150.00'])->save();
        StageBillingAllocations::replace($student, $transaction, [
            ['stage_enrollment_id' => $first->id, 'amount' => '100.00'],
            ['stage_enrollment_id' => $second->id, 'amount' => '50.00'],
        ]);
        $filters = ['startdate' => '2026-01-01', 'enddate' => '2026-01-21'];
        $unfiltered = AttributableIncome::run($filters);
        $filtered = AttributableIncome::run([...$filters, 'program_id' => $program->id]);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame('Early Childhood', $filtered['rows'][0]['program']);
        $this->assertSame('150.00', $filtered['calculated']['invoiced']);
        $this->assertSame($unfiltered['calculated'], $filtered['calculated']);
        $this->assertSame('150.00', TransactionsReport::run([...$filters, 'program_id' => $program->id])['totals']['Fee']);
    }

    public function test_preserved_legacy_status_dates_limit_program_membership(): void
    {
        $this->travelTo(now()->setDate(2026, 2, 15));
        [$early, , , $learner] = $this->fixtures();
        $legacy = StudentEnrollment::create(['student_id' => $learner->id, 'program_id' => $early->id,
            'mode' => 'legacy', 'started_on' => '2025-01-01', 'ended_on' => '2026-03-01',
            'legacy_context' => ['student' => ['wd' => true, 'wd_date' => '2026-01-31']]]);
        $this->assertSame([], CollectionsReport::run(['program_id' => $early->id])['rows']);
        $legacy->forceFill(['legacy_context' => ['student' => ['wd' => false, 'wd_date' => '2026-01-31']]])->save();
        $report = CollectionsReport::run(['program_id' => $early->id]);
        $this->assertSame([$learner->id], array_column($report['rows'], 'id'));
        $this->assertSame('Early Childhood (history incomplete); Elementary', $report['rows'][0]['program']);
    }

    public function test_legacy_and_snapshot_program_dates_use_graduation_and_inactive_fallbacks(): void
    {
        $program = new Program;
        $program->forceFill(['program' => 'Legacy', 'servicedays' => 100])->save();
        $student = new Student;
        $student->forceFill(['program_id' => $program->id, 'start_date' => '2021-01-01',
            'graduated' => true, 'graddate' => '2021-03-01'])->save();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'datesent' => '2021-04-15', 'amount' => '100.00'])->save();
        $period = ['invoicesentafterdate' => '2021-04-01', 'invoicesentbeforedate' => '2021-04-30'];
        $this->assertSame([], ReportPrograms::labels([$student->id], 'invoices', $period));
        $this->assertSame([], InvoicesReport::run([...$period, 'program_id' => $program->id])['groups']);
        $student->forceFill(['actualdategrad' => '2021-05-01'])->save();
        $this->assertArrayHasKey($student->id, ReportPrograms::labels([$student->id], 'invoices', $period));
        $this->assertCount(1, InvoicesReport::run([...$period, 'program_id' => $program->id])['groups']);
        $student->forceFill(['graduated' => false, 'actualdategrad' => null, 'inactive' => true, 'pifdate' => '2021-03-01'])->save();
        $this->assertSame([], ReportPrograms::labels([$student->id], 'invoices', $period));

        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'program_id' => $program->id,
            'mode' => 'legacy', 'started_on' => '2021-01-01', 'ended_on' => '2022-01-01',
            'legacy_context' => ['student' => ['start_date' => '2021-01-01', 'graduated' => true,
                'graddate' => '2021-03-01'], 'loas' => []]]);
        $student->forceFill(['current_enrollment_id' => $enrollment->id])->save();
        $invoice->forceFill(['enrollment_id' => $enrollment->id])->save();
        $this->assertSame([], ReportPrograms::labels([$student->id], 'invoices', $period));
        $this->assertSame([], InvoicesReport::run([...$period, 'program_id' => $program->id])['groups']);
        $enrollment->forceFill(['legacy_context' => ['student' => ['start_date' => '2021-01-01',
            'inactive' => true, 'pifdate' => '2021-03-01'], 'loas' => []]])->save();
        $this->assertSame([], ReportPrograms::labels([$student->id], 'invoices', $period));
        $enrollment->forceFill(['legacy_context' => ['student' => ['start_date' => '2021-01-01',
            'inactive' => true, 'pifdate' => '2021-05-01'], 'loas' => []]])->save();
        $this->assertArrayHasKey($student->id, ReportPrograms::labels([$student->id], 'invoices', $period));
        $this->assertCount(1, InvoicesReport::run([...$period, 'program_id' => $program->id])['groups']);
    }

    public function test_program_column_does_not_replace_a_transaction_type_of_the_same_name(): void
    {
        [$early, , , $learner] = $this->fixtures();
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $learner->id, 'enrollment_id' => $learner->current_enrollment_id,
            'transactiondate' => '2026-02-15', 'processeddate' => '2026-02-15',
            'transactiontype' => 'program', 'transactionamount' => '4.00'])->save();
        $report = AttributableIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-03-01']);
        $row = collect($report['rows'])->firstWhere('id', (string) $learner->id);
        $this->assertSame('4.00', $row['program']);
        $this->assertSame('Early Childhood; Elementary', $row['program (enrollment)']);
        $this->assertContains('program (enrollment)', $report['csvFields']);
        $this->assertSame('4.00', $report['types']['program']);
    }

    private function fixtures(): array
    {
        $early = new Program;
        $early->forceFill(['program' => 'Early Childhood', 'servicedays' => 100])->save();
        $elementary = new Program;
        $elementary->forceFill(['program' => 'Elementary', 'servicedays' => 100])->save();
        $stage = new Program;
        $stage->forceFill(['program' => 'Online stage', 'staged' => true, 'certificate_program_id' => $early->id, 'servicedays' => 50])->save();
        $learner = new Student;
        $learner->forceFill(['first_name' => 'Synthetic', 'last_name' => 'Learner', 'program_id' => $elementary->id,
            'start_date' => '2026-02-02', 'balance' => '150.00'])->save();
        $first = StudentEnrollment::create(['student_id' => $learner->id, 'program_id' => $early->id, 'mode' => 'whole',
            'started_on' => '2026-01-01', 'ended_on' => '2026-02-01', 'service_days' => 100, 'status' => 'completed']);
        $second = StudentEnrollment::create(['student_id' => $learner->id, 'program_id' => $elementary->id, 'mode' => 'whole',
            'started_on' => '2026-02-02', 'service_days' => 100]);
        $learner->forceFill(['current_enrollment_id' => $second->id])->save();
        $other = new Student;
        $other->forceFill(['first_name' => 'Other', 'program_id' => $elementary->id,
            'start_date' => '2026-01-01', 'balance' => '200.00'])->save();
        foreach ([[$learner, $first, '2026-01-15', '100.00'], [$learner, $second, '2026-02-15', '50.00'], [$other, null, '2026-01-15', '200.00']] as [$student, $enrollment, $date, $amount]) {
            $transaction = new StudentTransaction;
            $transaction->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment?->id,
                'transactiondate' => $date, 'processeddate' => $date, 'transactiontype' => 'Fee', 'transactionamount' => $amount])->save();
        }
        foreach ([[$learner, $first, '100.00'], [$other, null, '200.00']] as [$student, $enrollment, $amount]) {
            $invoice = new Invoice;
            $invoice->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment?->id,
                'datesent' => '2026-01-15', 'amount' => $amount])->save();
            $payment = new AnticipatedPayment;
            $payment->forceFill(['student_id' => $student->id, 'enrollment_id' => $enrollment?->id,
                'paymentduedate' => '2026-01-15', 'paymentamount' => $student->id === $learner->id ? '25.00' : '30.00'])->save();
        }

        return [$early, $elementary, $stage, $learner];
    }
}
