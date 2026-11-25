<?php

namespace App\Http\Controllers;

use App\Billing\BillingRules;
use App\Billing\EnrollmentAccounting;
use App\Billing\EnrollmentAttribution;
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

    public function show(Request $request, Student $student)
    {
        $data = $request->validate(EnrollmentAttribution::rules());
        $choices = $this->stageChoices($student);
        $period = $data['billing_period'] ?? (count($choices) === 1 ? array_key_first($choices) : null);
        $scope = $choices && $period ? $this->stageScope($student, $period) : null;
        if ($choices && ! $scope) {
            return view('students.schedule', ['student' => $student, 'stageChoices' => $choices,
                'selectedPeriod' => null]);
        }
        [$plan, $payments, $version] = $this->snapshot($student, $scope);
        $summary = $this->scheduleSummary($student, $scope);
        $enrollment = $student->currentEnrollment;
        $startDate = $scope
            ? $enrollment->stages->firstWhere('id', $scope['stage_enrollment_id'])?->started_on
            : ($enrollment?->mode === 'staged'
            ? $enrollment->stages->last()?->started_on
            : ($enrollment?->started_on ?? $student->start_date));

        return view('students.schedule', ['student' => $student, 'plan' => $plan,
            'payments' => $payments, 'version' => $version, 'fees' => $summary['fees'],
            'appFee' => $summary['app_fee'], 'startDate' => $startDate,
            'stageChoices' => $choices, 'selectedPeriod' => $period,
            'templates' => PaymentPlan::orderBy('id')->get()]);
    }

    public function generate(Request $request, Student $student)
    {
        $data = $request->validate([
            ...EnrollmentAttribution::rules(),
            'template_id' => ['required', 'integer', 'exists:paymentplans,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'exclude_app_fee' => ['sometimes', 'boolean'],
            'app_fee' => ['required', ...BillingRules::money()],
        ]);
        $scope = $this->requiredStageScope($student, $data['billing_period'] ?? null);
        $summary = $this->scheduleSummary($student, $scope);
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
            ...EnrollmentAttribution::rules(),
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
            $scope = $this->requiredStageScope($locked, $data['billing_period'] ?? null);
            [$plan, $existing, $version] = $this->snapshot($locked, $scope);
            if (! hash_equals($version, $data['version'])) {
                throw ValidationException::withMessages(['version' => 'The payment plan changed. Reload it before saving.']);
            }
            if (! $plan) {
                $plan = new StudentPaymentPlan;
                $plan->student_id = $locked->id;
            }
            $attribution = $scope ?? EnrollmentAttribution::data($locked, array_intersect_key($data, ['billing_period' => true]), $plan->exists ? $plan : null);
            $plan->forceFill($attribution);
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
                $row->enrollment_id = $plan->enrollment_id;
                $row->stage_enrollment_id = $plan->stage_enrollment_id;
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

    private function snapshot(Student $student, ?array $scope = null): array
    {
        $query = $student->paymentPlans();
        if ($student->current_enrollment_id) {
            foreach ($scope ?? EnrollmentAttribution::current($student) as $field => $value) {
                $query->where($field, $value);
            }
        }
        $plans = $query->get();
        if ($plans->count() > 1) {
            throw ValidationException::withMessages(['plan' => 'Multiple plans exist for this learner. Review them before editing.']);
        }
        $plan = $plans->first();
        $payments = $plan ? $plan->payments()->orderBy('id')->get() : collect();
        $version = hash('sha256', serialize([$plan?->getRawOriginal(), $payments->map(fn ($p) => $p->getRawOriginal())->all(), $scope]));

        return [$plan, $payments, $version];
    }

    private function scheduleSummary(Student $student, ?array $scope = null): array
    {
        $query = $student->transactions();
        if ($student->current_enrollment_id && ! $scope) {
            foreach ($scope ?? EnrollmentAttribution::current($student) as $field => $value) {
                $query->where($field, $value);
            }
        } elseif ($scope) {
            $query->where('enrollment_id', $scope['enrollment_id']);
        }

        $transactions = $query->get(['id', 'student_id', 'enrollment_id', 'stage_enrollment_id',
            'transactiontype', 'transactionamount', 'recognition']);
        if ($scope) {
            $transactions = collect(EnrollmentAccounting::expandAllocations($transactions))
                ->where('stage_enrollment_id', $scope['stage_enrollment_id']);
        }

        return [...Ledger::summary($transactions), 'app_fee' => $student->current_enrollment_id
            ? Ledger::summary($transactions->where('recognition', 'application'))['fees']
            : '100.00'];
    }

    private function stageChoices(Student $student): array
    {
        $enrollment = $student->currentEnrollment;
        if (! config('billing.concurrent_stages') || $enrollment?->mode !== 'staged') {
            return [];
        }
        $options = EnrollmentAttribution::options($student);
        $choices = [];
        foreach ($enrollment->stages as $stage) {
            $key = $enrollment->id.':'.$stage->id;
            $choices[$key] = $options[$key];
        }

        return $choices;
    }

    private function stageScope(Student $student, string $period): array
    {
        if (! array_key_exists($period, $this->stageChoices($student))) {
            throw ValidationException::withMessages(['billing_period' => 'Choose a stage in the current program enrollment.']);
        }

        return EnrollmentAttribution::data($student, ['billing_period' => $period]);
    }

    private function requiredStageScope(Student $student, ?string $period): ?array
    {
        $choices = $this->stageChoices($student);
        if (! $choices) {
            return null;
        }
        $period ??= count($choices) === 1 ? array_key_first($choices) : null;
        if (! $period) {
            throw ValidationException::withMessages(['billing_period' => 'Choose the stage for this payment plan.']);
        }

        return $this->stageScope($student, $period);
    }
}
