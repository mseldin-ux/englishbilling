<?php

namespace App\Http\Controllers;

use App\Billing\BillingRules;
use App\Billing\EditLocks;
use App\Billing\Ledger;
use App\Billing\RecordVersion;
use App\Billing\StudentEdits;
use App\Billing\StudentFields;
use App\Billing\StudentSearch;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        if ($request->boolean('reset')) {
            $request->session()->forget('billing_search');
        } elseif ($request->hasAny(array_diff(array_keys(StudentSearch::rules()), ['page']))) {
            $filters = $request->validate(StudentSearch::rules());
            unset($filters['page']);
            $request->session()->put('billing_search', $filters);
        }
        $filters = $request->session()->get('billing_search', []);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $students = StudentSearch::query($filters)->orderBy('id')->paginate(40);
        $editingLocks = DB::table('student_edit_locks as l')->join('staff_sessions as s', 's.id', '=', 'l.session_id')
            ->join('users as u', 'u.id', '=', 's.user_id')->whereIn('l.student_id', $students->modelKeys())
            ->where('s.revoked', false)->where('s.last_activity', '>', now()->timestamp - EditLocks::minutes() * 60)
            ->select('l.student_id', 'u.name', 'u.username')->get()->keyBy('student_id');

        return view('students.index', compact('students', 'filters', 'editingLocks'));
    }

    public function create()
    {
        return view('students.form', ['student' => new Student, 'groups' => StudentFields::GROUPS,
            'accessStatuses' => DB::table('student_access_statuses')->orderBy('id')->get(),
            'programs' => Program::orderBy('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(StudentFields::rules());
        $student = DB::transaction(function () use ($data) {
            $student = new Student;
            $student->forceFill($data);
            $student->balance = '0.00';
            $student->save();

            return $student;
        });

        return redirect()->route('students.show', $student)->with('status', 'Learner created.');
    }

    public function show(Request $request, Student $student)
    {
        $editLock = EditLocks::claim($request, $student);
        $filters = $request->session()->get('billing_search', []);
        $query = StudentSearch::query($filters);
        $previous = (clone $query)->where('id', '<', $student->id)->orderByDesc('id')->value('id')
            ?? (clone $query)->orderByDesc('id')->value('id');
        $next = (clone $query)->where('id', '>', $student->id)->orderBy('id')->value('id')
            ?? (clone $query)->orderBy('id')->value('id');
        $position = (clone $query)->where('id', '<', $student->id)->count();
        $transactions = $student->transactions()->orderBy('transactiondate')->orderBy('id')->get();
        $summary = Ledger::summary($transactions);
        $planned = $student->anticipatedPayments()->whereDate('paymentduedate', '>=', today())
            ->orderBy('paymentduedate')->first();
        $plannedInvoiceExists = $planned && $student->invoices()->whereDate('nextdate', $planned->paymentduedate)->exists();

        return view('students.show', ['student' => $student, 'groups' => StudentFields::GROUPS,
            'accessStatuses' => DB::table('student_access_statuses')->orderBy('id')->get(),
            'programs' => Program::orderBy('id')->get(), 'previous' => $previous, 'next' => $next,
            'listPage' => intdiv($position, 40) + 1, 'transactions' => $transactions,
            'summary' => $summary, 'types' => BillingRules::TYPES, 'planned' => $planned,
            'plannedInvoiceExists' => $plannedInvoiceExists, 'editLock' => $editLock]);
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate(['version' => ['required', 'string'], ...StudentFields::rules()]);
        $version = $data['version'];
        unset($data['version']);
        $updated = StudentEdits::update($student, $data, $version);

        return $request->expectsJson()
            ? response()->json(['message' => 'All changes saved.', 'version' => RecordVersion::of($updated)])
            : back()->with('status', 'All changes saved.');
    }

    public function archive(Request $request, Student $student)
    {
        $data = $request->validate(['archived' => ['required', 'boolean'], 'version' => ['required', 'string']]);
        DB::transaction(function () use ($student, $data) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            RecordVersion::check($locked, $data['version']);
            $locked->archived = $data['archived'];
            $locked->save();
        });

        return back()->with('status', $data['archived'] ? 'Learner archived.' : 'Learner unarchived.');
    }

    public function recalculate(Request $request)
    {
        $request->validate(['confirm' => ['accepted']]);
        $count = 0;
        DB::transaction(function () use (&$count) {
            Student::orderBy('id')->chunkById(250, function ($students) use (&$count) {
                foreach ($students as $student) {
                    $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
                    DB::table('student_edit_locks')->where('student_id', $student->id)->lockForUpdate()->first();
                    if ($lock = EditLocks::current($student->id)) {
                        throw new HttpException(423, "Balance recalculation is blocked: learner {$student->id} is being edited by {$lock->name} ({$lock->username}). Release editing locks first.");
                    }
                    Ledger::recalculate($locked);
                    $count++;
                }
            });
        });

        return back()->with('status', "Recalculated balances for {$count} learners.");
    }
}
