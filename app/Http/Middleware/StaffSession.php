<?php

namespace App\Http\Middleware;

use App\Billing\EditLocks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StaffSession
{
    public function handle(Request $request, Closure $next)
    {
        $activeSession = null;
        $response = DB::transaction(function () use ($request, $next, &$activeSession) {
            $id = EditLocks::sessionId($request);
            DB::table('staff_sessions')->insertOrIgnore(['id' => $id, 'user_id' => $request->user()->id,
                'last_activity' => now()->timestamp, 'revoked' => false]);
            $session = DB::table('staff_sessions')->where('id', $id)->lockForUpdate()->first();
            if ($session->revoked || $session->user_id !== $request->user()->id
                || $session->last_activity <= now()->timestamp - EditLocks::minutes() * 60) {
                EditLocks::revoke($request);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Your session expired due to inactivity. Your editing locks have been released. Log in again.'], 401)
                    : redirect()->route('login')->with('status', 'Your session expired due to inactivity. Your editing locks have been released.');
            }
            if (! $request->routeIs('session.status', 'locks.status', 'locks.release',
                'workspace.status', 'workspace.release', 'workspace.table-locks', 'forms.status', 'forms.release', 'forms.grades.status', 'forms.grades.release')) {
                DB::table('staff_sessions')->where('id', $id)->update(['last_activity' => now()->timestamp]);
                $activeSession = $id;
            }

            return $next($request);
        }, 3);
        if ($activeSession !== null) {
            // Renew after commit to avoid taking lease locks before student-row locks.
            DB::table('student_edit_locks')->where('session_id', $activeSession)->update(['last_activity' => now()->timestamp]);
            DB::table('form_edit_locks')->where('session_id', $activeSession)->update(['last_activity' => now()->timestamp]);
        }

        return $response;
    }
}
