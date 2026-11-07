<?php

namespace Tests\Feature;

use App\Billing\AttributableIncome;
use App\Billing\ProgramProgress;
use App\Models\LeaveOfAbsence;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttributableIncomeTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private array $filters = ['startdate' => '2026-01-01', 'enddate' => '2026-02-20'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $program = new Program;
        $program->forceFill(['program' => 'Synthetic', 'servicedays' => 100])->save();
        $this->student = $this->student(['program_id' => $program->id]);
    }

    private function student(array $data = []): Student
    {
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'last_name' => 'Learner',
            'start_date' => '2026-01-01', 'graddate' => '2028-01-01', ...$data])->save();

        return $student;
    }

    private function transaction(?string $type, ?string $amount, ?Student $student = null, ?string $date = '2026-01-10'): void
    {
        $row = new StudentTransaction;
        $row->forceFill(['student_id' => ($student ?? $this->student)->id, 'transactiontype' => $type,
            'transactionamount' => $amount, 'transactiondate' => $date, 'processeddate' => '2028-01-01'])->save();
    }

    private function progress(array $data = [], string $asOf = '2026-02-20'): array
    {
        $this->student->forceFill($data)->save();

        return ProgramProgress::calculate($this->student->fresh()->load('program', 'loas'), $asOf);
    }

    private function loa(?string $start, ?string $end): void
    {
        $row = new LeaveOfAbsence;
        $row->forceFill(['student_id' => $this->student->id, 'loa_start' => $start, 'loa_end' => $end])->save();
    }

    public function test_exact_category_rules_preserve_absolute_signs_and_legacy_exclusions(): void
    {
        foreach ([['Fee', '100.00'], ['Fee', '-5.00'], ['DECLINED FEE', '2.00'],
            ['discount', '-10.00'], ['Correction', '3.00'], ['Waived', '2.00'], ['Discounts', '-8.00'],
            ['Adjustment', '4.00'], ['Adjustments', '1.00'], ['Fee Adjustment', '-2.00'],
            ['Payment', '-20.00'], ['Payment', '3.00'], ['Check', '5.00'], ['Pago', '6.00'], ['Payment/Pago', '7.00'],
            ['Credit', '2.00'], ['Credits', '-1.00'], ['Pmt Adjustment', '4.00'], ['nonexistant', '1.00'],
            ['Write off', '5.00'], ['Refund', '9.00'], ['Reversal', '2.00'], [null, '0.07'],
            ['Note', '0.00'], ['Fee', null]] as [$type, $amount]) {
            $this->transaction($type, $amount);
        }
        $report = AttributableIncome::run($this->filters);
        $row = $report['rows'][0];
        foreach (['invoiced' => '107.00', 'paid' => '41.00', 'discounts' => '15.00', 'adjustments' => '7.00',
            'credits' => '3.00', 'writeoffs' => '6.00', 'adjustedowed' => '85.00', 'adjustedpaid' => '39.00',
            'ar' => '40.00', 'earnedincome' => '42.500000', 'pct' => '0.5'] as $key => $expected) {
            $this->assertSame($expected, $row[$key], $key);
        }
        $this->assertSame('42.50', $report['calculated']['totalearned']);
        $this->assertSame('0.07', $report['types']['none']);
        $this->assertSame('9.00', $report['types']['refund']);
        $this->assertSame('8.00', $report['types']['discounts']);
        $this->assertSame('50 days, 0:00:00', $row['currenttimeinprogram']);
    }

    public function test_customer_date_boundaries_enrollment_filter_null_dates_and_unassigned_rows(): void
    {
        $this->transaction('Fee', '1.00', date: '2026-01-01');
        $this->transaction('Fee', '2.00', date: '2026-02-19 23:59:59');
        $this->transaction('Fee', '4.00', date: '2026-02-20');
        $this->transaction('Fee', '8.00', date: '2025-12-31 23:59:59');
        $this->transaction('Fee', '16.00', date: null);
        $old = $this->student(['start_date' => '2025-12-31']);
        $missing = $this->student(['start_date' => null]);
        $this->transaction('Fee', '32.00', $old);
        $this->transaction('Fee', '64.00', $missing);
        $unassigned = new StudentTransaction;
        $unassigned->forceFill(['transactiondate' => '2026-01-01', 'transactiontype' => 'Fee', 'transactionamount' => '128.00'])->save();
        $all = AttributableIncome::run($this->filters);
        $this->assertSame('99.00', $all['calculated']['invoiced']);
        $filtered = AttributableIncome::run([...$this->filters, 'enrolldate' => '2026-01-01']);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame('3.00', $filtered['calculated']['invoiced']);
    }

    public function test_aggregate_ar_and_learner_ar_retain_different_absolute_value_rules(): void
    {
        $this->transaction('Fee', '1.00');
        $this->transaction('Discount', '11.00');
        $report = AttributableIncome::run($this->filters);
        $this->assertSame('-10.00', $report['rows'][0]['adjustedowed']);
        $this->assertSame('-10.00', $report['rows'][0]['ar']);
        $this->assertSame('10.00', $report['calculated']['ar']);
        $this->assertSame('-5.00', $report['calculated']['totalearned']);
    }

    public function test_negative_adjusted_paid_is_subtracted_by_absolute_value(): void
    {
        $this->transaction('Fee', '100.00');
        $this->transaction('Pmt Adjustment', '10.00');
        $report = AttributableIncome::run($this->filters);
        $this->assertSame('-10.00', $report['rows'][0]['adjustedpaid']);
        $this->assertSame('90.00', $report['rows'][0]['ar']);
    }

    public function test_earned_total_rounds_once_instead_of_summing_rounded_rows(): void
    {
        $this->transaction('Fee', '0.01');
        $second = $this->student(['program_id' => $this->student->program_id]);
        $this->transaction('Fee', '0.01', $second);
        $report = AttributableIncome::run($this->filters);
        $this->assertSame(['0.005000', '0.005000'], array_column($report['rows'], 'earnedincome'));
        $this->assertSame('0.01', $report['calculated']['totalearned']);
    }

    public function test_student_earned_income_keeps_six_decimals_in_full_and_simple_html_and_csv(): void
    {
        $this->student->program()->first()->forceFill(['servicedays' => 3])->save();
        $second = $this->student(['program_id' => $this->student->program_id]);
        $this->transaction('Fee', '1.00');
        $this->transaction('Discount', '2.00', $second);
        $filters = ['startdate' => '2026-01-01', 'enddate' => '2026-01-11'];
        $this->student->forceFill(['start_date' => '2026-01-10'])->save();
        $second->forceFill(['start_date' => '2026-01-10'])->save();
        foreach ([0, 1] as $simple) {
            $parameters = [...$filters, 'usesimplefields' => $simple];
            $report = AttributableIncome::run($parameters);
            $this->assertSame(['0.333333', '-0.666667'], array_column($report['rows'], 'earnedincome'));
            $this->assertSame('-0.33', $report['calculated']['totalearned']);
            $this->get('/reports/attributable-income?'.http_build_query($parameters))
                ->assertOk()->assertSee('<td>0.333333</td>', false)->assertSee('<td>-0.666667</td>', false);
            $response = $this->get('/reports/attributable-income?'.http_build_query([
                ...$parameters, 'format' => 'csv',
            ]))->assertOk();
            $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", $response->streamedContent()));
            $index = array_search(['DETAILS BY AL'], $lines, true);
            $column = array_search('earnedincome', $lines[$index + 1], true);
            $this->assertSame('0.333333', $lines[$index + 2][$column]);
            $this->assertSame('-0.666667', $lines[$index + 3][$column]);
            $this->assertContains(['', 'totalearned', '-0.33'], $lines);
        }
    }

    public function test_program_progress_clamps_future_and_completed_service_without_dst_changes(): void
    {
        $this->assertSame('0', $this->progress(['start_date' => '2026-03-01'])['fields']['pct']);
        $this->assertSame('0:00:00', $this->progress()['fields']['currentservicetime']);
        $this->assertSame('1', $this->progress(['start_date' => '2025-01-01'])['fields']['pct']);
        $progress = $this->progress(['start_date' => '2026-03-01'], '2026-03-11');
        $this->assertSame('10 days, 0:00:00', $progress['fields']['currentservicetime']);
        $this->assertSame('0.1', $progress['fields']['pct']);
    }

    public function test_lifecycle_flags_only_force_completion_when_dated_and_not_suspension_or_pif(): void
    {
        foreach (['wd' => 'wd_date', 'inactive' => 'inactive_date', 'onextension' => 'extension_start_date',
            'graduated' => 'actualdategrad'] as $flag => $field) {
            $this->assertSame('0.5', $this->progress([$flag => true, $field => null])['fields']['pct']);
            $this->assertSame('0.5', $this->progress([$field => '2026-03-01'])['fields']['pct']);
            $this->assertSame('1', $this->progress([$field => '2026-02-01'])['fields']['pct']);
            $this->progress([$flag => false, $field => null]);
        }
        $this->assertSame('0.5', $this->progress(['sus' => true, 'sus_date' => '2026-02-01',
            'pif' => true, 'pifdate' => '2026-02-01'])['fields']['pct']);
        $this->assertSame('2026-02-01 00:00:00', $this->progress()['fields']['lastdate']);
        $this->assertSame('1', $this->progress(['graduated' => true, 'graddate' => '2026-02-01'])['fields']['pct']);
    }

    public function test_missing_dates_three_year_inference_and_default_service_days_are_nonmutating(): void
    {
        $progress = $this->progress(['program_id' => null, 'start_date' => null, 'graddate' => null]);
        $this->assertSame('1', $progress['fields']['pct']);
        $this->assertSame('547 days, 0:00:00', $progress['fields']['lengthofprogram']);
        $this->assertSame('None', $progress['fields']['start_date']);
        $this->assertNull($this->student->fresh()->start_date);
        $this->assertNull($this->student->fresh()->graddate);
        $this->assertSame('0.5', $this->progress(['program_id' => Program::first()->id,
            'graddate' => '2029-01-01'])['fields']['pct']);
    }

    public function test_legacy_loa_overlap_missing_and_last_row_clipping_are_preserved(): void
    {
        $this->loa(null, null);
        $this->loa('2026-01-10', '2026-01-05');
        $this->loa('2026-01-10', '2026-01-20');
        $this->loa('2026-01-15', '2026-01-25');
        $this->loa('2026-02-10', null);
        $progress = $this->progress();
        $this->assertSame('30 days, 0:00:00', $progress['fields']['loalength']);
        $this->assertSame('0.2', $progress['fields']['pct']);
        $this->loa('2026-03-01', null);
        $this->assertSame('20 days, 0:00:00', $this->progress()['fields']['loalength']);
        $this->loa('2026-02-01', '2026-03-01');
        $this->assertSame('39 days, 0:00:00', $this->progress()['fields']['loalength']);
        $this->loa(null, null);
        $this->assertSame('48 days, 0:00:00', $this->progress()['fields']['loalength']);
    }

    public function test_archived_before_2022_forces_completion_and_bad_service_days_are_explicit(): void
    {
        $this->loa('2021-01-01', null);
        $this->assertSame('1', $this->progress(['archived' => true, 'start_date' => '2021-12-31'])['fields']['pct']);
        $this->assertSame('0', $this->progress(['start_date' => '2022-01-01'])['fields']['pct']);
        $this->student->program()->first()->forceFill(['servicedays' => 0])->save();
        $this->transaction('Fee', '1.00');
        $this->get('/reports/attributable-income?'.http_build_query($this->filters))
            ->assertSessionHasErrors('program');
    }

    public function test_staff_access_validation_empty_results_and_csv_output_shapes(): void
    {
        $this->get('/reports')->assertOk()->assertSee('Attributable Income Report');
        $this->get('/reports/attributable-income')->assertSessionHasErrors(['startdate', 'enddate']);
        $this->get('/reports/attributable-income?startdate=2026-01-01&enddate=2026-01-01')->assertSessionHasErrors('enddate');
        $this->get('/reports/attributable-income?'.http_build_query($this->filters))
            ->assertOk()->assertSee('No matching transactions.');
        $this->student->first_name = '=SUM(A1:A2)';
        $this->student->save();
        $this->transaction('Fee', '100.00');
        foreach ([false, true] as $simple) {
            $report = AttributableIncome::run([...$this->filters, 'usesimplefields' => $simple]);
            $response = $this->get('/reports/attributable-income?'.http_build_query([...$this->filters,
                'usesimplefields' => $simple ? 1 : 0, 'format' => 'csv']))->assertOk()
                ->assertHeader('Content-Disposition', 'attachment; filename=cgms-attributableincome-report.csv');
            $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", $response->streamedContent()));
            $index = array_search(['DETAILS BY AL'], $lines, true);
            $this->assertSame(['', ...$report['csvFields']], $lines[$index + 1]);
            $this->assertCount(count($report['csvFields']) + 1, $lines[$index + 2]);
            $this->assertStringStartsWith("'=SUM", $lines[$index + 2][2]);
            $this->assertSame($simple ? '0.50' : '0.5', $lines[$index + 2][array_search('pct', $report['csvFields']) + 1]);
        }
        auth()->logout();
        $this->get('/reports')->assertRedirect('/login');
        $this->get('/reports/attributable-income?'.http_build_query($this->filters))->assertRedirect('/login');
    }

    public function test_full_report_preserves_legacy_type_order_and_aligns_csv_values_with_headers(): void
    {
        $types = ['fee', 'note', 'payment', 'discount', 'credit', 'write off', 'refund',
            'declined fee', 'fee adjustment'];
        foreach (array_reverse($types) as $type) {
            $this->transaction($type, '0.00', $this->student());
        }
        $this->transaction('Additional type', '0.00', $this->student());
        $this->transaction('Fee', '375.00');
        $this->transaction('Payment', '-475.00');
        $this->transaction('Refund', '100.00');
        $report = AttributableIncome::run($this->filters);
        $this->assertSame([...$types, 'additional type'], array_keys($report['types']));
        $this->assertSame(['id', 'name', ...$types], array_slice($report['fields'], 0, 11));
        $this->assertSame(['id', 'name', ...$types], array_slice($report['csvFields'], 0, 11));
        $response = $this->get('/reports/attributable-income?'.http_build_query([
            ...$this->filters, 'format' => 'csv',
        ]))->assertOk();
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", $response->streamedContent()));
        $index = array_search(['DETAILS BY AL'], $lines, true);
        $header = $lines[$index + 1];
        $row = $lines[$index + 2];
        $this->assertSame(['', 'id', 'name', ...$types], array_slice($header, 0, 12));
        $this->assertSame((string) $this->student->id, $row[1]);
        $this->assertSame(['375.00', '0.00', '475.00', '0.00', '0.00', '0.00',
            '100.00', '0.00', '0.00'], array_slice($row, 3, 9));
        $this->assertSame('375.00', $row[array_search('adjustedowed', $header)]);
        $this->assertSame('475.00', $row[array_search('adjustedpaid', $header)]);
        $this->assertSame('-100.00', $row[array_search('ar', $header)]);
    }

    public function test_csv_escapes_whitespace_prefixed_formulas_but_preserves_negative_money(): void
    {
        $this->transaction('Fee', '1.00');
        $this->transaction('Discount', '11.00');
        foreach (['  =SUM(A1:A2)', "\n@SUM(A1:A2)", "\t+SUM(A1:A2)", '-SUM(A1:A2)'] as $name) {
            $this->student->forceFill(['first_name' => $name])->save();
            $response = $this->get('/reports/attributable-income?'.http_build_query([
                ...$this->filters, 'format' => 'csv',
            ]))->assertOk();
            $csv = $response->streamedContent();
            $this->assertStringContainsString("'".$name.' Learner', $csv);
            $this->assertStringContainsString(',-5.000000,', $csv);
            $this->assertStringNotContainsString("'-5.00", $csv);
        }
    }

    public function test_report_reads_are_batched_and_do_not_change_financial_data(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $student = $this->student(['program_id' => $this->student->program_id]);
            $this->transaction('Fee', '100.01', $student);
        }
        DB::enableQueryLog();
        $report = AttributableIncome::run($this->filters);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(30, $report['rows']);
        $this->assertLessThanOrEqual(4, count($queries));
        $this->assertDatabaseCount('studenttransactions', 30);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertNull($this->student->fresh()->balance);
    }
}
