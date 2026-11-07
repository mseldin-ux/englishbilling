<?php

namespace Tests\Feature;

use App\Models\AnticipatedPayment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_row_order_is_preserved_and_duplicate_ids_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $student = new Student;
        $student->save();
        $this->obtainEditingLock($student);
        $url = "/students/{$student->id}/schedule";
        $version = $this->get($url)->viewData('version');
        $payments = [
            ['paymentduedate' => '2027-03-01', 'paymentamount' => '9.99', 'paymentnotes' => 'First row'],
            ['paymentduedate' => '2027-01-01', 'paymentamount' => '10.01', 'paymentnotes' => 'Second row'],
        ];
        $this->putJson($url, ['version' => $version, 'paymentplandesc' => 'Ordered', 'payments' => $payments])->assertOk();
        $rows = $this->get($url)->viewData('payments');
        $this->assertSame(['First row', 'Second row'], $rows->pluck('paymentnotes')->all());
        $duplicate = [...$payments[0], 'id' => $rows[0]->id];
        $this->putJson($url, ['version' => $this->get($url)->viewData('version'),
            'paymentplandesc' => 'Reject', 'payments' => [$duplicate, $duplicate]])
            ->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertSame(['First row', 'Second row'], AnticipatedPayment::orderBy('id')->pluck('paymentnotes')->all());
    }
}
