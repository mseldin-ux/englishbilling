<?php

namespace App\Billing;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EditLocks
{
    public static function sessionId(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    public static function minutes(): int
    {
        return (int) DB::table('staff_settings')->where('id', 1)->value('idle_minutes');
    }

    public static function clearExpired(): int
    {
        return DB::table('student_edit_locks')
            ->where('last_activity', '<=', now()->timestamp - self::minutes() * 60)->delete();
    }

    public static function current(int $student): ?object
    {
        $lock = DB::table('student_edit_locks')->where('student_id', $student)->lockForUpdate()->first();
        if ($lock === null || $lock->last_activity <= now()->timestamp - self::minutes() * 60) {
            return null;
        }
        $user = DB::table('staff_sessions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $lock->session_id)->select('u.name', 'u.username')->first();
        $lock->name = $user->name;
        $lock->username = $user->username;

        return $lock;
    }

    public static function claim(Request $request, Student $student): array
    {
        return DB::transaction(function () use ($request, $student) {
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            DB::table('student_edit_locks')->where('student_id', $student->id)->lockForUpdate()->first();
            $lock = self::current($student->id);
            $session = self::sessionId($request);
            if ($lock !== null && $lock->session_id !== $session) {
                return self::state($request, $student->id, '');
            }
            $token = bin2hex(random_bytes(32));
            DB::table('student_edit_locks')->updateOrInsert(['student_id' => $student->id], [
                'session_id' => $session, 'token' => $token, 'acquired_at' => now()->timestamp, 'last_activity' => now()->timestamp,
            ]);

            return self::state($request, $student->id, $token);
        });
    }

    public static function state(Request $request, int $student, string $token): array
    {
        $lock = self::current($student);
        $owned = $lock !== null && $lock->session_id === self::sessionId($request)
            && $token !== '' && hash_equals($lock->token, $token);

        return ['owned' => $owned, 'token' => $owned ? $token : '', 'editor' => $lock ? "{$lock->name} ({$lock->username})" : null,
            'expires_at' => $lock ? ($lock->last_activity + self::minutes() * 60) : null,
            'idle_minutes' => self::minutes()];
    }

    public static function requireOwner(Request $request, Student $student): void
    {
        Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
        $lock = DB::table('student_edit_locks')->where('student_id', $student->id)->lockForUpdate()->first();
        $token = (string) ($request->header('X-Student-Lock') ?: $request->input('_edit_lock', ''));
        if ($lock === null || $lock->session_id !== self::sessionId($request) || ! hash_equals($lock->token, $token)) {
            $state = self::state($request, $student->id, $token);
            throw new HttpException(423, $state['editor']
                ? "This student's records are locked by {$state['editor']}. No changes were saved. Reload to obtain an editing lock."
                : 'Your editing lock was released or expired. No changes were saved. Reload to obtain an editing lock.');
        }
    }

    public static function release(Request $request, Student $student, string $token): void
    {
        DB::transaction(function () use ($request, $student, $token) {
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            DB::table('student_edit_locks')->where('student_id', $student->id)
                ->where('session_id', self::sessionId($request))->where('token', $token)->delete();
        });
    }

    public static function revoke(Request $request): void
    {
        $id = self::sessionId($request);
        DB::table('staff_sessions')->where('id', $id)->update(['revoked' => true]);
        DB::table('student_edit_locks')->where('session_id', $id)->delete();
        DB::table('form_edit_locks')->where('session_id', $id)->delete();
    }
}
