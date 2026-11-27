<?php

namespace App\Billing;

use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class DocumentTemplates
{
    public const KEYS = ['invoice', 'statement'];

    public const PLACEHOLDERS = [
        'default_styles' => 'Current default document CSS (inside a style element)',
        'logo_url' => 'CGMS logo URL (use in an image src)',
        'title' => 'Invoice or statement title',
        'document_info_heading' => 'Invoice Info or Statement Info heading',
        'document_info' => 'Invoice identifier or statement as-of information',
        'invoice_id' => 'Invoice ID (blank for statements)',
        'invoice_reference' => 'Invoice reference (blank for statements)',
        'invoice_batch' => 'Invoice batch (blank for statements)',
        'payment_method' => 'Learner payment method',
        'generated_date' => 'Document generation date',
        'learner_name' => 'Adult learner name',
        'learner_address' => 'Learner street address',
        'learner_address_line_2' => 'Learner address line 2 and a line break, when present',
        'learner_city' => 'Learner city',
        'learner_state' => 'Learner state',
        'learner_zip' => 'Learner ZIP',
        'learner_email' => 'Learner email',
        'student_id' => 'Student ID as shown on the existing document',
        'cohort' => 'Learner cohort',
        'start_date' => 'Learner start date',
        'graduation_date' => 'Expected graduation date',
        'status' => 'Learner status',
        'recipient_heading' => 'Bill to or account heading',
        'recipient' => 'Billing recipient',
        'billing_address' => 'Billing address',
        'billing_email' => 'Billing email',
        'billing_details' => 'Invoice payment method, reference and batch information',
        'transaction_heading' => 'Transactions heading for statements',
        'transaction_rows' => 'All ledger rows, including long-note continuation rows in PDFs',
        'balance' => 'Current balance',
        'amount_due' => 'Amount due',
        'due_date' => 'Due date or payment thank-you message',
        'urgent_message' => 'Invoice urgent message, when present',
        'note' => 'Invoice or statement note, when present',
        'note_text' => 'Invoice or statement note without its label',
        'urgent_message_text' => 'Invoice urgent message without its markup',
    ];

    public static function original(): string
    {
        return file_get_contents(resource_path('views/documents/template.html'));
    }

    public static function saved(string $key): ?DocumentTemplate
    {
        return DocumentTemplate::where('key', $key)->first();
    }

    public static function unknown(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys(self::PLACEHOLDERS))));
    }

    public static function render(string $body, array $data, bool $pdf): string
    {
        $student = $data['student'];
        $invoice = $data['invoice'];
        $date = $data['generatedAt'];
        $text = [
            'logo_url' => $pdf ? public_path('images/cgms-logo.png') : asset('images/cgms-logo.png'),
            'title' => $data['title'],
            'document_info_heading' => $invoice ? 'Invoice Info' : 'Statement Info',
            'document_info' => $invoice
                ? $invoice->id.'-'.$student->last_name.'-'.$date->format('n-j-Y')
                : 'Account information believed to be accurate as of '.$date->format('n/j/Y'),
            'invoice_id' => $invoice?->id, 'invoice_reference' => $data['reference'],
            'invoice_batch' => $invoice?->batchid, 'payment_method' => $student->pmt_type,
            'generated_date' => $date->format('n/j/Y'),
            'learner_name' => $student->displayName(),
            'learner_address' => $student->streetaddress,
            'learner_city' => $student->city, 'learner_state' => $student->state, 'learner_zip' => $student->zipcode,
            'learner_email' => $student->emailaddr, 'student_id' => $invoice ? $student->id : $student->stid,
            'cohort' => $student->cohort, 'start_date' => $student->start_date?->format('n/j/Y'),
            'graduation_date' => $student->graddate?->format('n/j/Y'), 'status' => $student->status(),
            'recipient_heading' => $invoice ? 'BILL TO:' : 'ACCOUNT:',
            'recipient' => $data['recipient'], 'billing_address' => $data['address'], 'billing_email' => $data['email'],
            'balance' => $data['balance'], 'amount_due' => $data['amountDue'], 'due_date' => $data['due'],
            'note_text' => $data['notes'], 'urgent_message_text' => $invoice?->urgent_message,
        ];
        $values = array_map(fn ($value) => e((string) $value), $text);
        $values['learner_address_line_2'] = $student->address_line_2 ? e($student->address_line_2).'<br>' : '';
        $values['default_styles'] = file_get_contents(public_path('css/documents.css'));
        $values['billing_details'] = view('documents.billing-details', $data)->render();
        $values['transaction_heading'] = $invoice ? '' : '<h3>TRANSACTIONS</h3>';
        $values['transaction_rows'] = view('documents.transaction-rows', [...$data, 'pdf' => $pdf])->render();
        $values['urgent_message'] = $invoice?->urgent_message
            ? '<p class="urgent multiline">'.e($invoice->urgent_message).'</p>' : '';
        $values['note'] = $data['notes'] ? '<p class="multiline"><b>NOTE:</b> '.e($data['notes']).'</p>' : '';

        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($match) => $values[$match[1]] ?? $match[0], $body);
    }

    public static function sample(string $key): array
    {
        $student = new Student;
        $student->forceFill(['id' => 123, 'stid' => 'AL-123', 'first_name' => 'Sample', 'last_name' => 'Learner',
            'streetaddress' => '1 Example Street', 'city' => 'Raleigh', 'state' => 'NC', 'zipcode' => '27615',
            'emailaddr' => 'learner@example.org', 'cohort' => 'Example', 'start_date' => '2025-01-01',
            'graddate' => '2027-01-01']);
        $invoice = $key === 'invoice' ? (new Invoice)->forceFill(['id' => 456, 'invoicereference' => 'Example reference']) : null;
        $transaction = (new StudentTransaction)->forceFill(['transactiondate' => '2026-01-01',
            'transactiontype' => 'Fee', 'transactionnotes' => 'Sample tuition', 'transactionamount' => '100.00']);

        return ['student' => $student, 'invoice' => $invoice, 'transactions' => new Collection([$transaction]),
            'title' => $invoice ? 'Invoice' : 'STATEMENT', 'recipient' => 'Sample Learner',
            'address' => "1 Example Street\nRaleigh, NC 27615", 'email' => 'learner@example.org',
            'amountDue' => '$100.00', 'due' => '12/01/2026', 'balance' => '100.00', 'notes' => 'Sample note',
            'reference' => $invoice?->invoicereference, 'generatedAt' => CarbonImmutable::parse('2026-11-27')];
    }
}
