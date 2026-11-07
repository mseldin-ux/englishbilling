<?php

namespace Tests\Feature;

use App\Billing\RecordVersion;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['username' => 'staff']));
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Test', 'last_name' => 'Learner',
            'balance' => '0.00', 'inactive' => false, 'loa' => false,
            'wd' => false, 'sus' => false, 'graduated' => false, 'pif' => false,
            'archived' => false, 'start_date' => '2026-01-31 00:00:00'])->save();
        $this->obtainEditingLock($this->student);
    }

    private function record(string $model, array $data)
    {
        $record = new $model;
        $record->forceFill(['student_id' => $this->student->id, ...$data])->save();

        return $record;
    }

    private function transactionData(): array
    {
        return ['transactiondate' => '2026-11-01', 'processeddate' => '2026-11-02',
            'transactiontype' => 'Fee', 'transactionamount' => '100.10', 'transactionnotes' => 'Base Tuition'];
    }

    public function test_staff_can_view_browse_detail_creation_and_all_sections(): void
    {
        $this->get('/browse')->assertOk()->assertSee('Learner')->assertSee('Cohort');
        $this->get('/create')->assertOk()->assertSee('Sponsor contact');
        $this->get('/detail/'.$this->student->id)->assertOk()->assertSee('Save All Changes');
        foreach (['transactions', 'invoices', 'loas', 'schedule'] as $section) {
            $this->get("/students/{$this->student->id}/{$section}")->assertOk();
        }
        $this->get('/paymentplans')->assertOk()->assertSee('Payment Plan Templates');
    }

    public function test_guest_cannot_read_or_mutate_billing_or_download_documents(): void
    {
        auth()->logout();
        $this->get('/browse')->assertRedirect('/login');
        $this->get("/detail/{$this->student->id}")->assertRedirect('/login');
        $this->postJson("/students/{$this->student->id}/transactions", $this->transactionData())->assertUnauthorized();
        $this->get("/students/{$this->student->id}/invoices/1/pdf")->assertRedirect('/login');
    }

    public function test_create_and_partial_edit_preserve_all_unsubmitted_fields(): void
    {
        $this->post('/students', ['first_name' => 'Created', 'school' => 'Montessori',
            'sponsor_email' => 'sponsor@example.test', 'loa' => null])->assertRedirect();
        $created = Student::where('first_name', 'Created')->firstOrFail();
        $this->assertSame('0.00', $created->balance);
        $this->student->forceFill(['graddate' => '2027-11-03 13:14:15', 'gender' => 'Unspecified',
            'invoice_notes' => '', 'sponsored' => null])->save();
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student), 'first_name' => 'Edited',
            'graddate' => '2027-11-03', 'invoice_notes' => null, 'balance' => '99999.00', 'id' => 900,
        ])->assertOk()->assertJsonPath('message', 'All changes saved.');
        $fresh = $this->student->fresh();
        $this->assertSame('Edited', $fresh->first_name);
        $this->assertSame('2027-11-03 13:14:15', $fresh->graddate->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->sponsored);
        $this->assertSame('', $fresh->invoice_notes);
        $this->assertSame('Unspecified', $fresh->gender);
        $this->assertSame('0.00', $fresh->balance);
    }

    public function test_stale_student_edit_and_invalid_money_are_rejected(): void
    {
        $version = RecordVersion::of($this->student);
        $this->student->first_name = 'Another staff edit';
        $this->student->save();
        $this->patchJson("/students/{$this->student->id}", [
            'version' => $version, 'first_name' => 'Overwrite',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student), 'payment_amount_due' => '1.005',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_amount_due');
        $this->assertSame('Another staff edit', $this->student->fresh()->first_name);
    }

    public function test_saved_date_returns_the_persisted_version_for_the_next_edit(): void
    {
        $response = $this->patchJson("/students/{$this->student->id}", [
            'version' => RecordVersion::of($this->student->fresh()),
            'start_date' => '2026-02-28',
        ])->assertOk();
        $this->assertSame(RecordVersion::of($this->student->fresh()), $response->json('version'));
        $this->patchJson("/students/{$this->student->id}", [
            'version' => $response->json('version'), 'first_name' => 'Next edit',
        ])->assertOk();
        $this->assertSame('Next edit', $this->student->fresh()->first_name);
    }

    public function test_ledger_create_edit_delete_atomically_recalculates_signed_cents(): void
    {
        $url = "/students/{$this->student->id}/transactions";
        $this->postJson($url, $this->transactionData())->assertOk();
        $transaction = StudentTransaction::firstOrFail();
        $this->assertSame('100.10', $this->student->fresh()->balance);
        $this->postJson($url, [...$this->transactionData(), 'transactiontype' => 'Payment',
            'transactionamount' => '-25.05'])->assertOk();
        $this->assertSame('75.05', $this->student->fresh()->balance);
        $this->patchJson($url.'/'.$transaction->id, [
            'version' => RecordVersion::of($transaction), 'transactionamount' => '80.10',
        ])->assertOk()->assertJsonStructure(['version']);
        $this->assertSame('55.05', $this->student->fresh()->balance);
        $this->deleteJson($url.'/'.$transaction->id, [
            'version' => RecordVersion::of($transaction->fresh()),
        ])->assertOk();
        $this->assertSame('-25.05', $this->student->fresh()->balance);
    }

    public function test_ledger_changes_do_not_invalidate_unrelated_student_edits(): void
    {
        $version = RecordVersion::of($this->student);
        $this->postJson("/students/{$this->student->id}/transactions", $this->transactionData())->assertOk();
        $this->patchJson("/students/{$this->student->id}", [
            'version' => $version, 'school' => 'Changed school',
        ])->assertOk();
        $this->assertSame('100.10', $this->student->fresh()->balance);
    }

    public function test_transaction_fields_validation_and_ownership_protect_data(): void
    {
        $url = "/students/{$this->student->id}/transactions";
        $this->postJson($url, [...$this->transactionData(), 'transactionamount' => 'bad'])->assertUnprocessable();
        $this->postJson($url, [...$this->transactionData(), 'transactiondate' => '2026-02-30'])->assertUnprocessable();
        $this->postJson($url, [...$this->transactionData(), 'invoice_id' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('studenttransactions', 0);
        $this->assertSame('0.00', $this->student->fresh()->balance);
        $other = new Student;
        $other->forceFill(['first_name' => 'Other'])->save();
        $invoice = $this->record(Invoice::class, ['student_id' => $other->id, 'amount' => '5.00']);
        $this->postJson($url, [...$this->transactionData(), 'invoice_id' => $invoice->id])->assertUnprocessable();
        $transaction = $this->record(StudentTransaction::class, $this->transactionData());
        $this->obtainEditingLock($other);
        $this->patchJson("/students/{$other->id}/transactions/{$transaction->id}", [
            'version' => RecordVersion::of($transaction), 'transactionamount' => '1.00',
        ])->assertNotFound();
        $this->deleteJson("/students/{$other->id}/transactions/{$transaction->id}", [
            'version' => RecordVersion::of($transaction),
        ])->assertNotFound();
    }

    public function test_stale_transaction_is_not_overwritten(): void
    {
        $record = $this->record(StudentTransaction::class, $this->transactionData());
        $version = RecordVersion::of($record);
        $record->transactionnotes = 'Staff reconciled';
        $record->save();
        $this->patchJson("/students/{$this->student->id}/transactions/{$record->id}", [
            'version' => $version, 'transactionnotes' => 'Overwrite',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('Staff reconciled', $record->fresh()->transactionnotes);
    }

    public function test_invoice_crud_keeps_original_pdf_and_unlinks_payments(): void
    {
        $url = "/students/{$this->student->id}/invoices";
        $this->postJson($url, ['nextdate' => '2026-12-01', 'amount' => '100.00',
            'invoicereference' => 'Test Invoice', 'user_sent' => 'spoof'])->assertOk();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('staff', $invoice->user_sent);
        $invoice->invoice_file = "%PDF-test\0\xff";
        $invoice->save();
        $transaction = $this->record(StudentTransaction::class, [...$this->transactionData(), 'invoice_id' => $invoice->id]);
        $list = $this->get($url)->assertOk()->assertSee('Stored PDF')->assertSee('HTML')->assertSee('Edit Invoice');
        $this->assertArrayNotHasKey('invoice_file', $list->viewData('records')->first()->getAttributes());
        $this->patchJson($url.'/'.$invoice->id, [
            'version' => RecordVersion::of($list->viewData('records')->first()), 'notes' => 'Edited',
        ])->assertOk();
        $this->assertSame("%PDF-test\0\xff", $invoice->fresh()->invoice_file);
        $this->get($url.'/'.$invoice->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertContent("%PDF-test\0\xff");
        $this->deleteJson($url.'/'.$invoice->id, ['version' => RecordVersion::of($invoice->fresh())])->assertOk();
        $this->assertNull($transaction->fresh()->invoice_id);
        $this->assertDatabaseCount('studenttransactions', 1);
    }

    public function test_missing_pdf_and_wrong_student_pdf_are_explicit(): void
    {
        $invoice = $this->record(Invoice::class, []);
        $this->get("/students/{$this->student->id}/invoices/{$invoice->id}/pdf")->assertNotFound();
        $other = new Student;
        $other->save();
        $this->get("/students/{$other->id}/invoices/{$invoice->id}/pdf")->assertNotFound();
    }

    public function test_loas_accept_open_end_reject_reversed_edits_and_allow_repair(): void
    {
        $url = "/students/{$this->student->id}/loas";
        $this->postJson($url, ['loa_start' => '2026-04-01', 'loa_end' => null])->assertOk();
        $loa = LeaveOfAbsence::firstOrFail();
        $this->patchJson($url.'/'.$loa->id, [
            'version' => RecordVersion::of($loa), 'loa_end' => '2026-03-01',
        ])->assertUnprocessable();
        $legacy = $this->record(LeaveOfAbsence::class, ['loa_start' => '2026-04-01', 'loa_end' => '2026-03-01']);
        $this->get($url)->assertOk();
        $this->patchJson($url.'/'.$legacy->id, [
            'version' => RecordVersion::of($legacy), 'loa_end' => null,
        ])->assertOk();
        $this->assertNull($legacy->fresh()->loa_end);
        $this->deleteJson($url.'/'.$loa->id, ['version' => RecordVersion::of($loa)])->assertOk();
    }

    public function test_recalculation_requires_confirmation_and_staff_can_run_it(): void
    {
        $this->postJson("/students/{$this->student->id}/editing-lock/release", [
            'token' => $this->defaultHeaders['X-Student-Lock'],
        ])->assertOk();
        $this->record(StudentTransaction::class, $this->transactionData());
        $this->post('/balances/recalculate')->assertSessionHasErrors('confirm');
        $this->post('/balances/recalculate', ['confirm' => '1'])->assertRedirect();
        $this->assertSame('100.10', $this->student->fresh()->balance);
    }

    public function test_search_pagination_and_next_previous_use_same_filtered_set(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $student = new Student;
            $student->forceFill(['first_name' => 'Match', 'cohort' => 'EC', 'archived' => false])->save();
        }
        $archived = new Student;
        $archived->forceFill(['first_name' => 'Match Archived', 'cohort' => 'EC', 'archived' => true])->save();
        $page = $this->get('/browse?query=Match&cohort=EC')->assertOk()->viewData('students');
        $this->assertSame(45, $page->total());
        $this->assertCount(40, $page);
        $this->assertCount(5, $this->get('/browse?page=2')->assertOk()->viewData('students'));
        $first = $page->first();
        $detail = $this->get('/detail/'.$first->id)->assertOk();
        $this->assertSame($page[1]->id, $detail->viewData('next'));
        $this->assertSame(Student::where('first_name', 'Match')->max('id'), $detail->viewData('previous'));
        $this->assertSame(46, $this->get('/browse?query=Match&showall=1')->viewData('students')->total());
        $this->get('/browse?page=-1')->assertSessionHasErrors('page');
        $this->get('/browse?reset=1')->assertOk();
        $this->assertSame([], session('billing_search', []));
    }

    public function test_transaction_search_deduplicates_learners_and_handles_blank_types(): void
    {
        $this->record(StudentTransaction::class, $this->transactionData());
        $this->record(StudentTransaction::class, $this->transactionData());
        $this->assertSame(1, $this->get('/browse?transaction_type=Fee')->viewData('students')->total());
        $this->assertSame(0, $this->get('/browse?transaction_type=0')->viewData('students')->total());
        $this->record(StudentTransaction::class, [...$this->transactionData(), 'transactiontype' => null]);
        $this->assertSame(1, $this->get('/browse?transaction_type=0')->viewData('students')->total());
        $this->assertSame(1, $this->get('/browse?statuses[]=active')->viewData('students')->total());
    }

    public function test_archive_uses_stale_guard_and_is_hidden_until_showall(): void
    {
        $this->post("/students/{$this->student->id}/archive", ['version' => RecordVersion::of($this->student),
            'archived' => '1'])->assertRedirect();
        $this->assertSame(0, $this->get('/browse')->viewData('students')->total());
        $this->assertSame(1, $this->get('/browse?showall=1')->viewData('students')->total());
    }

    public function test_templates_are_available_to_staff_and_validate_month_lists(): void
    {
        $this->post('/paymentplans', ['paymentplan' => '3 payments', 'paymentplandesc' => 'Quarterly',
            'monthslist' => '1,7,13'])->assertRedirect();
        $plan = PaymentPlan::firstOrFail();
        $this->patch('/paymentplans/'.$plan->id, ['version' => RecordVersion::of($plan),
            'paymentplan' => 'Changed', 'monthslist' => '1,3'])->assertRedirect();
        $this->post('/paymentplans', ['paymentplan' => 'Bad', 'monthslist' => '1,1'])
            ->assertSessionHasErrors('monthslist');
        $this->assertDatabaseCount('paymentplans', 1);
        $this->delete('/paymentplans/'.$plan->id, ['version' => RecordVersion::of($plan->fresh())])->assertRedirect();
    }
}
