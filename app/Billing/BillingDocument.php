<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Student;
use Brick\Math\BigDecimal;

final class BillingDocument
{
    public static function data(Student $student, ?Invoice $invoice = null): array
    {
        $transactions = $student->transactions()->orderBy('transactiondate')->orderBy('id')->get();
        $payments = $transactions->filter(fn ($row) => BigDecimal::of($row->transactionamount ?? '0')->isNegative())->count();
        $amount = $invoice?->amount;
        // The legacy invoice falls back to the learner's next payment when its amount is zero.
        if ($amount === null || BigDecimal::of($amount)->isZero()) {
            $amount = $student->payment_amount_due;
        }
        $amount = Money::cents($amount ?? '0');
        $due = $invoice ? ($invoice->nextdate ?? $student->next_due_date)?->format('m/d/Y') : $student->next_due_date?->format('m/d/Y');
        if (! $invoice && $payments > 0) {
            $due = $payments === 1 ? 'Thank you for your payment' : "Thank you for your {$payments} payments";
        }
        $address = trim(($student->streetaddress ?? '')."\n"
            .($student->address_line_2 ? $student->address_line_2."\n" : '')
            .trim(($student->city ?? '').', '.($student->zipcode ?? ''), ', ').' '.($student->country ?? ''));

        return [
            'student' => $student, 'invoice' => $invoice, 'transactions' => $transactions,
            'title' => $invoice ? 'Invoice' : 'STATEMENT',
            'recipient' => $student->sponsored ? $student->sponsor_contact : $student->displayName(),
            'address' => $student->sponsored ? $student->sponsor_full_address : $address,
            'email' => $student->sponsored ? $student->sponsor_email : $student->emailaddr,
            'amountDue' => BigDecimal::of($amount)->isZero() ? 'Nothing Due' : '$'.$amount,
            'due' => $due, 'balance' => Money::cents($student->balance ?? '0'),
            'notes' => $invoice ? $invoice->notes : $student->invoice_notes,
            'reference' => $invoice?->invoicereference,
            'generatedAt' => now(),
        ];
    }
}
