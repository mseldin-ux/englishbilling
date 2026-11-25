<?php

namespace Tests\Feature;

use App\Billing\StageBillingAllocations;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StageBillingAllocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_invoice_and_payment_have_exact_stage_breakdowns_without_changing_ledger(): void
    {
        [$student, $stages] = $this->learner();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'amount' => '1000.00'])->save();
        $payment = new StudentTransaction;
        $payment->forceFill(['student_id' => $student->id, 'invoice_id' => $invoice->id,
            'transactiontype' => 'Payment', 'transactionamount' => '-250.00'])->save();

        StageBillingAllocations::replace($student, $invoice, [
            ['stage_enrollment_id' => $stages[0], 'amount' => '600.00'],
            ['stage_enrollment_id' => $stages[1], 'amount' => '400.00'],
        ]);
        StageBillingAllocations::replace($student, $payment, [
            ['stage_enrollment_id' => $stages[0], 'amount' => '150.00'],
            ['stage_enrollment_id' => $stages[1], 'amount' => '100.00'],
        ]);

        $this->assertDatabaseHas('invoice_stage_allocations', ['invoice_id' => $invoice->id,
            'stage_enrollment_id' => $stages[0], 'amount' => 600]);
        $this->assertDatabaseHas('transaction_stage_allocations', ['transaction_id' => $payment->id,
            'stage_enrollment_id' => $stages[1], 'amount' => 100]);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('studenttransactions', 1);
        $this->assertSame('-250.00', $payment->fresh()->transactionamount);
    }

    public function test_rejects_bad_totals_duplicate_stages_and_other_learners_without_erasing_allocations(): void
    {
        [$student, $stages] = $this->learner();
        [, $otherStages] = $this->learner();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'amount' => '100.00'])->save();
        StageBillingAllocations::replace($student, $invoice, [
            ['stage_enrollment_id' => $stages[0], 'amount' => '100.00'],
        ]);
        foreach ([
            [['stage_enrollment_id' => $stages[0], 'amount' => '99.99']],
            [['stage_enrollment_id' => $stages[0], 'amount' => '50'], ['stage_enrollment_id' => $stages[0], 'amount' => '50']],
            [['stage_enrollment_id' => $otherStages[0], 'amount' => '100']],
            [['stage_enrollment_id' => $stages[0], 'amount' => '100.001']],
        ] as $invalid) {
            try {
                StageBillingAllocations::replace($student, $invoice, $invalid);
                $this->fail('Invalid allocations must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allocations', $exception->errors());
            }
            $this->assertDatabaseHas('invoice_stage_allocations', ['invoice_id' => $invoice->id,
                'stage_enrollment_id' => $stages[0], 'amount' => 100]);
        }
    }

    public function test_allocation_uses_the_current_locked_amount_not_a_stale_model(): void
    {
        [$student, $stages] = $this->learner();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'amount' => '100.00'])->save();
        DB::table('invoices')->where('id', $invoice->id)->update(['amount' => '150.00']);
        $this->expectException(ValidationException::class);
        StageBillingAllocations::replace($student, $invoice, [
            ['stage_enrollment_id' => $stages[0], 'amount' => '100.00'],
        ]);
    }

    private function learner(): array
    {
        $program = new Program;
        $program->forceFill(['program' => 'Test Full', 'servicedays' => 100])->save();
        $student = new Student;
        $student->forceFill(['first_name' => 'Synthetic', 'balance' => '0.00'])->save();
        $enrollment = StudentEnrollment::create(['student_id' => $student->id, 'program_id' => $program->id,
            'mode' => 'staged', 'started_on' => '2026-01-01']);
        $stages = [];
        foreach ([1, 2] as $stage) {
            $stages[] = DB::table('student_stage_enrollments')->insertGetId(['enrollment_id' => $enrollment->id,
                'stage' => $stage, 'started_on' => '2026-01-01', 'service_days' => 100]);
        }

        return [$student, $stages];
    }
}
