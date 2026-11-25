<?php

namespace Tests\Feature;

use App\Billing\AttributableIncome;
use App\Billing\CollectionsReport;
use App\Billing\EarnedIncome;
use App\Billing\InvoicesReport;
use App\Billing\LegacyReportDates;
use App\Billing\PrepaysReport;
use App\Billing\ProgramProgress;
use App\Billing\ReceivablesReport;
use App\Billing\TransactionsReport;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemainingReportsTest extends TestCase
{
    use RefreshDatabase;

    private array $prepayFilters = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2026-02-01',
        'transstart' => '2026-01-01', 'dateasof' => '2026-01-06'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function student(array $data = []): Student
    {
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'last_name' => 'Learner',
            'start_date' => '2026-01-01', 'graddate' => '2026-01-11',
            'balance' => '100.00', 'payment_amount_due' => '10.00', 'next_due_date' => '2026-01-10', ...$data])->save();

        return $student;
    }

    private function transaction(?Student $student, ?string $type, ?string $amount, array $data = []): StudentTransaction
    {
        $row = new StudentTransaction;
        $row->forceFill(['student_id' => $student?->id, 'transactiontype' => $type, 'transactionamount' => $amount,
            'transactiondate' => '2026-01-02', 'processeddate' => '2026-01-02', ...$data])->save();

        return $row;
    }

    private function invoice(?Student $student, ?string $amount, string $date = '2026-01-02'): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student?->id, 'amount' => $amount, 'datesent' => $date])->save();

        return $invoice;
    }

    private function loa(Student $student, ?string $begin, ?string $end): void
    {
        $loa = new LeaveOfAbsence;
        $loa->forceFill(['student_id' => $student->id, 'loa_start' => $begin, 'loa_end' => $end])->save();
    }

    private function csv(string $url): array
    {
        $response = $this->get($url)->assertOk();
        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $response->streamedContent());
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    public function test_financial_report_periods_use_our_date_not_customer_date(): void
    {
        $student = $this->student();
        $this->invoice($student, '100.00');
        $this->transaction($student, 'Fee', '100.00', ['transactiondate' => '2026-03-01', 'processeddate' => '2026-01-02']);
        $this->transaction($student, 'Fee', '999.00', ['transactiondate' => '2026-01-02', 'processeddate' => '2026-01-06']);
        $this->transaction($student, 'Payment', '-20.00', ['transactiondate' => '2026-03-01', 'processeddate' => '2026-01-03']);
        $this->transaction($student, 'Payment', '-999.00', ['transactiondate' => '2026-01-02', 'processeddate' => '2026-01-06']);
        $period = ['startdate' => '2026-01-01', 'enddate' => '2026-01-06'];
        $income = AttributableIncome::run($period);
        $this->assertSame('100.00', $income['calculated']['invoiced']);
        $this->assertSame('20.00', $income['calculated']['paid']);
        $this->assertSame('100.00', EarnedIncome::run($this->prepayFilters)['totals']['invoiced']);
        $prepay = PrepaysReport::run($this->prepayFilters);
        $this->assertSame('100.00', $prepay['totals']['Owed']);
        $this->assertSame('20.00', $prepay['totals']['Paid']);
        $this->assertSame('80.00', ReceivablesReport::run(['enddate' => '2026-01-06'])['total']);
        $this->assertSame(['Fee' => '100.00', 'Payment' => '-20.00'], TransactionsReport::run($period)['totals']);
        $invoice = InvoicesReport::run(['invoicesentafterdate' => '2026-01-01', 'invoicesentbeforedate' => '2026-01-05', 'showreceived' => 1]);
        $this->assertSame('20.00', $invoice['totals']['Total received']);
        $this->assertStringStartsWith('2026-01-03', $invoice['groups'][0]['payments'][0]['date']);
    }

    public function test_dated_financial_reports_reject_missing_our_date(): void
    {
        $student = $this->student();
        $this->invoice($student, '100.00');
        $fee = $this->transaction($student, 'Fee', '100.00', ['processeddate' => null]);
        $period = ['startdate' => '2026-01-01', 'enddate' => '2026-01-06'];
        foreach ([
            '/reports/attributable-income?'.http_build_query($period),
            '/reports/earned-income?'.http_build_query($this->prepayFilters),
            '/reports/prepays?'.http_build_query($this->prepayFilters),
            '/reports/receivables?enddate=2026-01-06',
            '/reports/transactions?'.http_build_query($period),
        ] as $url) {
            $this->get($url)->assertSessionHasErrors('report');
        }
        $fee->forceFill(['processeddate' => '2026-01-02'])->save();
        $this->transaction($student, 'Payment', '-20.00', ['processeddate' => null]);
        $this->get('/reports/invoices?invoicesentbeforedate=2026-01-06&showreceived=1')->assertSessionHasErrors('report');
    }

    public function test_invoice_status_filters_use_effective_date_and_current_status_when_missing(): void
    {
        $student = $this->student(['graduated' => true, 'actualdategrad' => '2026-03-01']);
        $this->invoice($student, '100.00');
        $filter = ['invoicesentbeforedate' => '2026-02-01', 'hidegraduated' => 1];
        $this->assertSame('100.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $student->forceFill(['actualdategrad' => '2026-01-15'])->save();
        $this->assertSame('0.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $student->forceFill(['actualdategrad' => null])->save();
        $this->assertSame('0.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $student->forceFill(['graduated' => false, 'inactive' => true, 'inactive_date' => '2026-03-01'])->save();
        $filter = ['invoicesentbeforedate' => '2026-02-01', 'hideinactive' => 1];
        $this->assertSame('100.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $student->forceFill(['inactive_date' => null])->save();
        $this->assertSame('0.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
    }

    public function test_future_leaves_do_not_change_prepay_accrual(): void
    {
        $student = $this->student();
        $this->transaction($student, 'Fee', '100.00');
        $before = PrepaysReport::run($this->prepayFilters)['totals'];
        $this->loa($student, '2026-02-01', null);
        $this->assertSame($before, PrepaysReport::run($this->prepayFilters)['totals']);
        $this->assertSame('50.00', $before['Prepaid_accrual']);
    }

    public function test_blank_leaves_are_ignored_but_a_missing_start_with_an_end_is_inferred_for_prepays(): void
    {
        $student = $this->student();
        $this->transaction($student, 'Fee', '100.00');
        $before = PrepaysReport::run($this->prepayFilters)['rows'][0];
        $this->loa($student, null, null);
        $this->assertSame($before, PrepaysReport::run($this->prepayFilters)['rows'][0]);
        $this->loa($student, null, '2026-01-03');
        $row = PrepaysReport::run($this->prepayFilters)['rows'][0];
        $this->assertSame('2 days, 0:00:00', $row['loa length']);
        $this->assertSame('3 days, 0:00:00', $row['current']);
    }

    public function test_legacy_inactive_date_uses_latest_eligible_status_or_our_date(): void
    {
        $student = $this->student(['start_date' => '2021-01-01', 'graddate' => '2030-01-01',
            'inactive' => true, 'inactive_date' => null, 'pifdate' => '2021-04-01', 'archived' => true]);
        $this->transaction($student, 'Fee', '100.00', ['transactiondate' => '2021-10-10', 'processeddate' => '2021-03-01']);
        $this->loa($student, '2021-04-02', '2021-04-05');
        $this->assertStringStartsWith('2021-04-05', LegacyReportDates::forStudent($student));
        $student = $student->fresh()->load('program', 'loas');
        $this->assertTrue(ProgramProgress::calculate($student, '2021-04-04')['ratio']->isLessThan(1));
        $this->assertTrue(ProgramProgress::calculate($student, '2021-04-05')['ratio']->isEqualTo(1));

        $this->invoice($student, '100.00', '2021-02-01');
        $filter = ['hideinactive' => 1, 'invoicesentbeforedate' => '2021-04-04'];
        $this->assertSame('100.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $this->assertSame('0.00', InvoicesReport::run([...$filter, 'invoicesentbeforedate' => '2021-04-05'])['totals']['Total invoiced']);

        $period = ['startdate' => '2021-01-01', 'lastpossiblestartdate' => '2022-01-01',
            'transstart' => '2021-01-01', 'dateasof' => '2021-04-05'];
        $this->assertSame('100.00', EarnedIncome::run($period)['totals']['total earned']);
        $this->transaction($student, 'Payment', '-100.00', ['processeddate' => '2021-03-01']);
        $this->assertSame('0.000000', PrepaysReport::run($period)['rows'][0]['prepaid']);
        $this->assertNull($student->fresh()->inactive_date);
    }

    public function test_legacy_inactive_date_stays_unresolved_when_start_or_evidence_is_missing_or_later(): void
    {
        $noStart = $this->student(['start_date' => null, 'inactive' => true, 'pifdate' => '2021-01-01']);
        $noEvidence = $this->student(['start_date' => '2021-01-01', 'inactive' => true, 'graddate' => null]);
        $later = $this->student(['start_date' => '2021-01-01', 'inactive' => true, 'pifdate' => '2021-02-01']);
        $missingDate = $this->student(['start_date' => '2021-01-01', 'inactive' => true, 'pifdate' => '2021-02-01']);
        $this->transaction($later, 'Note', '0.00', ['processeddate' => '2022-01-01']);
        $this->transaction($missingDate, 'Note', '0.00', ['processeddate' => null]);
        foreach ([$noStart, $noEvidence, $later, $missingDate] as $student) {
            $this->assertNull(LegacyReportDates::forStudent($student));
        }
    }

    public function test_graduation_fallback_and_actual_precedence_apply_to_invoice_and_prepays(): void
    {
        $student = $this->student(['graduated' => true, 'graddate' => '2026-01-04']);
        $this->invoice($student, '100.00');
        $this->transaction($student, 'Fee', '100.00');
        $this->transaction($student, 'Payment', '-50.00');
        $filter = ['hidegraduated' => 1, 'invoicesentbeforedate' => '2026-01-03'];
        $this->assertSame('100.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $this->assertSame('0.00', InvoicesReport::run([...$filter, 'invoicesentbeforedate' => '2026-01-04'])['totals']['Total invoiced']);
        $this->assertSame('0.000000', PrepaysReport::run($this->prepayFilters)['rows'][0]['prepaid']);
        $this->assertSame('50.000000', PrepaysReport::run($this->prepayFilters)['rows'][0]['prorataowed']);
        $student->forceFill(['actualdategrad' => '2026-02-01'])->save();
        $this->assertSame('100.00', InvoicesReport::run([...$filter, 'invoicesentbeforedate' => '2026-01-04'])['totals']['Total invoiced']);
        $this->assertSame('100.000000', PrepaysReport::run($this->prepayFilters)['rows'][0]['prorataowed']);
    }

    public function test_legacy_snapshot_inference_excludes_later_enrollments_and_uses_preserved_leaves(): void
    {
        $student = $this->student(['start_date' => '2024-01-01', 'inactive' => false]);
        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'mode' => 'legacy',
            'started_on' => '2021-01-01', 'ended_on' => '2024-01-01', 'legacy_context' => [
                'student' => ['id' => $student->id, 'start_date' => '2021-01-01', 'inactive' => true, 'inactive_date' => null],
                'program' => null, 'loas' => [['loa_start' => '2021-03-01', 'loa_end' => '2021-03-05']],
            ]]);
        $this->transaction($student, 'Payment', '-20.00', ['enrollment_id' => $enrollment->id, 'processeddate' => '2021-02-01']);
        $this->transaction($student, 'Payment', '-10.00', ['processeddate' => '2024-01-01']);
        $this->assertStringStartsWith('2021-03-05', LegacyReportDates::forSnapshot($student, $enrollment->id));
        $invoice = $this->invoice($student, '100.00', '2021-01-01');
        $invoice->forceFill(['enrollment_id' => $enrollment->id])->save();
        $filters = ['hideinactive' => 1, 'invoicesentbeforedate' => '2021-03-04'];
        $this->assertSame('100.00', InvoicesReport::run($filters)['totals']['Total invoiced']);
        $this->assertSame('0.00', InvoicesReport::run([...$filters, 'invoicesentbeforedate' => '2021-03-05'])['totals']['Total invoiced']);
    }

    public function test_converted_legacy_invoice_uses_current_learner_status_when_snapshot_date_is_missing(): void
    {
        $student = $this->student(['graduated' => false]);
        $enrollment = DB::table('student_enrollments')->insertGetId([
            'student_id' => $student->id, 'mode' => 'legacy',
            'legacy_context' => json_encode(['student' => ['graduated' => true, 'actualdategrad' => null]], JSON_THROW_ON_ERROR),
        ]);
        $invoice = $this->invoice($student, '100.00');
        $invoice->forceFill(['enrollment_id' => $enrollment])->save();
        $filter = ['invoicesentbeforedate' => '2026-02-01', 'hidegraduated' => 1];
        $this->assertSame('100.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $student->forceFill(['graduated' => true])->save();
        $this->assertSame('0.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
        $student->forceFill(['graduated' => false])->save();
        DB::table('student_enrollments')->where('id', $enrollment)->update([
            'legacy_context' => json_encode(['student' => ['graduated' => true, 'actualdategrad' => '2026-01-15']], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame('0.00', InvoicesReport::run($filter)['totals']['Total invoiced']);
    }

    public function test_collections_filters_next_payment_not_balance_and_hides_inactive_correctly(): void
    {
        $active = $this->student(['middle_name' => 'Middle', 'balance' => '-10.01', 'payment_amount_due' => '0.01']);
        $inactive = $this->student(['inactive' => true, 'balance' => '20.02', 'payment_amount_due' => '0.01']);
        $this->student(['graduated' => true]);
        $this->student(['start_date' => '2025-12-31']);
        $this->student(['start_date' => '2026-02-01']);
        $this->student(['next_due_date' => '2026-01-11']);
        $this->student(['payment_amount_due' => '0.02']);
        $filters = ['enrolldate' => '2026-01-01', 'enrolledbeforedate' => '2026-02-01',
            'next_due_date' => '2026-01-11', 'collectionsmin' => '0.01', 'collectionsmax' => '0.01'];
        $report = CollectionsReport::run($filters);
        $this->assertSame([$active->id, $inactive->id], array_column($report['rows'], 'id'));
        $this->assertSame('10.01', $report['total']);
        $this->assertSame(['True', 'False'], array_column($report['rows'], 'active'));
        $this->assertSame('SyntheticMiddle Learner', $report['rows'][0]['name']);
        $hidden = CollectionsReport::run([...$filters, 'hideinactive' => 1]);
        $this->assertSame([$active->id], array_column($hidden['rows'], 'id'));
        $this->assertSame('-10.01', $hidden['total']);
        $this->get('/reports/collections?'.http_build_query([...$filters, 'hideinactive' => 1]))
            ->assertOk()->assertSee('TOTAL -10.01')->assertSee('Synthetic Middle Learner');
    }

    public function test_collections_csv_signed_zero_nulls_and_missing_balance_error(): void
    {
        $student = $this->student(['first_name' => '=Formula', 'last_name' => null, 'start_date' => null,
            'next_due_date' => null, 'payment_amount_due' => null, 'balance' => '0.00']);
        $rows = $this->csv('/reports/collections?format=csv');
        $index = array_search(CollectionsReport::HEADERS, $rows, true);
        $this->assertSame([(string) $student->id, 'True', "'=Formula", '', '', '', '0.00', 'Unknown program (history incomplete)'], $rows[$index + 1]);
        $student->forceFill(['balance' => null])->save();
        $this->get('/reports/collections')->assertSessionHasErrors('report');
    }

    public function test_receivables_uses_our_date_and_preserves_signed_total_when_hiding(): void
    {
        $a = $this->student();
        $b = $this->student();
        $zero = $this->student();
        $old = $this->student(['start_date' => '2025-12-31']);
        $this->transaction($a, 'Fee', '10.01');
        $this->transaction($b, 'Payment', '-2.00');
        $this->transaction($zero, null, null);
        $this->transaction($a, 'Fee', '100.00', ['processeddate' => '2026-02-01']);
        $this->transaction($a, 'Fee', '200.00', ['transactiondate' => '2026-02-01']);
        $this->transaction($a, 'Fee', '400.00', ['processeddate' => '2026-02-01']);
        $this->transaction($old, 'Fee', '800.00');
        $this->transaction(null, 'Note', '1.00');
        $filters = ['startdate' => '2026-01-01', 'enddate' => '2026-02-01'];
        $report = ReceivablesReport::run($filters);
        $this->assertSame('208.01', $report['total']);
        $this->assertCount(3, $report['rows']);
        $filtered = ReceivablesReport::run([...$filters, 'zeros' => 'hidezero']);
        $this->assertSame([$a->id], array_column($filtered['rows'], 'id'));
        $this->assertSame('208.01', $filtered['total']);
        $all = ReceivablesReport::run(['enddate' => '2026-02-01']);
        $this->assertSame('1009.01', $all['total']);
        $this->assertSame('UNKNOWN', $all['rows'][4]['name']);
        $this->assertNull($all['rows'][4]['id']);
        $rows = $this->csv('/reports/receivables?'.http_build_query([...$filters, 'zeros' => 'hidezero', 'format' => 'csv']));
        $this->assertContains(['TOTAL', '208.01'], $rows);
        $index = array_search(ReceivablesReport::HEADERS, $rows, true);
        $this->assertSame([(string) $a->id, 'Synthetic Learner', '210.01', 'Unknown program (history incomplete)'], $rows[$index + 1]);
    }

    public function test_invoice_abs_totals_mixed_date_bounds_substrings_and_group_overpayment(): void
    {
        $a = $this->student();
        $b = $this->student();
        $this->invoice($a, '-10.01');
        $this->invoice($a, '1.00', '2026-02-01');
        $this->invoice($a, '100.00', '2026-02-01 00:00:01');
        $this->invoice($b, '4.00');
        $p = $this->transaction($a, 'Payment', '-15.00', ['transactiondate' => '2026-03-01']);
        $this->transaction($b, 'Credits', '-1.00');
        $this->transaction($a, 'Check', '-100.00');
        $this->transaction($a, 'Refund', '100.00');
        $this->transaction($a, 'Payment', '-200.00', ['transactiondate' => '2025-12-31']);
        $this->transaction($a, 'Payment', '-400.00', ['processeddate' => '2026-02-01 00:00:01']);
        $filters = ['invoicesentafterdate' => '2026-01-01', 'invoicesentbeforedate' => '2026-02-01', 'showreceived' => 1];
        $report = InvoicesReport::run($filters);
        $this->assertSame(['Total invoiced' => '15.01', 'Total received' => '216.00',
            'Total prepayments' => '203.99', 'Total outstanding' => '-200.99'], $report['totals']);
        $this->assertSame('0.00', $report['groups'][0]['outstanding']);
        $this->assertSame('3.00', $report['groups'][1]['outstanding']);
        $this->assertSame($p->id, $report['groups'][0]['payments'][0]['id']);
        $this->assertSame('-10.01', $report['groups'][0]['invoices'][0]['amount']);
        $without = InvoicesReport::run([...$filters, 'showreceived' => 0]);
        $this->assertSame('15.01', $without['totals']['Total outstanding']);
        $this->assertSame('0.00', $without['groups'][0]['outstanding']);
        $rows = $this->csv('/reports/invoices?'.http_build_query([...$filters, 'format' => 'csv']));
        $this->assertContains(InvoicesReport::HEADERS, $rows);
        $this->assertContains(['Total outstanding', '-200.99'], $rows);
        $this->assertContains(['', '', '', '', '', '', 'TOTAL PREPAYMENT', '', '', '', '203.99'], $rows);
        $this->get('/reports/invoices?'.http_build_query($filters))->assertOk()->assertSee('TOTAL PREPAYMENT');
    }

    public function test_invoice_enrollment_status_null_amounts_metadata_only_and_lexical_groups(): void
    {
        $active = $this->student(['id' => 2]);
        $other = $this->student(['id' => 10]);
        $grad = $this->student(['graduated' => true]);
        $inactive = $this->student(['inactive' => true]);
        $old = $this->student(['start_date' => '2025-01-01']);
        foreach ([$active, $other, $grad, $inactive, $old] as $student) {
            $this->invoice($student, '0.01')->forceFill(['invoice_file' => 'DO NOT LOAD PDF'])->save();
        }
        DB::enableQueryLog();
        $report = InvoicesReport::run(['studentenrolleddate' => '2026-01-01', 'hideinactive' => 1, 'hidegraduated' => 1]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([$other->id, $active->id], array_column($report['groups'], 'id'));
        $this->assertStringNotContainsString('invoice_file', $queries[0]['query']);
        $this->assertCount(1, $queries);
        $this->invoice(null, '0.01');
        $this->assertNull(InvoicesReport::run([])['groups'][5]['id']);
        $this->invoice($active, null);
        $this->get('/reports/invoices')->assertSessionHasErrors('report');
    }

    public function test_prepays_signed_net_categories_refunds_transaction_window_and_derived_precision(): void
    {
        $student = $this->student();
        foreach ([['Fee', '120.00'], ['Fee Adjustment', '-20.00'], ['Discount', '-10.00'], ['Credit', '2.00'],
            ['Payment', '-80.00'], ['Check', '20.00'], ['Refund', '-10.00'], ['Reversal', '5.00'], ['Write off', '-5.00'],
            ['Discounts', '-100.00'], ['Adjustments', '-100.00']] as [$type, $amount]) {
            $this->transaction($student, $type, $amount);
        }
        $this->transaction($student, 'Fee', '1000.00', ['processeddate' => '2025-12-31 23:59:59']);
        $this->transaction($student, 'Fee', '2000.00', ['processeddate' => '2026-01-06']);
        $report = PrepaysReport::run($this->prepayFilters);
        $this->assertSame(['Owed' => '100.00', 'Discounts' => '8.00', 'Paid' => '95.00',
            'Prepaid' => '54.00', 'Prorata Owed' => '46.00', 'Prepaid_accrual' => '46.00',
            'Prorata Owed Accrual' => '38.00'], $report['totals']);
        $this->assertSame('54.000000', $report['rows'][0]['prepaid']);
        $this->assertSame('5 days, 0:00:00', $report['rows'][0]['current']);
        $this->assertSame(PrepaysReport::HEADERS, array_keys($report['rows'][0]));
        $rows = $this->csv('/reports/prepays?'.http_build_query([...$this->prepayFilters, 'format' => 'csv']));
        $this->assertContains([PrepaysReport::NOTICE], $rows);
        $index = array_search(PrepaysReport::HEADERS, $rows, true);
        $this->assertCount(23, $rows[$index + 1]);
        $this->assertSame('54.000000', $rows[$index + 1][15]);
        $this->get('/reports/prepays?'.http_build_query($this->prepayFilters))->assertOk()->assertSee(PrepaysReport::NOTICE);
    }

    public function test_prepays_old_loa_rules_completed_dates_skip_positive_and_missing_grad(): void
    {
        $student = $this->student();
        $this->transaction($student, 'Fee', '100.00');
        $this->transaction($student, 'Payment', '-100.00');
        $this->loa($student, '2026-01-02', '2026-01-03');
        $this->loa($student, null, null);
        $report = PrepaysReport::run($this->prepayFilters);
        $this->assertSame('1 day, 0:00:00', $report['rows'][0]['loa length']);
        $this->assertSame('4 days, 0:00:00', $report['rows'][0]['current']);
        $this->assertSame('60.000000', $report['rows'][0]['prepaid']);
        $student->forceFill(['sus' => false, 'sus_date' => '2026-01-06'])->save();
        $this->assertSame('0.000000', PrepaysReport::run($this->prepayFilters)['rows'][0]['prepaid']);
        $this->assertCount(0, PrepaysReport::run([...$this->prepayFilters, 'skipzero' => 'yes'])['rows']);
        $missing = $this->student(['graddate' => null]);
        $this->transaction($missing, 'Fee', '100.00');
        $this->transaction($missing, 'Payment', '-200.00');
        $row = PrepaysReport::run($this->prepayFilters)['rows'][1];
        $this->assertSame('2026-01-06 00:00:00', $row['grad']);
        $this->assertSame('', $row['graddate']);
        $this->assertSame('100.000000', $row['prepaid']);
        $this->assertNull($missing->fresh()->graddate);
    }

    public function test_prepays_expected_date_length_zero_fallback_fraction_rounding_and_sorting(): void
    {
        $a = $this->student(['graddate' => '2026-01-04', 'last_name' => 'Z']);
        $b = $this->student(['graddate' => '2026-01-04', 'last_name' => null]);
        foreach ([$a, $b] as $student) {
            $this->transaction($student, 'Fee', '0.01', ['processeddate' => '2026-01-01']);
            $this->transaction($student, 'Payment', '-1.00', ['processeddate' => '2026-01-01']);
        }
        $filters = [...$this->prepayFilters, 'dateasof' => '2026-01-02', 'sort' => 'Last Name'];
        $report = PrepaysReport::run($filters);
        $this->assertSame([(string) $b->id, (string) $a->id], array_column($report['rows'], 'id'));
        $this->assertSame(['0.003333', '0.003333'], array_column($report['rows'], 'prorataowed'));
        $this->assertSame('0.01', $report['totals']['Prorata Owed']);
        $a->forceFill(['graddate' => '2026-01-01'])->save();
        $row = PrepaysReport::run([...$filters, 'sort' => 'ID'])['rows'][0];
        $this->assertSame('547 days, 0:00:00', $row['length']);
        $this->assertSame('0.000000', $row['prepaid']);
        $b->forceFill(['graddate' => '2026-01-04', 'start_date' => '2026-01-05'])->save();
        $this->get('/reports/prepays?'.http_build_query($filters))->assertSessionHasErrors('report');
    }

    public function test_optional_filters_null_inputs_errors_empty_results_and_access(): void
    {
        foreach (['collections', 'receivables', 'invoices'] as $kind) {
            $this->get('/reports/'.$kind)->assertOk();
            $this->get('/reports/'.$kind.'?format=bad')->assertSessionHasErrors('format');
        }
        $this->get('/reports/prepays')->assertSessionHasErrors(['startdate', 'lastpossiblestartdate', 'transstart', 'dateasof']);
        $this->get('/reports/collections?collectionsmin=0.001')->assertSessionHasErrors('collectionsmin');
        $this->get('/reports/collections?collectionsmin=10&collectionsmax=2')->assertSessionHasErrors('collectionsmax');
        $this->get('/reports/invoices?invoicesentafterdate=2026-02-01&invoicesentbeforedate=2026-01-01')->assertSessionHasErrors('invoicesentbeforedate');
        $student = $this->student();
        $this->transaction($student, 'Fee', null);
        $this->get('/reports/prepays?'.http_build_query($this->prepayFilters))->assertSessionHasErrors('report');
        $this->assertSame('100.00', $student->fresh()->balance);
        auth()->logout();
        foreach (['collections', 'receivables', 'invoices', 'prepays'] as $kind) {
            $this->get('/reports/'.$kind)->assertRedirect('/login');
        }
    }

    public function test_prepays_future_loa_refund_spelling_and_nonmutating_batched_reads(): void
    {
        $student = $this->student();
        $this->transaction($student, 'Fee', '100.00');
        $this->transaction($student, 'Payment', '-50.00');
        $this->transaction($student, 'refund', '10.00');
        $this->loa($student, '2026-01-08', null);
        DB::enableQueryLog();
        $report = PrepaysReport::run($this->prepayFilters);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(7, $queries);
        $this->assertSame('0:00:00', $report['rows'][0]['loa length']);
        $this->assertSame('5 days, 0:00:00', $report['rows'][0]['current']);
        $this->assertSame('60.00', $report['rows'][0]['paid']);
        $this->assertSame('50.000000', $report['rows'][0]['prorataowed']);
        $this->assertSame('10.000000', $report['rows'][0]['prepaid']);
        $this->assertDatabaseCount('studenttransactions', 3);
        $this->assertDatabaseCount('loas', 1);
        $this->assertSame('100.00', $student->fresh()->balance);
    }

    public function test_receivables_unbounded_csv_includes_unknown_null_amount_and_note_escaping(): void
    {
        $student = $this->student(['first_name' => '=Formula', 'last_name' => '<script>bad</script>']);
        $this->transaction($student, 'Fee', '0.00', ['transactiondate' => null, 'processeddate' => null]);
        $this->transaction(null, null, '0.01');
        $rows = $this->csv('/reports/receivables?format=csv');
        $this->assertContains(['TOTAL', '0.01'], $rows);
        $this->assertContains(['', 'UNKNOWN', '0.01', 'Unknown program'], $rows);
        $this->assertContains([(string) $student->id, "'=Formula <script>bad</script>", '0.00', 'Unknown program (history incomplete)'], $rows);
        $this->get('/reports/receivables')->assertOk()->assertSee('&lt;script&gt;bad&lt;/script&gt;', false)
            ->assertDontSee('<script>bad</script>', false);
    }
}
