<?php

namespace Tests\Feature;

use App\Billing\ExpectedIncome;
use App\Billing\TransactionsReport;
use App\Models\AnticipatedPayment;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OtherReportsTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Synthetic', 'middle_name' => 'Middle', 'last_name' => 'Learner',
            'archived' => true, 'graduated' => true])->save();
    }

    private function payment(?string $date, ?string $amount, array $data = []): AnticipatedPayment
    {
        $payment = new AnticipatedPayment;
        $payment->forceFill(['student_id' => $this->student->id, 'paymentduedate' => $date,
            'paymentamount' => $amount, ...$data])->save();

        return $payment;
    }

    private function transaction(?string $type, ?string $amount, array $data = []): StudentTransaction
    {
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $this->student->id, 'transactiontype' => $type,
            'transactionamount' => $amount, 'transactiondate' => '2025-01-01',
            'processeddate' => '2026-01-10', ...$data])->save();

        return $transaction;
    }

    private function csv(string $path): array
    {
        $response = $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
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

    public function test_expected_due_date_boundaries_months_signed_cents_and_student_status(): void
    {
        $this->payment('2026-01-01', '100.01');
        $this->payment('2026-01-31 23:59:59', '-0.02');
        $this->payment('2026-02-01', '0.01');
        $this->payment('2026-03-01', '200.00');
        $this->payment('2025-12-31 23:59:59', '400.00');
        $report = ExpectedIncome::run(['startdate' => '2026-01-01', 'enddate' => '2026-03-01']);
        $this->assertCount(3, $report['rows']);
        $this->assertSame('100.00', $report['total']);
        $this->assertSame(['2026 01' => '99.99', '2026 02' => '0.01'], $report['months']);
        $this->assertSame('Synthetic Middle Learner', $report['rows'][0]['name']);
        $this->assertSame('2026-01-31 23:59:59', $report['rows'][1]['date']);
        $this->assertCount(5, ExpectedIncome::run([])['rows']);
        $this->assertCount(4, ExpectedIncome::run(['startdate' => '2026-01-01'])['rows']);
        $this->assertCount(1, ExpectedIncome::run(['enddate' => '2026-01-01'])['rows']);
        $this->get('/reports/expected-income?startdate=2026-01-01&enddate=2026-03-01')
            ->assertOk()->assertSee('TOTAL 100.00')->assertSee('Synthetic Middle Learner');
    }

    public function test_expected_csv_layout_literal_notes_and_unassigned_student(): void
    {
        $this->payment('2026-01-01', '10.25', ['paymentnotes' => "  =SUM(A1:A2)\n\"quoted\", note"]);
        $this->payment('2026-01-02', '2.00', ['student_id' => null]);
        $rows = $this->csv('/reports/expected-income?format=csv');
        $this->assertSame(['CGMS Planned Income Report'], $rows[0]);
        $this->assertContains(['TOTAL', '12.25'], $rows);
        $this->assertContains(['', '2026 01', '12.25'], $rows);
        $index = array_search(['DETAILS'], $rows, true);
        $this->assertSame(ExpectedIncome::HEADERS, $rows[$index + 1]);
        $this->assertSame(['2026-01-01 00:00:00', (string) $this->student->id, 'Synthetic', 'Learner', '10.25',
            "'  =SUM(A1:A2)\n\"quoted\", note"], $rows[$index + 2]);
        $this->assertSame(['2026-01-02 00:00:00', '', '', '', '2.00', ''], $rows[$index + 3]);
        $this->get('/reports/expected-income')->assertOk()->assertSee('UNKNOWN');
    }

    public function test_transactions_filter_processed_not_customer_dates_and_keep_signed_totals(): void
    {
        $first = $this->transaction('Payment', '-10.01', ['processeddate' => '2026-01-01', 'transactiondate' => '2028-01-01']);
        $second = $this->transaction('Payment', '0.02', ['processeddate' => '2026-01-31 23:59:59', 'transactiondate' => '2024-01-01']);
        $this->transaction('Payment', '-100.00', ['processeddate' => '2026-02-01']);
        $this->transaction('Payment', '-200.00', ['processeddate' => '2025-12-31 23:59:59']);
        $this->transaction('Payment', '-400.00', ['processeddate' => null]);
        $report = TransactionsReport::run(['startdate' => '2026-01-01', 'enddate' => '2026-02-01']);
        $this->assertSame([$second->id, $first->id], array_column($report['rows'], 'id'));
        $this->assertSame(['Payment' => '-9.99'], $report['totals']);
        $this->assertSame('Synthetic Middle Learner', $report['rows'][0]['name']);
        $this->assertCount(5, TransactionsReport::run([])['rows']);
    }

    public function test_transaction_type_filters_are_literal_case_insensitive_substrings(): void
    {
        foreach (['Fee', 'DECLINED FEE', 'Fee Adjustment', 'Payment', 'Payment/Pago', 'Check', 'Pago',
            '10%_fee!', '10XXfee!', 'Discount'] as $type) {
            $this->transaction($type, '1.00');
        }
        $this->transaction(null, '0.01');
        $this->assertCount(5, TransactionsReport::run(['whichtransactions' => 'feesonly'])['rows']);
        $this->assertCount(2, TransactionsReport::run(['whichtransactions' => 'paymentsonly'])['rows']);
        $this->assertCount(1, TransactionsReport::run(['whichtransactions' => 'discount'])['rows']);
        $this->assertCount(1, TransactionsReport::run(['whichtransactions' => '10%_fee!'])['rows']);
        $this->assertCount(11, TransactionsReport::run(['whichtransactions' => 'all'])['rows']);
        $this->get('/reports/transactions?whichtransactions=Payment')->assertOk()
            ->assertSee('value="Payment" selected', false);
    }

    public function test_signed_amount_filters_include_zero_and_decimal_boundaries(): void
    {
        foreach (['-10.02', '-10.01', '0.00', '10.01', '10.02'] as $amount) {
            $this->transaction('Fee', $amount);
        }
        $report = TransactionsReport::run(['transmin' => '-10.01', 'transmax' => '10.01']);
        $this->assertSame(['-10.01', '0.00', '10.01'], array_column($report['rows'], 'amount'));
        $this->assertSame(['Fee' => '0.00'], $report['totals']);
        $this->assertCount(1, TransactionsReport::run(['transmin' => '0', 'transmax' => '0'])['rows']);
        $this->get('/reports/transactions?transmin=-10.01&transmax=10.01')->assertOk();
    }

    public function test_transactions_csv_shape_null_dates_types_unknown_students_and_escaped_text(): void
    {
        $first = $this->transaction('Payment', '-10.01', ['transactionnotes' => "@formula\n\"quote\", note"]);
        $second = $this->transaction(null, '0.01', ['student_id' => null, 'transactiondate' => null, 'processeddate' => null]);
        $rows = $this->csv('/reports/transactions?format=csv');
        $this->assertSame(['CGMS Transactions report'], $rows[0]);
        $this->assertContains(["'+", 'Payment', '-10.01'], $rows);
        $this->assertContains(["'+", 'None', '0.01'], $rows);
        $index = array_search(TransactionsReport::HEADERS, $rows, true);
        $this->assertSame([(string) $second->id, '', 'UNKNOWN', '', '', '', '0.01', ''], $rows[$index + 1]);
        $this->assertSame([(string) $first->id, (string) $this->student->id, 'Synthetic Middle Learner',
            '2025-01-01 00:00:00', '2026-01-10 00:00:00', 'Payment', '-10.01', "'@formula\n\"quote\", note"], $rows[$index + 2]);
        $this->student->forceFill(['first_name' => null, 'middle_name' => 'None'])->save();
        $this->assertSame('UNKNOWNLearner', TransactionsReport::run([])['rows'][1]['name']);
    }

    public function test_reports_reject_invalid_input_and_surface_incomplete_financial_rows(): void
    {
        foreach (['expected-income', 'transactions'] as $report) {
            $this->get("/reports/$report?startdate=invalid")->assertSessionHasErrors('startdate');
            $this->get("/reports/$report?startdate=2026-01-01&enddate=2026-01-01")->assertSessionHasErrors('enddate');
            $this->get("/reports/$report?format=pdf")->assertSessionHasErrors('format');
        }
        foreach (['transmin=1.001', 'transmin=1e3', 'transmin=bad', 'transmin=100&transmax=20',
            'transmin=-10&transmax=-20', 'transmin=10000000000000'] as $query) {
            $this->get('/reports/transactions?'.$query)->assertSessionHasErrors();
        }
        $this->payment(null, '1.00');
        $this->get('/reports/expected-income')->assertSessionHasErrors('report');
        $this->transaction('Fee', null);
        $this->get('/reports/transactions')->assertSessionHasErrors('report');
    }

    public function test_empty_reports_access_and_single_query_read_only_generation(): void
    {
        $this->get('/reports')->assertOk()->assertSee('Expected Income Report')->assertSee('Transactions Report');
        $this->get('/reports/expected-income')->assertOk()->assertSee('No matching scheduled payments.');
        $this->get('/reports/transactions')->assertOk()->assertSee('No matching transactions.');
        for ($i = 0; $i < 30; $i++) {
            $this->payment('2026-01-01', '0.01');
            $this->transaction('Fee', '0.01');
        }
        DB::enableQueryLog();
        $expected = ExpectedIncome::run([]);
        $transactions = TransactionsReport::run([]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertSame('0.30', $expected['total']);
        $this->assertSame(['Fee' => '0.30'], $transactions['totals']);
        $this->assertDatabaseCount('anticipatedpayment', 30);
        $this->assertDatabaseCount('studenttransactions', 30);
        $this->assertNull($this->student->fresh()->balance);
        auth()->logout();
        $this->get('/reports/expected-income')->assertRedirect('/login');
        $this->get('/reports/transactions')->assertRedirect('/login');
    }

    public function test_missing_payment_amount_is_explicit_and_report_html_escapes_notes(): void
    {
        $payment = $this->payment('2026-01-01', null);
        $this->get('/reports/expected-income?startdate=2026-01-01')->assertSessionHasErrors('report');
        $payment->forceFill(['paymentamount' => '0.00', 'paymentnotes' => '<script>alert(1)</script>'])->save();
        $this->transaction('Fee', '0.00', ['transactionnotes' => '<script>alert(1)</script>']);
        foreach (['expected-income', 'transactions'] as $report) {
            $this->get("/reports/$report")->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false);
        }
    }
}
