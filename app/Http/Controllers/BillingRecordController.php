<?php

namespace App\Http\Controllers;

use App\Billing\BillingDocument;
use App\Billing\BillingRules;
use App\Billing\EnrollmentAttribution;
use App\Billing\InvoiceEmail;
use App\Billing\Ledger;
use App\Billing\PdfRenderer;
use App\Billing\RecordVersion;
use App\Mail\TemplatedMail;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Workflows\EmailTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;

class BillingRecordController extends Controller
{
    private const MODELS = ['transactions' => StudentTransaction::class,
        'invoices' => Invoice::class, 'loas' => LeaveOfAbsence::class];

    public function index(Student $student, string $kind)
    {
        $this->model($kind);
        $query = $student->{$kind}();
        if ($kind === 'invoices') {
            $query->select(Invoice::METADATA_COLUMNS)
                ->selectRaw('CASE WHEN LENGTH(invoice_file) > 0 THEN 1 ELSE 0 END AS has_pdf')->orderBy('id');
        } else {
            $query->orderBy($kind === 'loas' ? 'loa_start' : 'transactiondate')->orderBy('id');
        }
        $records = $query->get();

        return view('students.records', ['student' => $student, 'kind' => $kind,
            'records' => $records, 'summary' => $kind === 'transactions' ? Ledger::summary($records) : [],
            'types' => BillingRules::TYPES]);
    }

    public function store(Request $request, Student $student, string $kind)
    {
        $model = $this->model($kind);
        $data = $request->validate($this->rules($kind, true));
        $created = DB::transaction(function () use ($student, $model, $kind, $data, $request) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $data = EnrollmentAttribution::data($locked, $data);
            if ($kind !== 'loas') {
                $data = $this->invoiceAttribution($data, $locked);
            }
            $this->checkInvoiceLink($data, $locked);
            $record = new $model;
            $record->forceFill($data);
            $record->student_id = $locked->id;
            if ($kind === 'invoices') {
                $record->datesent ??= now();
                $record->user_sent = $request->user()->username;
            }
            $record->save();
            if ($kind === 'invoices') {
                $record->invoice_file = app(PdfRenderer::class)->render(BillingDocument::data($locked, $record));
                $record->save();
            }
            if ($kind === 'transactions') {
                Ledger::recalculate($locked);
            }

            return $record;
        });

        if ($kind === 'invoices' && $request->expectsJson()) {
            return response()->json(['message' => 'Invoice '.$created->id.' created.',
                'invoice_id' => $created->id,
                'email_url' => route('invoices.email', [$student, $created->id])]);
        }

