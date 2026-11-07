<?php

namespace App\Forms;

use App\Billing\EditLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-form editing leases. A form is identified by its kind (the table holding the form)
 * and its row id. Leases share the student-lock inactivity timeout and admin recovery.
 */
final class FormLocks
{
    public const KINDS = ['formsdata' => 'Graduation Initiation', 'finalgrades' => 'Final Grades'];

    public static function clearExpired(): int
    {
        return DB::table('form_edit_locks')
            ->where('last_activity', '<=', now()->timestamp - EditLocks::minutes() * 60)->delete();
    }

    public static function current(string $kind, int $id): ?object
    {
        $lock = DB::table('form_edit_locks')->where('form_kind', self::kind($kind))->where('form_id', $id)->lockForUpdate()->first();
        if ($lock === null || $lock->last_activity <= now()->timestamp - EditLocks::minutes() * 60) {
            return null;
        }
        $user = DB::table('staff_sessions as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', $lock->session_id)->select('u.name', 'u.username')->first();
        $lock->name = $user->name;
        $lock->username = $user->username;

        return $lock;
    }

    public static function claim(Request $request, string $kind, int $id): array
    {
        return DB::transaction(function () use ($request, $kind, $id) {
            DB::table(self::kind($kind))->where('id', $id)->lockForUpdate()->firstOrFail();
            $lock = self::current($kind, $id);
            $session = EditLocks::sessionId($request);
            if ($lock !== null && $lock->session_id !== $session) {
                return self::state($request, $kind, $id, '');
            }
            $token = bin2hex(random_bytes(32));
            DB::table('form_edit_locks')->updateOrInsert(['form_kind' => $kind, 'form_id' => $id], [
                'session_id' => $session, 'token' => $token, 'acquired_at' => now()->timestamp, 'last_activity' => now()->timestamp,
            ]);

            return self::state($request, $kind, $id, $token);
        });
    }

    public static function state(Request $request, string $kind, int $id, string $token): array
    {
        $lock = self::current($kind, $id);
        $owned = $lock !== null && $lock->session_id === EditLocks::sessionId($request)
            && $token !== '' && hash_equals($lock->token, $token);

        return ['owned' => $owned, 'token' => $owned ? $token : '', 'editor' => $lock ? "{$lock->name} ({$lock->username})" : null,
            'expires_at' => $lock ? ($lock->last_activity + EditLocks::minutes() * 60) : null,
            'idle_minutes' => EditLocks::minutes()];
    }

    /**
     * Call inside a transaction after locking the form row.
     */
    public static function requireOwner(Request $request, string $kind, int $id): void
    {
        $lock = DB::table('form_edit_locks')->where('form_kind', self::kind($kind))->where('form_id', $id)->lockForUpdate()->first();
        $token = (string) ($request->header('X-Student-Lock') ?: $request->input('_edit_lock', ''));
        $expired = $lock !== null && $lock->last_activity <= now()->timestamp - EditLocks::minutes() * 60;
        if ($lock === null || $expired || $lock->session_id !== EditLocks::sessionId($request) || ! hash_equals($lock->token, $token)) {
            $state = self::state($request, $kind, $id, $token);
            throw new HttpException(423, $state['editor']
                ? "This form is locked by {$state['editor']}. No changes were saved."
                : 'Your editing lock on this form was released or expired. No changes were saved. Reload to obtain an editing lock.');
        }
    }

    public static function release(Request $request, string $kind, int $id, string $token): void
    {
        DB::transaction(function () use ($request, $kind, $id, $token) {
            DB::table(self::kind($kind))->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('form_edit_locks')->where('form_kind', $kind)->where('form_id', $id)
                ->where('session_id', EditLocks::sessionId($request))->where('token', $token)->delete();
        });
    }

    /**
     * Removes the lease of a deleted form. Call in the deleting transaction.
     */
    public static function forget(string $kind, int $id): void
    {
        DB::table('form_edit_locks')->where('form_kind', self::kind($kind))->where('form_id', $id)->delete();
    }

    /**
     * Active lock holders for a page of forms, keyed by form id.
     */
    public static function holders(string $kind, array $ids)
    {
        return DB::table('form_edit_locks as l')->join('staff_sessions as s', 's.id', '=', 'l.session_id')
            ->join('users as u', 'u.id', '=', 's.user_id')->where('l.form_kind', self::kind($kind))->whereIn('l.form_id', $ids)
            ->where('l.last_activity', '>', now()->timestamp - EditLocks::minutes() * 60)
            ->select('l.form_id', 'u.name', 'u.username')->get()->keyBy('form_id');
    }

    private static function kind(string $kind): string
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw new InvalidArgumentException("Unknown form kind {$kind}.");
        }

        return $kind;
    }
}
