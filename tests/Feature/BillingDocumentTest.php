<?php

namespace Tests\Feature;

use App\Billing\BillingDocument;
use App\Billing\InvoiceEmail;
use App\Billing\PdfRenderer;
use App\Billing\RecordVersion;
use App\Mail\TemplatedMail;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentStageEnrollment;
use App\Models\StudentTransaction;
use App\Models\User;
use App\Workflows\EmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
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
        Mail::fake();
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
        $this->assertNull($new->emailed_at);
        Mail::assertNothingSent();
    }

    public function test_pdf_failure_rolls_back_invoice_creation(): void
    {
        Mail::fake();
        $this->mock(PdfRenderer::class)->shouldReceive('render')->once()
            ->andThrow(new RuntimeException('Synthetic PDF rendering failure'));
        $this->postJson("/students/{$this->student->id}/invoices", [
            'nextdate' => '2026-12-01', 'amount' => '1.00', 'invoicereference' => 'Fail',
        ])->assertStatus(500);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame('%PDF-old-original', $this->invoice->fresh()->invoice_file);
        Mail::assertNothingSent();
    }

    public function test_invoice_email_uses_profile_sender_all_comma_delimited_learner_addresses_and_exact_stored_pdf(): void
    {
        Mail::fake();
        $sender = auth()->user();
        $sender->forceFill(['email' => 'staff@example.test', 'name' => 'Billing Staff'])->save();
        $this->student->forceFill(['emailaddr' => ' first@example.test, second@example.test , ',
            'sponsored' => true, 'sponsor_email' => 'sponsor@example.test'])->save();
        $this->mock(PdfRenderer::class)->shouldReceive('render')->once()->andReturn("%PDF-new\0\xff");
        $created = $this->postJson("/students/{$this->student->id}/invoices", [
            'nextdate' => '2026-12-01', 'amount' => '100.10', 'invoicereference' => 'Email',
        ])->assertOk()->assertJsonStructure(['email_url']);
        $invoice = Invoice::orderByDesc('id')->firstOrFail();
        Mail::assertNothingSent();
        $this->travelTo(now()->startOfSecond());
        $version = RecordVersion::of($invoice);
        $message = "Hello <Learner>,\nPlease see the attached invoice.";

        $this->postJson($created->json('email_url'), [
            'email_message' => $message, 'from' => 'spoof@example.test', 'to' => 'spoof@example.test',
        ])->assertOk();

        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($invoice, $message) {
            $this->assertSame([['name' => 'Billing Staff', 'address' => 'staff@example.test']], $mail->from);
            $this->assertTrue($mail->hasReplyTo('staff@example.test'));
            $this->assertTrue($mail->hasTo('first@example.test'));
            $this->assertTrue($mail->hasTo('second@example.test'));
            $this->assertCount(2, $mail->to);
            $this->assertSame($message, $mail->textBody);
            $this->assertStringContainsString('&lt;Learner&gt;', $mail->htmlBody);
            $mail->assertHasAttachedData("%PDF-new\0\xff", 'invoice-'.$invoice->id.'.pdf', ['mime' => 'application/pdf']);

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertTrue($invoice->fresh()->emailed_at->equalTo(now()));
        $this->assertSame($version, RecordVersion::of($invoice->fresh()));
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_mail_builds_real_mime_outside_the_retried_request_transaction(): void
    {
        $level = DB::transactionLevel();
        $sent = false;
        Event::listen(MessageSending::class, function (MessageSending $event) use ($level, &$sent) {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertInstanceOf(Email::class, $event->message);
            $this->assertSame(auth()->user()->email, $event->message->getFrom()[0]->getAddress());
            $this->assertSame("A message\nWith two lines\n", $event->message->getTextBody());
            $attachment = $event->message->getAttachments()[0];
            $this->assertSame('%PDF-old-original', $attachment->getBody());
            $this->assertSame('application', $attachment->getMediaType());
            $this->assertSame('pdf', $attachment->getMediaSubtype());
            $sent = true;
        });
        $this->postJson($this->invoiceUrl('email'), ['email_message' => "A message\nWith two lines"])->assertOk();
        $this->assertTrue($sent);
        $this->assertNotNull($this->invoice->fresh()->emailed_at);
    }

    public function test_invoice_template_editor_and_invoice_drafts_use_saved_subject_and_message(): void
    {
        Mail::fake();
        $url = '/email-templates/'.InvoiceEmail::KEY;
        $subject = 'Invoice {invoice_reference} for {learner_name}';
        $body = "Dear {learner_first_name},\nAmount: \${invoice_amount}\nDue: {invoice_due_date}\nBalance: \${balance}\n{sender_name}";
        $this->get('/email-templates')->assertOk()->assertSee('Invoice email');
        $this->get($url)->assertOk()->assertSee('{invoice_reference}')->assertSee('{sender_email}');
        $this->put($url, ['subject' => $subject, 'body' => $body, 'action' => 'preview'])
            ->assertRedirect($url)->assertSessionHas('preview');
        $this->assertDatabaseMissing('email_templates', ['key' => InvoiceEmail::KEY, 'body' => $body]);
        $this->put($url, ['subject' => $subject, 'body' => $body])->assertRedirect($url);
        $this->get("/students/{$this->student->id}/invoices")->assertOk()
            ->assertSee('Refresh from template')->assertDontSee($body);
        $draft = $this->postJson("/students/{$this->student->id}/invoices/email-preview", ['invoice_id' => $this->invoice->id])
            ->assertOk()->assertJsonPath('subject', 'Invoice Test-Reference for Test Learner')->json();

        $this->postJson($this->invoiceUrl('email'), [
            'email_previewed' => true, 'email_subject' => $draft['subject'], 'email_message' => $draft['text'],
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->subjectLine === 'Invoice Test-Reference for Test Learner'
            && $mail->textBody === "Dear Test,\nAmount: $99.99\nDue: December 15, 2026\nBalance: $200.10\n".auth()->user()->name);
        $this->assertDatabaseHas('email_templates', ['key' => InvoiceEmail::KEY, 'subject' => $subject, 'body' => $body]);
        $this->put($url, ['subject' => 'x', 'body' => 'x', 'action' => 'reset'])->assertRedirect($url);
        $this->assertSame(InvoiceEmail::definition()->body, EmailTemplates::get(InvoiceEmail::KEY)->body);
    }

    public function test_new_invoice_preview_fills_draft_fields_and_defers_only_the_id_without_creating_or_sending(): void
    {
        Mail::fake();
        EmailTemplates::get(InvoiceEmail::KEY)->forceFill([
            'subject' => 'Invoice {invoice_id} / {invoice_reference}',
            'body' => "Dear {learner_first_name}, invoice {invoice_id}\nAmount: {invoice_amount}; due: {invoice_due_date}\n{invoice_notes}\nFrom {sender_email}",
        ])->save();
        $data = ['datesent' => '2026-11-19', 'nextdate' => '2026-12-31', 'amount' => '123.45',
            'invoicereference' => 'Draft reference', 'notes' => 'Literal {learner_name} from notes'];
        $preview = $this->postJson("/students/{$this->student->id}/invoices/email-preview", $data)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('subject', 'Invoice {invoice_id} / Draft reference')
            ->assertJsonPath('text', "Dear Test, invoice {invoice_id}\nAmount: 123.45; due: December 31, 2026\nLiteral {learner_name} from notes\nFrom ".auth()->user()->email)
            ->json();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertNull($this->invoice->fresh()->emailed_at);
        Mail::assertNothingSent();

        $created = $this->postJson("/students/{$this->student->id}/invoices", $data)->assertOk();
        $this->student->first_name = 'Changed after preview';
        $this->student->save();
        $body = $preview['text']."\nStaff edit: {keep_literal}";
        $this->postJson($created->json('email_url'), [
            'email_previewed' => true, 'email_subject' => $preview['subject'], 'email_message' => $body,
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->subjectLine === 'Invoice '.$created->json('invoice_id').' / Draft reference'
            && $mail->textBody === str_replace('{invoice_id}', (string) $created->json('invoice_id'), $body));
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_invoice_preview_uses_saved_values_for_existing_records_and_rejects_invalid_drafts(): void
    {
        Mail::fake();
        EmailTemplates::get(InvoiceEmail::KEY)->forceFill([
            'subject' => 'Invoice {invoice_id}',
            'body' => '{invoice_reference}: {invoice_amount}, due {invoice_due_date}',
        ])->save();
        $url = "/students/{$this->student->id}/invoices/email-preview";
        $this->postJson($url, ['invoice_id' => $this->invoice->id, 'amount' => '999.99'])
            ->assertOk()->assertJsonPath('subject', 'Invoice '.$this->invoice->id)
            ->assertJsonPath('text', 'Test-Reference: 99.99, due December 15, 2026');
        $this->postJson($url, ['amount' => '1.001'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['invoice_id' => 0])->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
        $this->postJson($url, ['billing_period' => '999:999'])->assertUnprocessable()->assertJsonValidationErrors('billing_period');
        $this->postJson($this->invoiceUrl('email'), ['email_previewed' => true, 'email_message' => 'Composed message'])
            ->assertUnprocessable()->assertJsonValidationErrors('email_subject');
        $this->assertSame('99.99', $this->invoice->fresh()->amount);
        $this->assertDatabaseCount('invoices', 1);
        Mail::assertNothingSent();
    }

    public function test_invoice_email_substitutes_per_send_edits_without_overwriting_the_shared_template(): void
    {
        Mail::fake();
        $template = EmailTemplates::get(InvoiceEmail::KEY);
        $template->forceFill(['subject' => 'Shared subject {invoice_reference}', 'body' => 'Shared message {learner_name}'])->save();
        $this->student->first_name = '<Learner & Co>';
        $this->student->save();
        $this->postJson($this->invoiceUrl('email'), [
            'email_subject' => 'Custom invoice {invoice_id}',
            'email_message' => "Hello {learner_first_name},\n{invoice_notes}\nFrom {sender_email}",
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            $this->assertSame('Custom invoice '.$this->invoice->id, $mail->subjectLine);
            $this->assertSame("Hello <Learner & Co>,\nLine one\nLine two\nFrom ".auth()->user()->email, $mail->textBody);
            $this->assertStringContainsString('&lt;Learner &amp; Co&gt;', $mail->htmlBody);
            $this->assertStringNotContainsString('<Learner & Co>', $mail->htmlBody);

            return true;
        });
        $this->assertSame('Shared message {learner_name}', $template->fresh()->body);
        $this->assertSame('Shared subject {invoice_reference}', $template->fresh()->subject);
    }

    public function test_invoice_email_rejects_unknown_substitution_fields_without_sending_or_marking_emailed(): void
    {
        Mail::fake();
        $this->postJson($this->invoiceUrl('email'), [
            'email_subject' => 'Invoice {unknown_subject}', 'email_message' => 'Hello {unknown_body}',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email_subject', 'email_message']);
        $this->put('/email-templates/'.InvoiceEmail::KEY, [
            'subject' => 'Invoice {unknown_subject}', 'body' => 'Hello {unknown_body}',
        ])->assertSessionHasErrors(['subject', 'body']);
        $this->assertNull($this->invoice->fresh()->emailed_at);
        $this->assertSame('%PDF-old-original', $this->invoice->fresh()->invoice_file);
        Mail::assertNothingSent();
    }

    public function test_invoice_email_uses_its_own_program_and_stage_and_preserves_zero_and_missing_values(): void
    {
        Mail::fake();
        $prior = new Program;
        $prior->forceFill(['program' => 'Prior', 'programdesc' => 'Prior certificate'])->save();
        $current = new Program;
        $current->forceFill(['program' => 'Current', 'programdesc' => 'Current certificate'])->save();
        $enrollment = StudentEnrollment::create(['student_id' => $this->student->id, 'program_id' => $prior->id,
            'mode' => 'staged', 'status' => 'completed', 'started_on' => '2025-01-01', 'ended_on' => '2025-06-01']);
        $stage = StudentStageEnrollment::create(['enrollment_id' => $enrollment->id, 'stage' => 1,
            'started_on' => '2025-01-01', 'completed_on' => '2025-06-01', 'service_days' => 100]);
        $this->student->program_id = $current->id;
        $this->student->save();
        $this->invoice->forceFill(['enrollment_id' => $enrollment->id, 'stage_enrollment_id' => $stage->id,
            'amount' => '0.00', 'datesent' => '2025-01-01'])->save();
        $this->postJson($this->invoiceUrl('email'), [
            'email_subject' => '{program} / Stage {stage}', 'email_message' => 'Amount [{invoice_amount}], issued {invoice_date}',
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->subjectLine === 'Prior certificate / Stage 1'
            && $mail->textBody === 'Amount [0.00], issued January 1, 2025');

        $this->invoice->forceFill(['amount' => null, 'nextdate' => null])->save();
        $this->postJson($this->invoiceUrl('email'), [
            'email_message' => 'Amount [{invoice_amount}], due [{invoice_due_date}]',
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->textBody === 'Amount [], due []');
        Mail::assertSentCount(2);
    }

    public function test_immediate_mail_error_keeps_invoice_and_pdf_and_email_only_retry_records_send_date(): void
    {
        $version = RecordVersion::of($this->invoice->fresh());
        $manager = Mail::getFacadeRoot();
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new TransportException('Synthetic SMTP failure'));
        Mail::partialMock()->shouldReceive('to')->once()->with(['learner@example.test'])->andReturn($pending);
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Please see your invoice.'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNull($this->invoice->fresh()->emailed_at);
        $this->assertSame('%PDF-old-original', $this->invoice->fresh()->invoice_file);
        $this->assertSame($version, RecordVersion::of($this->invoice->fresh()));
        $this->assertDatabaseCount('invoices', 1);

        Mail::swap($manager);
        Mail::fake();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Please see your invoice.'])->assertOk();
        Mail::assertSentCount(1);
        $this->assertNotNull($this->invoice->fresh()->emailed_at);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_email_addresses_are_split_on_commas_without_silently_filtering_tokens(): void
    {
        Mail::fake();
        $this->student->emailaddr = 'first@example.test, not-an-address';
        $this->student->save();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('first@example.test')
            && $mail->hasTo('not-an-address') && count($mail->to) === 2);
    }

    public function test_logging_mailer_and_logging_fallback_do_not_mark_invoices_emailed(): void
    {
        foreach (['log', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertNull($this->invoice->fresh()->emailed_at);
        }
    }

    public function test_cancelled_mail_does_not_record_a_send_date(): void
    {
        Event::listen(MessageSending::class, fn () => false);
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNull($this->invoice->fresh()->emailed_at);
    }

    public function test_invoice_email_requires_a_message_stored_pdf_and_nonempty_sender_and_recipient(): void
    {
        Mail::fake();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('email_message');
        $sender = auth()->user();
        $sender->email = null;
        $sender->save();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $sender->email = 'staff@example.test';
        $sender->save();
        $this->student->emailaddr = ', ,';
        $this->student->save();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->student->emailaddr = 'learner@example.test';
        $this->student->save();
        $this->invoice->invoice_file = null;
        $this->invoice->save();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNull($this->invoice->fresh()->emailed_at);
        Mail::assertNothingSent();
    }

    public function test_invoice_email_requires_billing_access_editing_lock_and_correct_learner(): void
    {
        Mail::fake();
        $other = new Student;
        $other->save();
        $this->obtainEditingLock($other);
        $this->postJson("/students/{$other->id}/invoices/{$this->invoice->id}/email", ['email_message' => 'Invoice attached.'])
            ->assertNotFound();
        $this->postJson("/students/{$other->id}/invoices/email-preview", ['invoice_id' => $this->invoice->id])->assertNotFound();
        $this->withHeader('X-Student-Lock', 'wrong')->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])
            ->assertStatus(423);
        $this->postJson("/students/{$this->student->id}/invoices/email-preview", [])->assertStatus(423);
        $this->actingAs(User::factory()->create(['access_level' => 'records']));
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])->assertForbidden();
        $this->postJson("/students/{$this->student->id}/invoices/email-preview", [])->assertForbidden();
        auth()->logout();
        $this->postJson($this->invoiceUrl('email'), ['email_message' => 'Invoice attached.'])->assertUnauthorized();
        $this->postJson("/students/{$this->student->id}/invoices/email-preview", [])->assertUnauthorized();
        Mail::assertNothingSent();
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
        $detail = $this->get("/detail/{$this->student->id}")->assertOk()->assertSee('Create New Invoice')->assertSee('HTML Statement');
        $this->assertMatchesRegularExpression(
            '/data-create-invoice>Create New Invoice<\/button>\s*<form class="inline" method="get" action="'
                .preg_quote(route('statements.pdf', $this->student), '/').'" target="_blank" rel="noopener">\s*<button type="submit">Statement PDF<\/button>\s*<\/form>/',
            $detail->getContent(),
        );
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
