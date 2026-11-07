<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderBy('username')->paginate(40)]);
    }

    public function create(): View
    {
        return view('users.form', ['staffUser' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        User::create($this->validated($request));

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        return view('users.form', ['staffUser' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        DB::transaction(function () use ($user, $data) {
            $admins = User::where('is_admin', true)->lockForUpdate()->get();
            if ($admins->contains('id', $user->id) && ! $data['is_admin'] && $admins->count() <= 1) {
                throw ValidationException::withMessages(['is_admin' => 'At least one admin must remain.']);
            }
            if ($user->is_admin !== $data['is_admin'] || $user->access_level !== $data['access_level']) {
                $sessions = DB::table('staff_sessions')->where('user_id', $user->id)->select('id');
                DB::table('student_edit_locks')->whereIn('session_id', $sessions)->delete();
                DB::table('form_edit_locks')->whereIn('session_id', $sessions)->delete();
            }
            $user->update($data);
        });

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own login.']);
        }
        DB::transaction(function () use ($user) {
            $admins = User::where('is_admin', true)->lockForUpdate()->get();
            if ($admins->contains('id', $user->id) && $admins->count() <= 1) {
                throw ValidationException::withMessages(['user' => 'At least one admin must remain.']);
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('staff_sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return redirect()->route('users.index')->with('status', 'User deleted.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = Validator::make($request->all(), [
            'username' => ['required', 'string', 'max:150', 'alpha_dash:ascii', Rule::unique('users')->ignore($user)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(12)],
            'is_admin' => ['sometimes', 'boolean'],
            'level' => ['sometimes', 'required', Rule::in(['admin', ...array_keys(User::LEVELS)])],
        ])->validate();
        $data['is_admin'] = isset($data['level']) ? $data['level'] === 'admin' : $request->boolean('is_admin');
        $data['access_level'] = isset($data['level']) && $data['level'] !== 'admin'
            ? $data['level'] : ($user?->access_level ?? 'staff');
        unset($data['level']);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
