<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireUserAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $allowed = $user->canAccessBilling() || $request->routeIs('home', 'logout', 'session.status', 'session.activity', 'forms.*')
            || ($user->canUseRecordsWorkspace() && $request->routeIs('workspace.*', 'templates.*', 'workflows.*'));
        if (! $allowed) {
            abort(403, $user->canUseRecordsWorkspace()
                ? 'Your user level only allows access to the student records workspace, forms and workflows.'
                : 'Your user level only allows access to forms.');
        }
        if ($request->routeIs('reports.*') && ! $user->canViewReports()) {
            abort(403, 'Your user level does not allow access to reports.');
        }
        if ($request->routeIs('plans.*') && ! $user->canManagePaymentPlans()) {
            abort(403, 'Your user level does not allow access to payment-plan templates.');
        }

        return $next($request);
    }
}
