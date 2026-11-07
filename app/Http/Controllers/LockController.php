<?php

namespace App\Http\Controllers;

use App\Billing\EditLocks;
use App\Forms\FormLocks;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LockController extends Controller
{
    public function status(Request $request, Student $student)
    {
        return response()->json(EditLocks::state($request, $student->id, (string) $request->header('X-Student-Lock', '')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function release(Request $request, Student $student)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        EditLocks::release($request, $student, $data['token']);

        return response()->json(['message' => 'Editing lock released.']);
    }

    public function sessionStatus(Request $request)
    {
        $last = DB::table('staff_sessions')->where('id', EditLocks::sessionId($request))->value('last_activity');

        return response()->json(['expires_at' => $last + EditLocks::minutes() * 60,
            'idle_minutes' => EditLocks::minutes()])->header('Cache-Control', 'private, no-store');
    }

    public function activity(Request $request)
    {
        return $this->sessionStatus($request);
    }

    public function index()
    {
        EditLocks::clearExpired();
        FormLocks::clearExpired();
        $locks = DB::table('student_edit_locks as l')->join('staff_sessions as s', 's.id', '=', 'l.session_id')
            ->join('users as u', 'u.id', '=', 's.user_id')->join('students as st', 'st.id', '=', 'l.student_id')
            ->select('l.student_id', 'l.token', 'l.acquired_at', 'l.last_activity', 'u.name', 'u.username',
                'st.first_name', 'st.last_name')->orderBy('l.student_id')->get();

        $base = fn () => DB::table('form_edit_locks as l')->join('staff_sessions as s', 's.id', '=', 'l.session_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->select('l.form_kind', 'l.form_id', 'l.token', 'l.acquired_at', 'l.last_activity', 'u.name', 'u.username', 'st.first_name', 'st.last_name');
        $formLocks = $base()->where('l.form_kind', 'formsdata')
            ->join('formsdata as f', 'f.id', '=', 'l.form_id')->join('students as st', 'st.id', '=', 'f.student_id')
            ->unionAll($base()->where('l.form_kind', 'finalgrades')
                ->join('finalgrades as f', 'f.id', '=', 'l.form_id')->join('students as st', 'st.id', '=', 'f.studentid'))
            ->orderBy('form_kind')->orderBy('form_id')->get();

        return view('locks.index', ['locks' => $locks, 'formLocks' => $formLocks, 'minutes' => EditLocks::minutes()]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['idle_minutes' => ['required', 'integer', 'between:1,1440']]);
        DB::table('staff_settings')->where('id', 1)->update($data);
        EditLocks::clearExpired();
        FormLocks::clearExpired();
        Log::notice('Staff inactivity timeout changed.', ['admin_id' => $request->user()->id, ...$data]);

        return back()->with('status', 'Inactivity timeout updated. Applies to existing sessions too.');
    }

    public function forceRelease(Request $request)
    {
        $data = $request->validate(['confirm' => ['accepted'],
            'student_id' => ['nullable', 'integer', 'exists:students,id', 'prohibits:form_kind'],
            'form_kind' => ['nullable', 'required_with:form_id', Rule::in(array_keys(FormLocks::KINDS))],
            'form_id' => ['nullable', 'required_with:form_kind', 'integer'],
            'token' => ['required_with:student_id,form_kind', 'nullable', 'string', 'size:64']]);
        if (isset($data['student_id'])) {
            $count = DB::table('student_edit_locks')->where('student_id', $data['student_id'])->where('token', $data['token'])->delete();
        } elseif (isset($data['form_kind'])) {
            $count = DB::table('form_edit_locks')->where('form_kind', $data['form_kind'])->where('form_id', $data['form_id'])
                ->where('token', $data['token'])->delete();
        } else {
            $count = DB::table('student_edit_locks')->delete() + DB::table('form_edit_locks')->delete();
        }
        Log::notice('Editing locks forcibly released.', ['admin_id' => $request->user()->id, 'student_id' => $data['student_id'] ?? null,
            'form_kind' => $data['form_kind'] ?? null, 'form_id' => $data['form_id'] ?? null, 'count' => $count]);

        return back()->with('status', "Released {$count} editing lock(s). Editors must reload before saving.");
    }
}
