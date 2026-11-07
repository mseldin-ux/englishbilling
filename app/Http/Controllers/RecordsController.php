<?php

namespace App\Http\Controllers;

use App\Billing\EditLocks;
use App\Billing\RecordsFields;
use App\Billing\RecordsSearch;
use App\Billing\RecordVersion;
use App\Billing\ReportCsv;
use App\Billing\StudentEdits;
use App\Billing\StudentFields;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordsController extends Controller
{
    private function query(Request $request)
    {
        $query = Student::query()->select(['id', ...array_keys(RecordsFields::fields())]);
        RecordsSearch::apply($query, RecordsSearch::filters($request));

        return $query->orderBy('id');
    }

    public function index(Request $request)
    {
        $students = $this->query($request)->paginate(40)->withQueryString();
        $locks = $this->locksFor($students->modelKeys())->keyBy('student_id');

        return view('records.index', ['students' => $students, 'locks' => $locks,
            'fields' => RecordsFields::fields(), 'accessStatuses' => $this->statuses(),
            'filters' => RecordsSearch::filters($request), 'searchFields' => RecordsSearch::fields()]);
    }

    public function tableLocks(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:40'],
            'ids.*' => ['required', 'integer', 'min:1']]);

        return response()->json(['locks' => $this->locksFor($data['ids'])])->header('Cache-Control', 'private, no-store');
    }

    private function locksFor(array $ids)
    {
        return DB::table('student_edit_locks as l')->join('staff_sessions as s', 's.id', '=', 'l.session_id')
            ->join('users as u', 'u.id', '=', 's.user_id')->whereIn('l.student_id', $ids)
            ->where('l.last_activity', '>', now()->timestamp - EditLocks::minutes() * 60)
            ->select('l.student_id', 'u.name', 'u.username')->get();
    }

    private function statuses()
    {
        return DB::table('student_access_statuses')->orderBy('id')->get();
    }

    public function create()
    {
        return view('records.form', ['student' => new Student, 'fields' => RecordsFields::fields(),
            'accessStatuses' => $this->statuses(), 'editLock' => null]);
    }

    private function data(Request $request, bool $editing): array
    {
        $allowed = [...array_keys(RecordsFields::fields()), '_token', '_method', '_edit_lock'];
        if ($editing) {
            $allowed[] = 'version';
        }
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown) {
            throw ValidationException::withMessages(['fields' => 'This form may only change the permitted student records fields.']);
        }

        return $request->validate([
            ...array_intersect_key(StudentFields::rules(), RecordsFields::fields()),
            ...($editing ? ['version' => ['required', 'string']] : []),
        ]);
    }

    public function store(Request $request)
    {
        $student = new Student;
        $student->forceFill($this->data($request, false));
        $student->balance = '0.00';
        $student->save();

        return redirect()->route('workspace.edit', $student)->with('status', 'Student created.');
    }

    public function edit(Request $request, Student $student)
    {
        $lock = EditLocks::claim($request, $student);

        return view('records.form', ['student' => $student->fresh(), 'fields' => RecordsFields::fields(),
            'accessStatuses' => $this->statuses(), 'editLock' => $lock]);
    }

    public function claim(Request $request, Student $student)
    {
        $lock = EditLocks::claim($request, $student);
        $student->refresh();

        return response()->json(['lock' => $lock, 'values' => RecordsFields::values($student),
            'version' => RecordVersion::of($student)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Student $student)
    {
        $data = $this->data($request, true);
        $version = $data['version'];
        unset($data['version']);
        $updated = StudentEdits::update($student, $data, $version);

        return $request->expectsJson()
            ? response()->json(['message' => 'Changes saved.', 'version' => RecordVersion::of($updated),
                'values' => RecordsFields::values($updated)])
            : back()->with('status', 'Changes saved.');
    }

    public function export(Request $request)
    {
        $query = $this->query($request);
        $ids = $request->validate(['ids' => ['sometimes', 'array'], 'ids.*' => ['integer', 'min:1']])['ids'] ?? null;
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $fields = RecordsFields::fields();

        return ReportCsv::download('student-records.tsv', function (callable $write) use ($query, $fields) {
            $write(['User ID', ...array_column($fields, 0)]);
            foreach ($query->cursor() as $student) {
                $write([$student->id, ...array_values(RecordsFields::values($student))]);
            }
        }, "\t");
    }
}
