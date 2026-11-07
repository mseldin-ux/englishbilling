<?php

namespace Tests\Feature;

use App\Billing\CollectionsReport;
use App\Billing\InvoicesReport;
use App\Billing\PrepaysReport;
use App\Billing\ReceivablesReport;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\Student;
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
        $this->assertSame([(string) $student->id, 'True', "'=Formula", '', '', '', '0.00'], $rows[$index + 1]);
        $student->forceFill(['balance' => null])->save();
        $this->get('/reports/collections')->assertSessionHasErrors('report');
    }

    public function test_receivables_requires_both_dates_before_cutoff_and_preserves_signed_total_when_hiding(): void
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
        $this->transaction($a, 'Fee', '400.00', ['processeddate' => null]);
        $this->transaction($old, 'Fee', '800.00');
        $this->transaction(null, 'Note', '1.00');
        $filters = ['startdate' => '2026-01-01', 'enddate' => '2026-02-01'];
        $report = ReceivablesReport::run($filters);
        $this->assertSame('8.01', $report['total']);
        $this->assertCount(3, $report['rows']);
        $filtered = ReceivablesReport::run([...$filters, 'zeros' => 'hidezero']);
        $this->assertSame([$a->id], array_column($filtered['rows'], 'id'));
        $this->assertSame('8.01', $filtered['total']);
        $all = ReceivablesReport::run(['enddate' => '2026-02-01']);
        $this->assertSame('809.01', $all['total']);
        $this->assertSame('UNKNOWN', $all['rows'][4]['name']);
        $this->assertNull($all['rows'][4]['id']);
        $rows = $this->csv('/reports/receivables?'.http_build_query([...$filters, 'zeros' => 'hidezero', 'format' => 'csv']));
        $this->assertContains(['TOTAL', '8.01'], $rows);
        $index = array_search(ReceivablesReport::HEADERS, $rows, true);
        $this->assertSame([(string) $a->id, 'Synthetic Learner', '10.01'], $rows[$index + 1]);
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
        $this->assertSame(['Total invoiced' => '15.01', 'Total received' => '16.00',
            'Total prepayments' => '3.99', 'Total outstanding' => '-0.99'], $report['totals']);
        $this->assertSame('0.00', $report['groups'][0]['outstanding']);
        $this->assertSame('3.00', $report['groups'][1]['outstanding']);
        $this->assertSame($p->id, $report['groups'][0]['payments'][0]['id']);
        $this->assertSame('-10.01', $report['groups'][0]['invoices'][0]['amount']);
        $without = InvoicesReport::run([...$filters, 'showreceived' => 0]);
        $this->assertSame('15.01', $without['totals']['Total outstanding']);
        $this->assertSame('0.00', $without['groups'][0]['outstanding']);
        $rows = $this->csv('/reports/invoices?'.http_build_query([...$filters, 'format' => 'csv']));
        $this->assertContains(InvoicesReport::HEADERS, $rows);
        $this->assertContains(['Total outstanding', '-0.99'], $rows);
        $this->assertContains(['', '', '', '', '', '', 'TOTAL PREPAYMENT', '', '', '', '3.99'], $rows);
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
        $this->transaction($student, 'Fee', '1000.00', ['transactiondate' => '2025-12-31 23:59:59']);
        $this->transaction($student, 'Fee', '2000.00', ['transactiondate' => '2026-01-06']);
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
        $this->assertCount(22, $rows[$index + 1]);
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
        $this->assertSame('6 days, 0:00:00', $report['rows'][0]['loa length']);
        $this->assertSame('-1 day, 0:00:00', $report['rows'][0]['current']);
        $this->assertSame('100.000000', $report['rows'][0]['prepaid']);
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
            $this->transaction($student, 'Fee', '0.01', ['transactiondate' => '2026-01-01']);
            $this->transaction($student, 'Payment', '-1.00', ['transactiondate' => '2026-01-01']);
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

    public function test_prepays_future_reversed_loa_refund_spelling_and_nonmutating_batched_reads(): void
    {
        $student = $this->student();
        $this->transaction($student, 'Fee', '100.00');
        $this->transaction($student, 'Payment', '-50.00');
        $this->transaction($student, 'refund', '10.00');
        $this->loa($student, '2026-01-08', null);
        $this->loa($student, '2026-01-05', '2026-01-04');
        DB::enableQueryLog();
        $report = PrepaysReport::run($this->prepayFilters);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $this->assertSame('-3 days, 0:00:00', $report['rows'][0]['loa length']);
        $this->assertSame('8 days, 0:00:00', $report['rows'][0]['current']);
        $this->assertSame('60.00', $report['rows'][0]['paid']);
        $this->assertSame('80.000000', $report['rows'][0]['prorataowed']);
        $this->assertSame('0.000000', $report['rows'][0]['prepaid']);
        $this->assertDatabaseCount('studenttransactions', 3);
        $this->assertDatabaseCount('loas', 2);
        $this->assertSame('100.00', $student->fresh()->balance);
    }

    public function test_receivables_unbounded_csv_includes_unknown_null_amount_and_note_escaping(): void
    {
        $student = $this->student(['first_name' => '=Formula', 'last_name' => '<script>bad</script>']);
        $this->transaction($student, 'Fee', '0.00', ['transactiondate' => null, 'processeddate' => null]);
        $this->transaction(null, null, '0.01');
        $rows = $this->csv('/reports/receivables?format=csv');
        $this->assertContains(['TOTAL', '0.01'], $rows);
        $this->assertContains(['', 'UNKNOWN', '0.01'], $rows);
        $this->assertContains([(string) $student->id, "'=Formula <script>bad</script>", '0.00'], $rows);
        $this->get('/reports/receivables')->assertOk()->assertSee('&lt;script&gt;bad&lt;/script&gt;', false)
            ->assertDontSee('<script>bad</script>', false);
    }
}
