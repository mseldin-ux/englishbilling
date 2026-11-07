<?php

namespace Tests\Feature;

use App\Billing\BillingDocument;
use App\Billing\PdfRenderer;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BillingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->student = new Student;
        $this->student->forceFill(['first_name' => 'Test', 'middle_name' => 'None',
            'last_name' => 'Learner', 'streetaddress' => 'One Main St', 'city' => 'Raleigh',
            'state' => 'NC', 'zipcode' => '27615', 'emailaddr' => 'learner@example.test',
            'start_date' => '2026-01-31', 'graddate' => '2027-07-31', 'cohort' => 'EL-Test',
            'payment_amount_due' => '100.10', 'next_due_date' => '2026-12-01',
            'invoice_notes' => 'Student note', 'balance' => '200.10'])->save();
        $this->invoice = new Invoice;
        $this->invoice->forceFill(['student_id' => $this->student->id, 'amount' => '99.99',
            'nextdate' => '2026-12-15', 'notes' => "Line one\nLine two",
            'invoicereference' => 'Test-Reference', 'batchid' => 'Batch-42',
            'urgent_message' => 'Please review', 'invoice_file' => '%PDF-old-original'])->save();
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $this->student->id, 'transactiondate' => '2026-01-31',
            'processeddate' => '2026-02-01', 'transactiontype' => 'Fee', 'transactionamount' => '200.10',
            'transactionnotes' => 'First ledger row'])->save();
        $this->obtainEditingLock($this->student);
    }

    public function test_invoice_html_uses_invoice_values_and_customer_dates_and_escapes_content(): void
    {
        $this->invoice->notes = '<script>alert("unsafe")</script>';
        $this->invoice->save();
        $this->get($this->invoiceUrl('html'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Test-Reference')->assertSee('Batch-42')->assertSee('Please review')
            ->assertSee('$99.99')->assertSee('12/15/2026')->assertSee('200.10')
            ->assertSee('2026-01-31')->assertDontSee('2026-02-01')
            ->assertSee('<script>alert("unsafe")</script>')->assertDontSee('<script>alert("unsafe")</script>', false)
            ->assertDontSee('Student note');
    }

    public function test_sponsored_documents_use_sponsor_billto_but_keep_student_details(): void
    {
        $this->student->forceFill(['sponsored' => true, 'sponsor_contact' => 'Sponsor Name',
            'sponsor_full_address' => "Sponsor Street\nSponsor City", 'sponsor_email' => 'sponsor@example.test'])->save();
        $this->get($this->invoiceUrl('html'))->assertOk()->assertSee('Sponsor Name')
            ->assertSee('Sponsor Street')->assertSee('sponsor@example.test')->assertSee('Test Learner');
    }

    public function test_statement_shows_signed_transactions_and_legacy_payment_thanks(): void
    {
        foreach (['-50.01', '-0.01'] as $amount) {
            $row = new StudentTransaction;
            $row->forceFill(['student_id' => $this->student->id, 'transactiontype' => 'Payment',
                'transactionamount' => $amount])->save();
        }
        $this->get("/students/{$this->student->id}/statement")->assertOk()
            ->assertSee('Thank you for your 2 payments')->assertSee('Student note')->assertSee('$100.10')
            ->assertSee('-50.01')->assertSee('-0.01')->assertDontSee('Test-Reference');
    }

    public function test_zero_invoice_amount_uses_legacy_fallback_and_missing_dates_are_safe(): void
    {
        $this->invoice->forceFill(['amount' => '0.00', 'nextdate' => null, 'notes' => null])->save();
        $this->get($this->invoiceUrl('html'))->assertOk()->assertSee('$100.10')
            ->assertSee('12/01/2026')->assertDontSee('Student note');
        $this->student->forceFill(['payment_amount_due' => null, 'next_due_date' => null,
            'start_date' => null, 'graddate' => null])->save();
        $this->get($this->invoiceUrl('html'))->assertOk()->assertSee('Nothing Due');
    }

    public function test_documents_are_authenticated_and_invoice_is_scoped_to_learner(): void
    {
        $other = new Student;
        $other->save();
        foreach (['html', 'current-pdf'] as $format) {
            $this->get("/students/{$other->id}/invoices/{$this->invoice->id}/{$format}")->assertNotFound();
        }
        auth()->logout();
        $this->get($this->invoiceUrl('html'))->assertRedirect('/login');
        $this->get($this->invoiceUrl('current-pdf'))->assertRedirect('/login');
        $this->get("/students/{$this->student->id}/statement/pdf")->assertRedirect('/login');
    }

    public function test_new_invoice_stores_valid_pdf_and_current_download_does_not_replace_original(): void
    {
        $this->postJson("/students/{$this->student->id}/invoices", [
            'nextdate' => '2026-12-01', 'amount' => '100.10', 'invoicereference' => 'New',
        ])->assertOk();
        $new = Invoice::orderByDesc('id')->firstOrFail();
        $this->assertStringStartsWith('%PDF-', $new->invoice_file);
        $this->assertStringContainsString('%%EOF', $new->invoice_file);
        $pdf = $this->get($this->invoiceUrl('current-pdf'))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="invoice-'.$this->invoice->id.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('/Subtype /Image', $pdf->getContent());
        $this->assertSame('%PDF-old-original', $this->invoice->fresh()->invoice_file);
    }

    public function test_pdf_failure_rolls_back_invoice_creation(): void
    {
        $this->mock(PdfRenderer::class)->shouldReceive('render')->once()
            ->andThrow(new RuntimeException('Synthetic PDF rendering failure'));
        $this->postJson("/students/{$this->student->id}/invoices", [
            'nextdate' => '2026-12-01', 'amount' => '1.00', 'invoicereference' => 'Fail',
        ])->assertStatus(500);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame('%PDF-old-original', $this->invoice->fresh()->invoice_file);
    }

    public function test_long_statement_generates_multiple_pages_without_losing_html_rows(): void
    {
        for ($i = 0; $i < 160; $i++) {
            $row = new StudentTransaction;
            $row->forceFill(['student_id' => $this->student->id, 'transactiondate' => '2026-02-01',
                'transactionamount' => '0.01', 'transactionnotes' => "Ledger row {$i}"])->save();
        }
        $data = BillingDocument::data($this->student);
        $this->assertCount(161, $data['transactions']);
        $pdf = app(PdfRenderer::class)->render($data);
        preg_match_all('/\/Type \/Page\b/', $pdf, $pages);
        $this->assertGreaterThanOrEqual(3, count($pages[0]));
        $this->get("/students/{$this->student->id}/statement")->assertOk()
            ->assertSee('Ledger row 0')->assertSee('Ledger row 159');
    }

    public function test_invoice_ui_has_explicit_buttons_modal_notes_and_preview_links(): void
    {
        $this->get("/detail/{$this->student->id}")->assertOk()->assertSee('Create New Invoice')->assertSee('HTML Statement');
        $html = $this->get("/students/{$this->student->id}/invoices")->assertOk()->getContent();
        $this->assertStringContainsString('id="new-invoice-dialog"', $html);
        $this->assertStringContainsString('name="notes" rows="6"', $html);
        $this->assertStringContainsString('Download Current PDF', $html);
        $this->assertStringContainsString('Stored PDF', $html);
    }

    private function invoiceUrl(string $format): string
    {
        return "/students/{$this->student->id}/invoices/{$this->invoice->id}/{$format}";
    }
}
