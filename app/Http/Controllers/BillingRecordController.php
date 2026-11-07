<?php

namespace App\Http\Controllers;

use App\Billing\BillingDocument;
use App\Billing\BillingRules;
use App\Billing\Ledger;
use App\Billing\PdfRenderer;
use App\Billing\RecordVersion;
use App\Models\Invoice;
use App\Models\LeaveOfAbsence;
use App\Models\Student;
use App\Models\StudentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        DB::transaction(function () use ($student, $model, $kind, $data, $request) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
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
        });

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
                'transactiondate' => [$presence, ...($creating ? [] : ['nullable']), 'date_format:Y-m-d'],
                'processeddate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'transactiontype' => [$presence, ...($creating ? [] : ['nullable']), 'string', 'max:255'],
                'transactionamount' => [$presence, ...($creating ? [] : ['nullable']), ...BillingRules::money()],
                'transactionnotes' => ['sometimes', 'nullable', 'string', 'max:65535'],
                'invoice_id' => ['sometimes', 'nullable', 'integer'],
            ],
            'invoices' => [
                'datesent' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'nextdate' => [$presence, ...($creating ? [] : ['nullable']), 'date_format:Y-m-d'],
                'amount' => [$presence, ...($creating ? [] : ['nullable']), ...BillingRules::money()],
                'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
                'invoicereference' => [$presence, ...($creating ? [] : ['nullable']), 'string', 'max:255'],
                'batchid' => ['sometimes', 'nullable', 'string', 'max:128'],
                'urgent_message' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'loas' => [
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

    private function saved(Request $request, string $message)
    {
        return $request->expectsJson() ? response()->json(compact('message')) : back()->with('status', $message);
    }
}
