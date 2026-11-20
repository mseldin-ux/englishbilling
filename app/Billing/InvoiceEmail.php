<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentStageEnrollment;
use App\Models\User;
use App\Workflows\TemplateDefinition;

final class InvoiceEmail
{
    public const KEY = 'invoice-email';

    public static function definition(): TemplateDefinition
    {
        return new TemplateDefinition(
            key: self::KEY,
            name: 'Invoice email',
            description: 'Used when staff choose to email a stored invoice PDF. Staff can customize the subject and message for each send.',
            placeholders: [
                'learner_first_name' => 'Learner first name',
                'learner_name' => 'Learner full name',
                'learner_email' => 'Learner email address(es) on the student record',
                'cohort' => 'Cohort (current learner record)',
                'program' => 'Invoice enrollment program, or current learner program for an unattributed legacy invoice',
                'stage' => 'Invoice stage number (blank for a whole-program or legacy invoice)',
                'invoice_id' => 'Invoice ID',
                'invoice_reference' => 'Saved invoice reference',
                'invoice_amount' => 'Saved invoice amount, two decimal places without a currency symbol (blank if not recorded)',
                'invoice_date' => 'Saved invoice sent/issue date',
                'invoice_due_date' => 'Saved invoice due date',
                'invoice_notes' => 'Saved invoice notes',
                'invoice_urgent_message' => 'Saved invoice urgent message',
                'balance' => 'Current learner balance, two decimal places without a currency symbol (blank if not recorded)',
                'sender_name' => 'Logged-in user name',
                'sender_email' => 'Logged-in user profile email address',
                'today' => 'Today\'s date',
            ],
            required: [],
            subject: 'Invoice {invoice_id}',
            body: <<<'TEXT'
Dear {learner_first_name},

Please find your invoice attached.

Kind regards,
{sender_name}
TEXT,
        );
    }

    /** @return array<string, string> */
    public static function variables(Student $student, Invoice $invoice, User $sender): array
    {
        $program = $invoice->enrollment_id
            ? StudentEnrollment::findOrFail($invoice->enrollment_id)->program
            : $student->program;
        $stage = $invoice->stage_enrollment_id
            ? StudentStageEnrollment::findOrFail($invoice->stage_enrollment_id)->stage
            : null;

        return [
            'learner_first_name' => $student->first_name ?: $student->displayName(),
            'learner_name' => $student->displayName(),
            'learner_email' => $student->emailaddr ?? '',
            'cohort' => $student->cohort ?? '',
            'program' => $program?->programdesc ?: ($program?->program ?? ''),
            'stage' => $stage === null ? '' : (string) $stage,
            'invoice_id' => $invoice->exists ? (string) $invoice->id : '{invoice_id}',
            'invoice_reference' => $invoice->invoicereference ?? '',
            'invoice_amount' => $invoice->amount === null ? '' : Money::cents($invoice->amount),
            'invoice_date' => $invoice->datesent?->format('F j, Y') ?? '',
            'invoice_due_date' => $invoice->nextdate?->format('F j, Y') ?? '',
            'invoice_notes' => $invoice->notes ?? '',
            'invoice_urgent_message' => $invoice->urgent_message ?? '',
            'balance' => $student->balance === null ? '' : Money::cents($student->balance),
            'sender_name' => $sender->name,
            'sender_email' => $sender->email ?? '',
            'today' => now()->format('F j, Y'),
        ];
    }
}
