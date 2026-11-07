<?php

namespace App\Http\Controllers;

use App\Billing\BillingRules;
use App\Billing\Ledger;
use App\Billing\Money;
use App\Billing\PaymentSchedule;
use App\Billing\RecordVersion;
use App\Models\AnticipatedPayment;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentPaymentPlan;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentPlanController extends Controller
{
    public function index()
    {
        return view('plans.index', ['plans' => PaymentPlan::orderBy('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->templateData($request);
        $plan = new PaymentPlan;
        $plan->forceFill($data)->save();

        return back()->with('status', 'Payment plan template created.');
    }

    public function update(Request $request, PaymentPlan $plan)
    {
        $data = $this->templateData($request);
        $version = $request->validate(['version' => ['required', 'string']])['version'];
        DB::transaction(function () use ($plan, $data, $version) {
            $locked = PaymentPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            RecordVersion::check($locked, $version);
            $locked->forceFill($data)->save();
        });

        return back()->with('status', 'Payment plan template saved.');
    }

    public function destroy(Request $request, PaymentPlan $plan)
    {
        $version = $request->validate(['version' => ['required', 'string']])['version'];
        DB::transaction(function () use ($plan, $version) {
            $locked = PaymentPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            RecordVersion::check($locked, $version);
            $locked->delete();
        });

        return back()->with('status', 'Template deleted. Existing student schedules are unchanged.');
    }

    public function show(Student $student)
    {
        [$plan, $payments, $version] = $this->snapshot($student);
        $summary = Ledger::summary($student->transactions()->get(['transactiontype', 'transactionamount']));

        return view('students.schedule', ['student' => $student, 'plan' => $plan,
            'payments' => $payments, 'version' => $version, 'fees' => $summary['fees'],
            'templates' => PaymentPlan::orderBy('id')->get()]);
    }

    public function generate(Request $request, Student $student)
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:paymentplans,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'exclude_app_fee' => ['sometimes', 'boolean'],
            'app_fee' => ['required', ...BillingRules::money()],
        ]);
        $summary = Ledger::summary($student->transactions()->get(['transactiontype', 'transactionamount']));
        $total = BigDecimal::of($summary['fees']);
        if ($request->boolean('exclude_app_fee')) {
            if (BigDecimal::of($data['app_fee'])->isNegative()) {
                throw ValidationException::withMessages(['app_fee' => 'Application fee must not be negative.']);
            }
            $total = $total->minus($data['app_fee']);
        }
        $template = PaymentPlan::findOrFail($data['template_id']);

        return response()->json(['payments' => PaymentSchedule::generate((string) $total, $data['start_date'], $template->monthslist ?? ''),
            'description' => $template->paymentplandesc, 'message' => 'Plan generated. Review and save to persist it.']);
    }

    public function save(Request $request, Student $student)
    {
        $data = $request->validate([
            'version' => ['required', 'string'],
            'paymentplandesc' => ['present', 'nullable', 'string', 'max:65535'],
            'payments' => ['present', 'array', 'max:120'],
            'payments.*.id' => ['sometimes', 'nullable', 'integer'],
            'payments.*.paymentduedate' => ['required', 'date_format:Y-m-d'],
            'payments.*.paymentamount' => ['required', ...BillingRules::money()],
            'payments.*.paymentnotes' => ['present', 'nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($student, $data) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            [$plan, $existing, $version] = $this->snapshot($locked);
            if (! hash_equals($version, $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The payment plan changed. Reload it before saving.']);
            }
            if (! $plan) {
                $plan = new StudentPaymentPlan;
                $plan->student_id = $locked->id;
            }
            $plan->paymentplandesc = $data['paymentplandesc'];
            $plan->save();
            $retained = [];
            $replacement = [];
            foreach ($data['payments'] as $payment) {
                $id = $payment['id'] ?? null;
                if ($id && (! $existing->contains('id', $id) || in_array($id, $retained))) {
                    throw ValidationException::withMessages(['payments' => 'A payment row is duplicated or does not belong to this plan.']);
                }
                $original = $id ? $existing->firstWhere('id', $id)->getRawOriginal() : [];
                unset($original['id']);
                $row = new AnticipatedPayment;
                $row->setRawAttributes($original);
                $row->student_id = $locked->id;
                $row->studentpaymentplanid_id = $plan->id;
                if (substr($original['paymentduedate'] ?? '', 0, 10) !== $payment['paymentduedate']) {
                    $row->paymentduedate = $payment['paymentduedate'];
                }
                $row->paymentamount = Money::cents($payment['paymentamount']);
                $row->paymentnotes = $payment['paymentnotes'];
                $replacement[] = $row;
                if ($id) {
                    $retained[] = $id;
                }
            }
            $plan->payments()->delete();
            foreach ($replacement as $row) {
                $row->save();
            }
        });

        return response()->json(['message' => 'Payment plan saved.']);
    }

    private function templateData(Request $request): array
    {
        $data = $request->validate([
            'paymentplan' => ['required', 'string', 'max:255'],
            'paymentplandesc' => ['nullable', 'string', 'max:65535'],
            'monthslist' => ['required', 'string', 'max:255'],
        ]);
        PaymentSchedule::months($data['monthslist']);

        return $data;
    }

    private function snapshot(Student $student): array
    {
        $plans = $student->paymentPlans()->get();
        if ($plans->count() > 1) {
            throw ValidationException::withMessages(['plan' => 'Multiple plans exist for this learner. Review them before editing.']);
        }
        $plan = $plans->first();
        $payments = $plan ? $plan->payments()->orderBy('id')->get() : collect();
        $version = hash('sha256', serialize([$plan?->getRawOriginal(), $payments->map(fn ($p) => $p->getRawOriginal())->all()]));

        return [$plan, $payments, $version];
    }
}
