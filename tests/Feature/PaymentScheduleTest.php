<?php

namespace Tests\Feature;

use App\Models\AnticipatedPayment;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentPaymentPlan;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->student = new Student;
        $this->student->forceFill(['start_date' => '2026-01-31', 'balance' => '100.00'])->save();
        $fee = new StudentTransaction;
        $fee->forceFill(['student_id' => $this->student->id, 'transactiontype' => 'Fee', 'transactionamount' => '200.00'])->save();
        $this->obtainEditingLock($this->student);
    }

    private function version(): string
    {
        return $this->get("/students/{$this->student->id}/schedule")->assertOk()->viewData('version');
    }

    private function payload(string $version): array
    {
        return ['version' => $version, 'paymentplandesc' => 'Custom',
            'payments' => [['paymentduedate' => '2026-12-01', 'paymentamount' => '100.00', 'paymentnotes' => 'Due']]];
    }

    public function test_generation_is_cent_exact_and_does_not_save_until_confirmed(): void
    {
        $template = new PaymentPlan;
        $template->forceFill(['paymentplan' => 'Three', 'monthslist' => '1,2,3'])->save();
        $response = $this->postJson("/students/{$this->student->id}/schedule/generate", [
            'template_id' => $template->id, 'start_date' => '2026-01-31',
            'exclude_app_fee' => true, 'app_fee' => '100.00',
        ])->assertOk();
        $this->assertSame(['33.33', '33.33', '33.34'], array_column($response->json('payments'), 'paymentamount'));
        $this->assertSame(['2026-01-31', '2026-03-03', '2026-03-31'], array_column($response->json('payments'), 'paymentduedate'));
        $this->assertDatabaseCount('studentpaymentplans', 0);
        $this->assertDatabaseCount('anticipatedpayment', 0);
    }

    public function test_save_edit_and_erase_schedule_preserve_existing_metadata(): void
    {
        $url = "/students/{$this->student->id}/schedule";
        $this->putJson($url, $this->payload($this->version()))->assertOk();
        $payment = AnticipatedPayment::firstOrFail();
        $payment->paymentinvoicedate = '2026-11-01';
        $payment->paymentpercent = 0.12345;
        $payment->save();
        $payload = $this->payload($this->version());
        $payload['payments'][0]['id'] = $payment->id;
        $payload['payments'][0]['paymentamount'] = '99.99';
        $this->putJson($url, $payload)->assertOk();
        $replacement = AnticipatedPayment::firstOrFail();
        $this->assertNotSame($payment->id, $replacement->id);
        $this->assertSame('99.99', $replacement->paymentamount);
        $this->assertSame('2026-11-01', $replacement->paymentinvoicedate->format('Y-m-d'));
        $this->assertSame(0.12345, $replacement->paymentpercent);
        $this->putJson($url, ['version' => $this->version(), 'paymentplandesc' => null, 'payments' => []])->assertOk();
        $this->assertDatabaseCount('anticipatedpayment', 0);
        $this->assertDatabaseCount('studentpaymentplans', 1);
    }

    public function test_stale_plan_rejects_overwrite_and_invalid_rows_leave_existing_plan(): void
    {
        $url = "/students/{$this->student->id}/schedule";
        $old = $this->version();
        $this->putJson($url, $this->payload($old))->assertOk();
        $this->putJson($url, $this->payload($old))->assertUnprocessable()->assertJsonValidationErrors('version');
        $payload = $this->payload($this->version());
        $payload['payments'][0]['paymentamount'] = '99.999';
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseHas('anticipatedpayment', ['paymentamount' => '100.00']);
    }

    public function test_foreign_row_rejection_rolls_back_description_and_all_rows(): void
    {
        $url = "/students/{$this->student->id}/schedule";
        $this->putJson($url, $this->payload($this->version()))->assertOk();
        $payload = $this->payload($this->version());
        $payload['paymentplandesc'] = 'Must roll back';
        $payload['payments'][] = ['id' => 99999, 'paymentduedate' => '2027-01-01',
            'paymentamount' => '1.00', 'paymentnotes' => 'Foreign row'];
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('anticipatedpayment', 1);
        $this->assertSame('Custom', StudentPaymentPlan::firstOrFail()->paymentplandesc);
    }
}
