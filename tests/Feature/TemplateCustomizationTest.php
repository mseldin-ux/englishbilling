<?php

namespace Tests\Feature;

use App\Billing\DocumentTemplates;
use App\Billing\InvoiceEmail;
use App\Mail\TemplatedMail;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
use App\Workflows\EmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TemplateCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_email_renders_safe_placeholders_and_a_plain_text_version_with_links(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = '/email-templates/course-completion-invitation';
        $body = '<p>Hello {learner_first_name},</p><p><a href="{form_link}">Complete your form</a></p>';
        $this->put($url, ['subject' => 'Hello {learner_first_name}', 'body' => $body, 'is_html' => '1', 'action' => 'preview'])->assertRedirect();
        $this->get($url)->assertOk()->assertSee('sandbox', false)->assertSee('HTML email preview')->assertSee('Plain-text version');
        $this->assertDatabaseMissing('email_templates', ['body' => $body]);
        $this->put($url, ['subject' => 'Hello {learner_first_name}', 'body' => $body, 'is_html' => '1'])->assertRedirect();
        $rendered = EmailTemplates::render('course-completion-invitation', [
            'learner_first_name' => '<Ada & "Bob">', 'form_link' => 'https://example.org/form?x=1&y=2',
        ]);
        $this->assertStringContainsString('&lt;Ada &amp; &quot;Bob&quot;&gt;', $rendered['html']);
        $this->assertStringContainsString('<a href="https://example.org/form?x=1&amp;y=2">', $rendered['html']);
        $this->assertStringContainsString('<Ada & "Bob">', $rendered['text']);
        $this->assertStringContainsString('Complete your form (https://example.org/form?x=1&y=2)', $rendered['text']);
        $this->assertStringNotContainsString('<a ', $rendered['text']);
        $this->put($url, ['subject' => 'Original', 'body' => '{form_link}', 'is_html' => '1', 'action' => 'reset'])->assertRedirect();
        $this->assertFalse(EmailTemplates::get('course-completion-invitation')->is_html);
    }

    public function test_invoice_html_source_survives_preview_edit_and_send(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'sender@example.org']);
        $this->actingAs($user);
        $student = new Student;
        $student->forceFill(['first_name' => 'A & B', 'emailaddr' => 'learner@example.org'])->save();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'invoice_file' => '%PDF-stored', 'amount' => '42.00'])->save();
        $this->obtainEditingLock($student);
        EmailTemplates::get(InvoiceEmail::KEY)->forceFill(['is_html' => true, 'subject' => 'Invoice {invoice_id}',
            'body' => '<p>Hello {learner_first_name}</p><a href="https://example.org/pay">Pay here</a>'])->save();
        $preview = $this->postJson("/students/{$student->id}/invoices/email-preview", ['invoice_id' => $invoice->id])
            ->assertOk()->assertJsonPath('is_html', true)->json();
        $this->assertStringContainsString('<p>Hello A &amp; B</p>', $preview['source']);
        $this->postJson("/students/{$student->id}/invoices/{$invoice->id}/email", [
            'email_previewed' => true, 'email_is_html' => true,
            'email_subject' => $preview['subject'], 'email_message' => $preview['source'].'<p>Edited</p>',
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => str_contains($mail->htmlBody, '<p>Hello A &amp; B</p>')
            && str_contains($mail->htmlBody, '<p>Edited</p>') && str_contains($mail->textBody, 'Pay here (https://example.org/pay)'));
    }

    public function test_new_invoice_html_draft_keeps_its_format_when_the_shared_template_changes(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['email' => 'sender@example.org']));
        $student = new Student;
        $student->forceFill(['first_name' => 'Sample', 'emailaddr' => 'learner@example.org'])->save();
        $this->obtainEditingLock($student);
        $template = EmailTemplates::get(InvoiceEmail::KEY);
        $template->forceFill(['is_html' => true, 'subject' => 'Invoice {invoice_id}',
            'body' => '<p>Invoice {invoice_id} for {learner_first_name}</p>'])->save();
        $draft = $this->postJson("/students/{$student->id}/invoices/email-preview", ['amount' => '42.00'])
            ->assertOk()->assertJsonPath('is_html', true)->json();
        $this->assertSame('<p>Invoice {invoice_id} for Sample</p>', $draft['source']);
        $template->forceFill(['is_html' => false, 'body' => 'A new plain text template'])->save();
        $created = $this->postJson("/students/{$student->id}/invoices", [
            'amount' => '42.00', 'nextdate' => '2026-12-01', 'invoicereference' => 'Sample',
        ])->assertOk();
        $this->postJson($created->json('email_url'), [
            'email_previewed' => true, 'email_is_html' => $draft['is_html'],
            'email_subject' => $draft['subject'], 'email_message' => $draft['source'].'<p>Edited</p>',
        ])->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->subjectLine === 'Invoice '.$created->json('invoice_id')
            && str_contains($mail->htmlBody, '<p>Invoice '.$created->json('invoice_id').' for Sample</p><p>Edited</p>')
            && ! str_contains($mail->htmlBody, '&lt;p&gt;'));
    }

    public function test_document_templates_are_admin_only_and_have_unsaved_html_and_pdf_previews(): void
    {
        $staff = User::factory()->create(['is_admin' => false]);
        $this->actingAs($staff)->get('/admin/document-templates')->assertForbidden();
        $this->put('/admin/document-templates/invoice', ['body' => 'No access'])->assertForbidden();
        $this->get('/admin/document-templates/invoice/preview-pdf')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/document-templates')->assertOk()->assertSee('invoice')->assertSee('statement');
        $this->get('/admin/document-templates/not-real')->assertNotFound();
        $body = '<style>{default_styles}</style><h1>Draft invoice for {learner_name}</h1><table>{transaction_rows}</table>';
        $this->put('/admin/document-templates/invoice', ['action' => 'preview', 'body' => $body])->assertRedirect();
        $this->assertDatabaseCount('document_templates', 0);
        $this->get('/admin/document-templates/invoice')->assertOk()->assertSee('Draft invoice for Sample Learner')->assertSee('sandbox', false);
        $pdf = $this->get('/admin/document-templates/invoice/preview-pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->put('/admin/document-templates/invoice', ['action' => 'preview', 'body' => '<h1>Unstyled</h1>'])->assertRedirect();
        $this->get('/admin/document-templates/invoice')->assertOk()->assertDontSee('.document-header { width:', false);
        $this->put('/admin/document-templates/invoice', ['body' => '{invalid_placeholder}'])->assertSessionHasErrors('body');
    }

    public function test_saved_document_layouts_apply_to_current_documents_and_new_pdfs_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $student = new Student;
        $student->forceFill(['first_name' => '<Test>', 'last_name' => 'Learner', 'balance' => '100.00'])->save();
        $invoice = new Invoice;
        $invoice->forceFill(['student_id' => $student->id, 'invoice_file' => '%PDF-original',
            'amount' => '100.00', 'invoicereference' => 'REF'])->save();
        $transaction = new StudentTransaction;
        $transaction->forceFill(['student_id' => $student->id, 'transactiondate' => '2026-01-01',
            'transactionamount' => '100.00', 'transactiontype' => 'Fee', 'transactionnotes' => '<unsafe>'])->save();
        $this->obtainEditingLock($student);
        $invoiceBody = '<style>{default_styles}</style><h1>Custom invoice {invoice_reference}</h1><p>{learner_name}</p>'
            .'<table><tbody>{transaction_rows}</tbody></table><p>{balance} {amount_due}</p>';
        $statementBody = '<h1>Custom statement</h1><p>{learner_name}</p><p>{balance}</p>';
        $this->put('/admin/document-templates/invoice', ['body' => $invoiceBody])->assertRedirect();
        $this->put('/admin/document-templates/statement', ['body' => $statementBody])->assertRedirect();
        $response = $this->get("/students/{$student->id}/invoices/{$invoice->id}/html")->assertOk()
            ->assertHeader('Content-Security-Policy')->assertSee('Custom invoice REF')->assertSee('&lt;Test&gt;', false)
            ->assertSee('&lt;unsafe&gt;', false)->assertDontSee('<unsafe>', false)->assertSee('100.00');
        $this->assertMatchesRegularExpression("/script-src 'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'));
        preg_match("/script-src 'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $nonce);
        $response->assertSee('nonce="'.$nonce[1].'"', false);
        $this->get("/students/{$student->id}/statement")->assertOk()->assertSee('Custom statement')->assertDontSee('Custom invoice');
        $current = $this->get("/students/{$student->id}/invoices/{$invoice->id}/current-pdf")->assertOk();
        $this->assertStringStartsWith('%PDF-', $current->getContent());
        $this->assertSame('%PDF-original', $invoice->fresh()->invoice_file);
        $this->postJson("/students/{$student->id}/invoices", ['amount' => '99.00', 'nextdate' => '2026-12-01',
            'invoicereference' => 'New'])->assertOk();
        $new = Invoice::orderByDesc('id')->firstOrFail();
        $this->assertStringStartsWith('%PDF-', $new->invoice_file);
        $this->put('/admin/document-templates/invoice', ['action' => 'reset'])->assertRedirect();
        $this->assertNull(DocumentTemplate::where('key', 'invoice')->first());
        $this->get("/students/{$student->id}/invoices/{$invoice->id}/html")->assertOk()->assertDontSee('Custom invoice');
        $this->assertSame($statementBody, DocumentTemplates::saved('statement')->body);
    }
}
