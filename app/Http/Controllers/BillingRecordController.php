<?php

namespace App\Http\Controllers;

use App\Billing\BillingDocument;
use App\Billing\BillingRules;
use App\Billing\EnrollmentAttribution;
use App\Billing\InvoiceEmail;
use App\Billing\Ledger;
use App\Billing\PdfRenderer;
use App\Billing\RecordVersion;
use App\Billing\StageBillingAllocations;
use App\Mail\TemplatedMail;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\Student;
use App\Models\StudentTransaction;
use App\Models\User;
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
        $allocations = collect();
        if (config('billing.concurrent_stages') && $kind !== 'loas') {
            $invoice = $kind === 'invoices';
            $key = $invoice ? 'invoice_id' : 'transaction_id';
            $allocations = DB::table($invoice ? 'invoice_stage_allocations' : 'transaction_stage_allocations')
                ->whereIn($key, $records->modelKeys())->get()->groupBy($key);
        }

        return view('students.records', ['student' => $student, 'kind' => $kind,
            'records' => $records, 'summary' => $kind === 'transactions' ? Ledger::summary($records) : [],
            'types' => BillingRules::TYPES, 'allocations' => $allocations]);
    }

    public function store(Request $request, Student $student, string $kind)
    {
        $model = $this->model($kind);
        $data = $request->validate($this->rules($kind, true));
        $created = DB::transaction(function () use ($student, $model, $kind, $data, $request) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $allocations = $data['allocations'] ?? null;
            unset($data['allocations']);
            if ($kind === 'loas' && config('billing.concurrent_stages') && $locked->currentEnrollment?->mode === 'staged') {
                if (! empty($data['billing_period'])) {
                    throw ValidationException::withMessages(['billing_period' => 'A leave applies to the whole learner.']);
                }
                unset($data['billing_period']);
                $data['enrollment_id'] = $locked->current_enrollment_id;
                $data['stage_enrollment_id'] = null;
            } else {
                $data = EnrollmentAttribution::data($locked, $data);
            }
            if ($kind !== 'loas') {
                $data = $this->invoiceAttribution($data, $locked);
            }
            $this->checkInvoiceLink($data, $locked);
            $record = new $model;
            $record->forceFill($data);
            $record->student_id = $locked->id;
            if ($record instanceof LeaveOfAbsence) {
                $this->validateLeave($locked, $data['loa_start'], $data['loa_end'] ?? null);
            }
            if ($record instanceof StudentTransaction) {
                $this->protectHistoricalTransaction($request->user(), $record);
            }
            if ($kind === 'invoices') {
                $record->datesent ??= now();
                $record->user_sent = $request->user()->username;
            }
            $record->save();
            if ($kind !== 'loas') {
                $this->saveAllocations($locked, $record, $allocations);
                $this->validateStageLinks($record);
            }
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
        $updated = DB::transaction(function () use ($student, $model, $kind, $record, $data, $version, $request) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $row = $model::where('student_id', $locked->id)->whereKey($record)->lockForUpdate()->firstOrFail();
            RecordVersion::check($row, $version);
            $hasAllocations = array_key_exists('allocations', $data);
            $allocations = $data['allocations'] ?? null;
            unset($data['allocations']);
            if ($row instanceof StudentTransaction) {
                $this->protectHistoricalTransaction($request->user(), $row);
                $proposed = clone $row;
                $proposed->forceFill(array_intersect_key($data, array_flip(['processeddate', 'transactiondate'])));
                $this->protectHistoricalTransaction($request->user(), $proposed);
            }
            if ($kind === 'loas' && config('billing.concurrent_stages') && $row->enrollment_id
                && ! $row->stage_enrollment_id && ! empty($data['billing_period'])
                && $locked->enrollments()->whereKey($row->enrollment_id)->where('mode', 'staged')->exists()) {
                throw ValidationException::withMessages(['billing_period' => 'A leave applies to the whole learner.']);
            }
            $checkLinks = $hasAllocations || array_key_exists('billing_period', $data)
                || array_key_exists('invoice_id', $data);
            $data = EnrollmentAttribution::data($locked, $data, $row);
            if ($kind !== 'loas') {
                $merged = [...$row->getAttributes(), ...$data];
                $this->invoiceAttribution($merged, $locked);
                if (! config('billing.concurrent_stages') && $kind === 'invoices' && $row->transactions()->get(['enrollment_id', 'stage_enrollment_id'])
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
                if (array_key_exists('loa_start', $data) || array_key_exists('loa_end', $data)) {
                    $this->validateLeave($locked, $start, $end, $row->id);
                }
            }
            $row->forceFill($data)->save();
            if ($kind !== 'loas') {
                $this->saveAllocations($locked, $row, $allocations);
                if ($checkLinks) {
                    $this->validateStageLinks($row);
                }
            }
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
        DB::transaction(function () use ($student, $model, $kind, $record, $data, $request) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $row = $model::where('student_id', $locked->id)->whereKey($record)->lockForUpdate()->firstOrFail();
            RecordVersion::check($row, $data['version']);
            if ($row instanceof StudentTransaction) {
                $this->protectHistoricalTransaction($request->user(), $row);
            }
            if ($kind === 'invoices') {
                if (! $request->user()->canManageHistoricalTransactions()) {
                    foreach (StudentTransaction::where('invoice_id', $row->id)->lockForUpdate()->get() as $transaction) {
                        $this->protectHistoricalTransaction($request->user(), $transaction);
                    }
                }
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
        $allocationStageIds = array_column($data['allocations'] ?? [], 'stage_enrollment_id');
        unset($data['allocations']);
        if (! empty($data['invoice_id'])) {
            $record = Invoice::where('student_id', $student->id)->select(Invoice::METADATA_COLUMNS)->findOrFail($data['invoice_id']);
        } else {
            unset($data['invoice_id']);
            $record = new Invoice;
            $record->forceFill(EnrollmentAttribution::data($student, $data));
            $record->datesent ??= now();
        }
        $message = EmailTemplates::render(InvoiceEmail::KEY, InvoiceEmail::variables($student, $record, $request->user(), $allocationStageIds));

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

    private function protectHistoricalTransaction(User $user, StudentTransaction $transaction): void
    {
        abort_if(! $user->canManageHistoricalTransactions() && $transaction->isHistorical(), 403,
            'Only Admin or Staff can create, edit, or delete transactions over 30 days old or with no usable date.');
    }

    private function rules(string $kind, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $allocations = config('billing.concurrent_stages') ? [
            'allocations' => ['sometimes', 'array', 'max:100'],
            'allocations.*.stage_enrollment_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', ...BillingRules::money()],
        ] : ['allocations' => ['prohibited']];

        return match ($kind) {
            'transactions' => [
                ...$allocations,
                ...EnrollmentAttribution::rules(true),
                'transactiondate' => [$presence, ...($creating ? [] : ['nullable']), 'date_format:Y-m-d'],
                'processeddate' => [$presence, 'required', 'date_format:Y-m-d'],
                'transactiontype' => [$presence, ...($creating ? [] : ['nullable']), 'string', 'max:255'],
                'transactionamount' => [$presence, ...($creating ? [] : ['nullable']), ...BillingRules::money()],
                'transactionnotes' => ['sometimes', 'nullable', 'string', 'max:65535'],
                'invoice_id' => ['sometimes', 'nullable', 'integer'],
            ],
            'invoices' => [
                ...$allocations,
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
                    ...($creating ? ['after:loa_start'] : [])],
            ],
        };
    }

    private function validateLeave(Student $student, ?string $start, ?string $end, ?int $except = null): void
    {
        if ($start === null || $start === '') {
            throw ValidationException::withMessages(['loa_start' => 'A leave start date is required.']);
        }
        if (! $student->start_date) {
            throw ValidationException::withMessages(['loa_start' => 'Enter the learner’s start date before recording a leave.']);
        }
        if ($start <= $student->start_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['loa_start' => 'LOA start must be after the learner’s start date.']);
        }
        if ($end !== null && $end <= $start) {
            throw ValidationException::withMessages(['loa_end' => 'LOA end must be after its start.']);
        }
        $overlap = LeaveOfAbsence::where('student_id', $student->id)
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->where(fn ($q) => $q->whereNull('loa_end')->orWhere('loa_end', '>', $start.' 00:00:00'))
            ->where(fn ($q) => $q->whereNull('loa_start')->orWhereNull('loa_end')->orWhereColumn('loa_start', '<', 'loa_end'))
            ->when($end !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('loa_start')->orWhere('loa_start', '<', $end.' 00:00:00')))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['loa_start' => 'This leave overlaps another leave or one with an unknown start. Correct the existing leave first.']);
        }
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
                || (! config('billing.concurrent_stages')
                    && (string) $invoice->stage_enrollment_id !== (string) ($data['stage_enrollment_id'] ?? null)))) {
                throw ValidationException::withMessages(['billing_period' => 'The transaction and invoice must use the same enrollment/stage.']);
            }
        }

        return $data;
    }

    private function saveAllocations(Student $student, Invoice|StudentTransaction $record, ?array $allocations): void
    {
        if (! config('billing.concurrent_stages')) {
            return;
        }
        $invoice = $record instanceof Invoice;
        $table = $invoice ? 'invoice_stage_allocations' : 'transaction_stage_allocations';
        $key = $invoice ? 'invoice_id' : 'transaction_id';
        if ($allocations === null) {
            $allocations = DB::table($table)->where($key, $record->id)
                ->orderBy('stage_enrollment_id')->get(['stage_enrollment_id', 'amount'])
                ->map(fn ($row) => ['stage_enrollment_id' => $row->stage_enrollment_id, 'amount' => (string) $row->amount])->all();
        }
        if ($allocations === []) {
            DB::table($table)->where($key, $record->id)->delete();

            return;
        }
        $stageIds = array_column($allocations, 'stage_enrollment_id');
        if (! $record->enrollment_id || DB::table('student_stage_enrollments')
            ->where('enrollment_id', $record->enrollment_id)->whereIn('id', $stageIds)->count() !== count(array_unique($stageIds))) {
            throw ValidationException::withMessages(['allocations' => 'All allocated stages must belong to this billing record’s program enrollment.']);
        }
        StageBillingAllocations::replace($student, $record, $allocations);
    }

    private function validateStageLinks(Invoice|StudentTransaction $record): void
    {
        if (! config('billing.concurrent_stages')) {
            return;
        }
        if ($record instanceof Invoice) {
            foreach ($record->transactions()->get() as $transaction) {
                $this->validateStagePair($record, $transaction);
            }
        } elseif ($record->invoice_id) {
            $invoice = Invoice::where('student_id', $record->student_id)->findOrFail($record->invoice_id);
            $this->validateStagePair($invoice, $record);
        }
    }

    private function validateStagePair(Invoice $invoice, StudentTransaction $transaction): void
    {
        $invoiceStages = DB::table('invoice_stage_allocations')->where('invoice_id', $invoice->id)
            ->pluck('stage_enrollment_id')->map(fn ($id) => (string) $id)->all();
        $transactionStages = DB::table('transaction_stage_allocations')->where('transaction_id', $transaction->id)
            ->pluck('stage_enrollment_id')->map(fn ($id) => (string) $id)->all();
        $invoiceStages = $invoiceStages ?: [(string) $invoice->stage_enrollment_id];
        $transactionStages = $transactionStages ?: [(string) $transaction->stage_enrollment_id];
        if ((string) $invoice->enrollment_id !== (string) $transaction->enrollment_id
            || array_diff($transactionStages, $invoiceStages)) {
            throw ValidationException::withMessages(['allocations' => 'A linked transaction must use stages included in its invoice.']);
        }
    }

    private function saved(Request $request, string $message)
    {
        return $request->expectsJson() ? response()->json(compact('message')) : back()->with('status', $message);
    }
}
