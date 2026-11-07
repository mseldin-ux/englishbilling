<?php

namespace App\Http\Middleware;

use App\Billing\EditLocks;
use App\Models\Student;
use Closure;
use Illuminate\Http\Request;

class RequireStudentLock
{
    public function handle(Request $request, Closure $next)
    {
        $student = $request->route('student');
        Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
        // Browse-page archive actions are atomic and do not have an editing page.
        if (! $request->routeIs('students.archive') || EditLocks::current($student->id) !== null) {
            EditLocks::requireOwner($request, $student);
        }

        return $next($request);
    }
}