        return $this->saved($request, 'Record created.');
    }

    public function update(Request $request, Student $student, string $kind, int $record)
    {
        $model = $this->model($kind);
        $data = $request->validate(['version' => ['required', 'string'], ...$this->rules($kind, false)]);
        $version = $data['version'];
        unset($data['version']);
        $updated = DB::transaction(function () use ($student, $model, $kind, $record, $data, $version) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $row = $model::where('student_id', $locked->id)->whereKey($record)->lockForUpdate()->firstOrFail();
            RecordVersion::check($row, $version);
            $data = EnrollmentAttribution::data($locked, $data, $row);
            if ($kind !== 'loas') {
                $merged = [...$row->getAttributes(), ...$data];
                $this->invoiceAttribution($merged, $locked);
                if ($kind === 'invoices' && $row->transactions()->get(['enrollment_id', 'stage_enrollment_id'])
                    ->contains(fn ($transaction) => (string) $transaction->enrollment_id !== (string) $merged['enrollment_id']
                        || (string) $transaction->stage_enrollment_id !== (string) $merged['stage_enrollment_id'])) {
                    throw ValidationException::withMessages(['billing_period' => 'Linked transactions must keep the same enrollment/stage as their invoice.']);
                }
            }
            // Retain a known imported mismatch when editing unrelated fields; new links must match.
            if (array_key_exists('invoice_id', $data) && (string) $data['invoice_id'] !== (string) $row->invoice_id) {
                $this->checkInvoiceLink($data, $locked);
            }
            foreach ($data as $field => $value) {
                $current = $row->getAttribute($field);
                if ($current instanceof \DateTimeInterface && $current->format('Y-m-d') === $value) {
                    unset($data[$field]);
                }
            }
            if ($kind === 'loas') {
                $start = array_key_exists('loa_start', $data) ? $data['loa_start'] : $row->loa_start?->format('Y-m-d');
                $end = array_key_exists('loa_end', $data) ? $data['loa_end'] : $row->loa_end?->format('Y-m-d');
                if ((array_key_exists('loa_start', $data) || array_key_exists('loa_end', $data)) && $start && $end && $end < $start) {
                    throw ValidationException::withMessages(['loa_end' => 'LOA end must be on or after its start.']);
                }
            }
            $row->forceFill($data)->save();
            if ($kind === 'transactions') {
                Ledger::recalculate($locked);
            }

            return $row->fresh();
        });

        return $request->expectsJson()
            ? response()->json(['message' => 'Record saved.', 'version' => RecordVersion::of($updated)])
            : back()->with('status', 'Record saved.');
    }

    public function destroy(Request $request, Student $student, string $kind, int $record)
    {
        $model = $this->model($kind);
        $data = $request->validate(['version' => ['required', 'string']]);
        DB::transaction(function () use ($student, $model, $kind, $record, $data) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $row = $model::where('student_id', $locked->id)->whereKey($record)->lockForUpdate()->firstOrFail();
            RecordVersion::check($row, $data['version']);
            if ($kind === 'invoices') {
                DB::table('studenttransactions')->where('invoice_id', $row->id)->update(['invoice_id' => null]);
            }
            $row->delete();
            if ($kind === 'transactions') {
                Ledger::recalculate($locked);
            }
        });

        return $this->saved($request, 'Record deleted.');
    }

    public function pdf(Student $student, int $invoice)
    {
        $record = Invoice::where('student_id', $student->id)->findOrFail($invoice);
        abort_if($record->invoice_file === null || $record->invoice_file === '', 404, 'No historical PDF is stored for this invoice.');

        return response($record->invoice_file, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-'.$record->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function emailPreview(Request $request, Student $student)
    {
        $data = $request->validate([
            'invoice_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...$this->rules('invoices', false),
        ]);
        if (! empty($data['invoice_id'])) {
            $record = Invoice::where('student_id', $student->id)->select(Invoice::METADATA_COLUMNS)->findOrFail($data['invoice_id']);
        } else {
            unset($data['invoice_id']);
            $record = new Invoice;
            $record->forceFill(EnrollmentAttribution::data($student, $data));
            $record->datesent ??= now();
        }
        $message = EmailTemplates::render(InvoiceEmail::KEY, InvoiceEmail::variables($student, $record, $request->user()));

        return response()->json(['subject' => $message['subject'], 'text' => $message['text'],
            'from' => $request->user()->email ?? '', 'to' => $student->emailaddr ?? ''])
            ->header('Cache-Control', 'private, no-store');
    }

    public function email(Request $request, Student $student, int $invoice)
    {
        $data = $request->validate([
            'email_previewed' => ['sometimes', 'boolean'],
            'email_subject' => [Rule::requiredIf($request->boolean('email_previewed')), 'string', 'max:255'],
            'email_message' => ['required', 'string', 'max:20000'],
        ]);
        $record = Invoice::where('student_id', $student->id)->findOrFail($invoice);
        $sender = $request->user();
        $recipients = array_values(array_filter(array_map('trim', explode(',', $student->emailaddr ?? '')),
            fn (string $address) => $address !== ''));
        if (! $sender->email || $recipients === []) {
            throw ValidationException::withMessages([
                'email' => 'An email address is needed in your user profile and the learner record. The invoice remains saved.',
            ]);
        }
        if ($record->invoice_file === null || $record->invoice_file === '') {
            throw ValidationException::withMessages(['email' => 'This invoice has no stored PDF to email.']);
        }
        $template = EmailTemplates::get(InvoiceEmail::KEY)->replicate();
        $template->forceFill(['subject' => $data['email_subject'] ?? $template->subject, 'body' => $data['email_message']]);
        $previewed = $request->boolean('email_previewed');
        $problems = $previewed ? [] : EmailTemplates::problems(InvoiceEmail::KEY, $template->subject, $template->body);
        if ($problems !== []) {
            throw ValidationException::withMessages([
                'email_subject' => $problems['subject'] ?? [],
                'email_message' => $problems['body'] ?? [],
            ]);
        }
        // A composed preview is literal text; only its deferred invoice ID is still a placeholder.
        $variables = $previewed ? ['invoice_id' => (string) $record->id] : InvoiceEmail::variables($student, $record, $sender);
        $message = EmailTemplates::render(InvoiceEmail::KEY, $variables, $template);
        if (! Mail::isFake()) {
            $this->checkDeliveryTransport(config('mail.default'));
        }

        $mail = (new TemplatedMail($message['subject'], $message['text'], $message['html'], [
                ['name' => 'invoice-'.$record->id.'.pdf', 'data' => $record->invoice_file],
            ]))->from($sender->email, $sender->name)->replyTo($sender->email, $sender->name);
        try {
            $sent = Mail::to($recipients)->send($mail);
        } catch (TransportExceptionInterface|RfcComplianceException $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'email' => 'The email could not be confirmed sent. Check the email addresses and mail configuration before retrying. The invoice remains saved.',
            ]);
        }
        if ($sent === null && ! Mail::isFake()) {
            Log::warning('Invoice email was cancelled before sending.', ['invoice_id' => $record->id]);
            throw ValidationException::withMessages(['email' => 'The email was cancelled before sending. The invoice remains saved.']);
        }
        $record->emailed_at = now();
        $record->save();

        return $this->saved($request, 'Invoice email submitted to the mail server.');
    }

    private function checkDeliveryTransport(string $mailer, array $visited = []): void
    {
        $transport = config("mail.mailers.{$mailer}.transport");
        if (in_array($mailer, $visited, true) || $transport === 'log'
            || ($transport === 'array' && ! app()->environment('testing'))) {
            throw ValidationException::withMessages([
                'email' => 'Email delivery is not configured for sending invoices. Configure a delivery mailer without a log/array fallback. The invoice remains saved.',
            ]);
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach (config("mail.mailers.{$mailer}.mailers", []) as $child) {
                $this->checkDeliveryTransport($child, [...$visited, $mailer]);
            }
        }
    }

    private function model(string $kind): string
    {
        abort_unless(isset(self::MODELS[$kind]), 404);

        return self::MODELS[$kind];
    }

    private function rules(string $kind, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return match ($kind) {
            'transactions' => [
                ...EnrollmentAttribution::rules(true),
                'transactiondate' => [$presence, ...($creating ? [] : ['nullable']), 'date_format:Y-m-d'],
                'processeddate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'transactiontype' => [$presence, ...($creating ? [] : ['nullable']), 'string', 'max:255'],
                'transactionamount' => [$presence, ...($creating ? [] : ['nullable']), ...BillingRules::money()],
                'transactionnotes' => ['sometimes', 'nullable', 'string', 'max:65535'],
                'invoice_id' => ['sometimes', 'nullable', 'integer'],
            ],
            'invoices' => [
                ...EnrollmentAttribution::rules(),
                'datesent' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'nextdate' => [$presence, ...($creating ? [] : ['nullable']), 'date_format:Y-m-d'],
                'amount' => [$presence, ...($creating ? [] : ['nullable']), ...BillingRules::money()],
                'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
                'invoicereference' => [$presence, ...($creating ? [] : ['nullable']), 'string', 'max:255'],
                'batchid' => ['sometimes', 'nullable', 'string', 'max:128'],
                'urgent_message' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'loas' => [
                ...EnrollmentAttribution::rules(),
                'loa_start' => [$presence, ...($creating ? [] : ['nullable']), 'date_format:Y-m-d'],
                'loa_end' => ['sometimes', 'nullable', 'date_format:Y-m-d',
                    ...($creating ? ['after_or_equal:loa_start'] : [])],
            ],
        };
    }

    private function checkInvoiceLink(array $data, Student $student): void
    {
        if (isset($data['invoice_id']) && ! Invoice::where('student_id', $student->id)->whereKey($data['invoice_id'])->exists()) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice must belong to this learner.']);
        }
    }

    private function invoiceAttribution(array $data, Student $student): array
    {
        if (! empty($data['invoice_id'])) {
            $invoice = Invoice::where('student_id', $student->id)->find($data['invoice_id']);
            if ($invoice && ((string) $invoice->enrollment_id !== (string) ($data['enrollment_id'] ?? null)
                || (string) $invoice->stage_enrollment_id !== (string) ($data['stage_enrollment_id'] ?? null))) {
                throw ValidationException::withMessages(['billing_period' => 'The transaction and invoice must use the same enrollment/stage.']);
            }
        }

        return $data;
    }

    private function saved(Request $request, string $message)
    {
        return $request->expectsJson() ? response()->json(compact('message')) : back()->with('status', $message);
    }
}
