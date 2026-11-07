<?php

namespace Tests\Feature;

use App\Billing\EarnedIncome;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarnedIncomeTest extends TestCase
{
    use RefreshDatabase;

    private array $filters = ['startdate' => '2026-01-01', 'lastpossiblestartdate' => '2026-02-01', 'dateasof' => '2026-01-02'];

    private function student(array $data = []): Student
    {
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'last_name' => 'Learner',
            'start_date' => '2026-01-01', 'graddate' => '2028-01-01', ...$data])->save();

        return $student;
    }

    private function fee(Student $student, string $type, ?string $amount, string $date = '2028-01-01'): void
    {
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $student->id, 'transactiontype' => $type,
            'transactionamount' => $amount, 'transactiondate' => $date])->save();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_all_date_absolute_fees_ignore_other_types_and_keep_six_decimal_details(): void
    {
        $program = new Program;
        $program->forceFill(['program' => 'Synthetic', 'servicedays' => 3])->save();
        $student = $this->student(['program_id' => $program->id]);
        $this->fee($student, 'Fee', '-0.75');
        $this->fee($student, 'DECLINED FEE', '0.25', '2020-01-01');
        foreach (['Payment', 'Discount', 'Fee Adjustment', 'Write off', 'Credit'] as $type) {
            $this->fee($student, $type, '100.00');
        }
        $this->fee($student, 'Fee', null);
        $report = EarnedIncome::run($this->filters);
        $this->assertSame(['invoiced' => '1.00', 'total earned' => '0.33', 'total unearned' => '0.67'], $report['totals']);
        $this->assertSame('0.333333', $report['rows'][0]['earned']);
        $this->assertSame('0.666667', $report['rows'][0]['unearned']);
        $this->assertNull($student->fresh()->balance);
        $this->get('/reports/earned-income?'.http_build_query($this->filters))
            ->assertOk()->assertSee('0.333333')->assertSee('0.666667');
    }

    public function test_selection_boundaries_graduation_constraint_status_and_zero_progress_filter(): void
    {
        $included = $this->student(['graddate' => null, 'archived' => true]);
        $future = $this->student(['start_date' => '2026-01-31 23:59:59']);
        $zeroFee = $this->student(['graddate' => '2026-01-01']);
        $this->student(['start_date' => '2025-12-31 23:59:59']);
        $this->student(['start_date' => '2026-02-01']);
        $this->student(['graddate' => '2025-12-31']);
        $this->student(['start_date' => null]);
        $this->fee($future, 'Fee', '100.00');
        $report = EarnedIncome::run($this->filters);
        $this->assertSame([(string) $included->id, (string) $future->id, (string) $zeroFee->id], array_column($report['rows'], 'id'));
        $filtered = EarnedIncome::run([...$this->filters, 'skipzero' => 'yes']);
        $this->assertSame([(string) $included->id, (string) $zeroFee->id], array_column($filtered['rows'], 'id'));
        $this->assertSame('0.00', $filtered['totals']['invoiced']);
    }

    public function test_sorting_missing_values_lifecycle_completion_and_once_rounded_totals(): void
    {
        $program = new Program;
        $program->forceFill(['program' => 'Synthetic', 'servicedays' => 2])->save();
        $a = $this->student(['program_id' => $program->id, 'last_name' => 'Z', 'graddate' => null]);
        $b = $this->student(['program_id' => $program->id, 'last_name' => 'A']);
        $this->fee($a, 'Fee', '0.01');
        $this->fee($b, 'Fee', '0.01');
        $report = EarnedIncome::run([...$this->filters, 'sort' => 'Last Name']);
        $this->assertSame([(string) $b->id, (string) $a->id], array_column($report['rows'], 'id'));
        $this->assertSame(['0.005000', '0.005000'], array_column($report['rows'], 'earned'));
        $this->assertSame('0.01', $report['totals']['total earned']);
        $this->assertSame((string) $a->id, EarnedIncome::run([...$this->filters, 'sort' => 'Graduation Date'])['rows'][0]['id']);
        $b->forceFill(['wd' => true, 'wd_date' => '2026-01-02'])->save();
        $this->assertSame('0.010000', EarnedIncome::run($this->filters)['rows'][1]['earned']);
    }

    public function test_csv_headers_precision_nulls_literal_text_and_permissions(): void
    {
        $student = $this->student(['first_name' => '=formula', 'graddate' => null]);
        $this->fee($student, 'Fee', '100.00');
        $response = $this->get('/reports/earned-income?'.http_build_query([...$this->filters, 'format' => 'csv']))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=cgms-earned-income-report-2026-01-02.csv');
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", $response->streamedContent()));
        $index = array_search(EarnedIncome::FIELDS, $lines, true);
        $this->assertNotFalse($index);
        $row = array_combine(EarnedIncome::FIELDS, $lines[$index + 1]);
        $this->assertSame("'=formula", $row['first_name']);
        $this->assertSame('', $row['graddate']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $row['earned']);
        $this->assertCount(29, $lines[$index + 1]);
        $this->get('/reports/earned-income')->assertSessionHasErrors(['startdate', 'lastpossiblestartdate', 'dateasof']);
        $this->get('/reports/earned-income?'.http_build_query([...$this->filters, 'sort' => 'bad']))->assertSessionHasErrors('sort');
        $this->get('/reports/earned-income?'.http_build_query([...$this->filters, 'lastpossiblestartdate' => '2026-01-01']))->assertSessionHasErrors('lastpossiblestartdate');
        auth()->logout();
        $this->get('/reports/earned-income')->assertRedirect('/login');
    }
}
